<?php

namespace App\Services;

use App\Models\AlertBatch;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly MockPaymentGateway $gateway,
        private readonly SmsService $sms,
    ) {
    }

    /**
     * 街道复核通过后写入发放清单。每户每批一笔（application 唯一约束兜底）。
     */
    public function createRosterEntry(Application $application): Payment
    {
        return Payment::firstOrCreate(
            ['application_id' => $application->id],
            [
                'alert_batch_id' => $application->alert_batch_id,
                'amount' => $application->batch->subsidy_amount,
                'status' => 'pending',
            ],
        );
    }

    /**
     * 批次发放清单。
     *
     * @return \Illuminate\Support\Collection<int,Payment>
     */
    public function roster(AlertBatch $batch)
    {
        return Payment::query()
            ->where('alert_batch_id', $batch->id)
            ->with(['application.household', 'application.members', 'application.applicant'])
            ->orderBy('id')
            ->get();
    }

    /**
     * 街道按清单发起转账。冻结 / 收款人未核验 / 已到账的不发。
     */
    public function payout(Payment $payment, User $operator): Payment
    {
        abort_unless($operator->isStreet(), 403, '仅街道可以发起发放。');
        abort_if($payment->application->frozen, 423, '申请处于冻结状态，暂不发放。');
        abort_unless($payment->application->payee_verified, 422,
            '收款人信息未核验通过，不能发放。');
        abort_if($payment->status === 'paid', 422, '该笔补贴已到账，不能重复发放。');

        DB::transaction(function () use ($payment) {
            if (blank($payment->transfer_ref)) {
                $this->gateway->payout($payment);
                ApplicationEvent::log($payment->application, 'payout_initiated', null,
                    "补贴 {$payment->amount} 分已发起转账，等待银行回执。", [
                        'transfer_ref' => $payment->transfer_ref,
                    ]);
            }
        });

        return $payment->fresh();
    }

    /**
     * 接收银行支付回执（可能被重复送达）。
     * 关键幂等：receipt_no 全局唯一；重复送达只累加 redelivery_count，
     * 不重复记到账、不重复发短信。
     *
     * @param array<string,mixed> $payload
     * @return array{payment:Payment, duplicated:bool}
     */
    public function receiveReceipt(string $receiptNo, int $paymentId, string $outcome,
                                   array $payload = []): array
    {
        return DB::transaction(function () use ($receiptNo, $paymentId, $outcome, $payload) {
            $existing = PaymentReceipt::where('receipt_no', $receiptNo)->first();
            if ($existing !== null) {
                // 重复回执：只记一次到账。加计数用原子更新防并发双花。
                $affected = PaymentReceipt::where('id', $existing->id)
                    ->where('redelivery_count', $existing->redelivery_count)
                    ->update(['redelivery_count' => $existing->redelivery_count + 1,
                              'updated_at' => now()]);
                ApplicationEvent::log($existing->payment->application, 'receipt_duplicate', null,
                    "支付回执 {$receiptNo} 重复送达（第 ".($existing->redelivery_count + 1)
                    .' 次），已忽略，不重复发放。');
                abort_if($affected === 0, 409, '回执并发冲突，请重试。');

                return ['payment' => $existing->payment->fresh(), 'duplicated' => true];
            }

            $payment = Payment::with('application')->lockForUpdate()->findOrFail($paymentId);

            $receipt = PaymentReceipt::create([
                'payment_id' => $payment->id,
                'receipt_no' => $receiptNo,
                'outcome' => $outcome,
                'payload' => $payload,
            ]);

            if ($outcome === 'success' && $payment->status !== 'paid') {
                $payment->update(['status' => 'paid', 'paid_at' => now()]);
                $payment->application->update(['status' => 'paid']);
                ApplicationEvent::log($payment->application, 'paid', null,
                    "到账 {$payment->amount} 分（回执 {$receiptNo}）。", ['receipt_no' => $receiptNo]);
                $this->sms->send($payment->application->applicant,
                    "【社区取暖补贴】您的补贴 {$payment->amount} 分已到账，"
                    .'收款人：'.$payment->application->payee_name.'。', 'paid');
            } elseif ($outcome === 'success') {
                // 补贴已到账后又一张成功回执（回执号不同的重复送达）：
                // 留存回执备查，但不重复记到账、不重复发短信
                ApplicationEvent::log($payment->application, 'receipt_duplicate', null,
                    "又收到成功回执 {$receiptNo}，该笔补贴此前已到账，不重复发放。",
                    ['receipt_no' => $receiptNo]);
            } else {
                ApplicationEvent::log($payment->application, 'receipt_failed', null,
                    "收到回执 {$receiptNo}，结果：{$outcome}。");
            }

            return ['payment' => $payment->fresh(), 'duplicated' => false];
        });
    }
}
