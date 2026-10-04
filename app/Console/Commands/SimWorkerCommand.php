<?php

namespace App\Console\Commands;

use App\Models\Disbursement;
use App\Services\DisbursementService;
use App\Services\MockSmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * 本地模拟短信/支付通道后台 worker：
 * - 周期发送队列中的短信；
 * - 为 paying 状态的发放自动生成支付回执（模拟支付公司回调）。
 * 重复回执请用 sim:receipt 命令手工模拟。
 */
class SimWorkerCommand extends Command
{
    protected $signature = 'sim:worker {--once : 只跑一轮}';

    protected $description = '启动本地模拟短信/支付通道 worker';

    public function handle(MockSmsService $sms, DisbursementService $disbursements): int
    {
        $this->info('[sim] 模拟短信/支付通道 worker 已启动（Ctrl+C 退出）');

        do {
            $sent = $sms->flushQueue();
            if ($sent > 0) {
                $this->line("[sim] 发送模拟短信 {$sent} 条");
            }

            // 模拟支付公司：支付发起后自动回执
            $autoReceipts = Disbursement::where('status', Disbursement::STATUS_PAYING)
                ->whereNull('receipt_no')
                ->limit(50)->get();

            foreach ($autoReceipts as $d) {
                $receiptNo = 'RCP-' . strtoupper(Str::random(12));
                try {
                    $res = $disbursements->handleReceipt($d->payment_no, $receiptNo);
                    if (! $res['duplicate']) {
                        $this->line("[sim] 支付回执到达：{$d->payment_no} → {$receiptNo}，补贴已到账");
                    }
                } catch (\Throwable $e) {
                    $this->warn("[sim] 回执处理失败：{$e->getMessage()}");
                }
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep(2);
        } while (true);
    }
}
