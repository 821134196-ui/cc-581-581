<?php

namespace App\Console\Commands;

use App\Models\Disbursement;
use App\Services\DisbursementService;
use Illuminate\Console\Command;

/**
 * 手工模拟支付回执送达，可重复发送同一 receipt_no 来验证幂等：
 *   php artisan sim:receipt --payment-no=PAY-xxx --receipt-no=RCP-xxx
 */
class SimReceiptCommand extends Command
{
    protected $signature = 'sim:receipt
        {--payment-no= : 支付单号（缺省取最近一笔 paying）}
        {--receipt-no= : 回执号（缺省自动生成；重复测试请传同一个号两次）}';

    protected $description = '模拟支付公司送达回执（支持重复送达测试）';

    public function handle(DisbursementService $disbursements): int
    {
        $paymentNo = $this->option('payment-no');
        if (! $paymentNo) {
            $d = Disbursement::where('status', Disbursement::STATUS_PAYING)->latest('id')->first();
            if (! $d) {
                $this->error('没有 paying 状态的发放记录，请先在街道端发起发放。');
                return self::FAILURE;
            }
            $paymentNo = $d->payment_no;
        }

        $receiptNo = $this->option('receipt-no') ?: ('RCP-MANUAL-' . strtoupper(\Illuminate\Support\Str::random(10)));

        $res = $disbursements->handleReceipt($paymentNo, $receiptNo);
        $d = $res['disbursement'];

        if ($res['duplicate']) {
            $this->warn("重复回执（{$receiptNo}）已被识别：系统只记一次到账，未重复发放。");
        } else {
            $this->info("回执已送达：{$receiptNo}，状态 {$d->status}。");
        }
        $this->line("该回执累计送达次数（含重复）：{$d->receipt_count}");

        return self::SUCCESS;
    }
}
