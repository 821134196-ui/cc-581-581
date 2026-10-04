<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Application;
use App\Models\Disbursement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DisbursementService
{
    public function __construct(
        private readonly MockPaymentService $payment,
        private readonly MockSmsService $sms,
    ) {}

    /** 复核通过自动入清单（也可街道手工调用） */
    public function addToList(Application $application): Disbursement
    {
        if ($application->status !== Application::STATUS_APPROVED) {
            throw new DomainException('not_approved', '只有复核通过的申请才能进入发放清单。', [
                'status' => $application->status,
            ]);
        }

        $payee = $application->payee();

        return Disbursement::firstOrCreate(
            ['application_id' => $application->id],
            [
                'batch_id' => $application->batch_id,
                'household_id' => $application->household_id,
                'amount' => $application->subsidyAmount(),
                'payee_name' => $payee['name'],
                'payee_account' => $payee['account'],
                'status' => Disbursement::STATUS_LISTED,
            ],
        );
    }

    /**
     * 对清单中待发放的记录向模拟支付服务发起支付。
     * 冻结/已支付记录不会被发起。
     *
     * @return array<int,string> 支付单号
     */
    public function issuePayments(int $batchId): array
    {
        $nos = [];
        Disbursement::where('batch_id', $batchId)
            ->whereIn('status', [Disbursement::STATUS_LISTED])
            ->with('application')
            ->get()
            ->each(function (Disbursement $d) use (&$nos) {
                // 发起前再次确认申请未冻结
                if ($d->application && $d->application->status === Application::STATUS_FROZEN) {
                    $d->update(['status' => Disbursement::STATUS_FROZEN]);
                    return;
                }
                $nos[] = $this->payment->initiate($d);
            });

        return $nos;
    }

    /**
     * 支付回执送达（可能重复）。回执以 receipt_no 幂等，重复只记一次到账。
     *
     * @return array{disbursement:Disbursement, duplicate:bool}
     */
    public function handleReceipt(string $paymentNo, string $receiptNo, ?string $paidAt = null): array
    {
        $result = $this->payment->receiveReceipt($paymentNo, $receiptNo, $paidAt);
        $disbursement = $result['disbursement'];
        $duplicate = $result['duplicate'];

        if (! $duplicate) {
            DB::transaction(function () use ($disbursement) {
                $application = Application::lockForUpdate()
                    ->whereKey($disbursement->application_id)->first();
                if ($application->status !== Application::STATUS_DISBURSED) {
                    $application->update(['status' => Application::STATUS_DISBURSED]);
                    Timeline::log(
                        $application,
                        'disbursed',
                        "补贴 {$disbursement->amount} 元已到账（{$disbursement->payee_name}，回执号 {$disbursement->receipt_no}）。",
                        null,
                        ['receipt_no' => $disbursement->receipt_no, 'amount' => $disbursement->amount],
                    );
                    if ($applicant = $application->applicant) {
                        $this->sms->send(
                            $applicant,
                            MockSmsService::TPL_PAID,
                            "【暖冬补贴】您的 {$application->batch->code} 批次补贴 {$disbursement->amount} 元已到账，"
                            . "回执号 {$disbursement->receipt_no}，请注意查收。",
                            $application,
                        );
                    }
                }
            });
        }

        return ['disbursement' => $disbursement->fresh(), 'duplicate' => $duplicate];
    }
}
