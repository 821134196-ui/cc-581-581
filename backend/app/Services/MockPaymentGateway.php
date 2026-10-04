<?php

namespace App\Services;

use App\Models\Payment;

/**
 * 本地支付服务模拟：
 *  - payout()  发起转账，生成 transfer_ref（银行端受理，未到账）
 *  - 到账结果由银行异步回调 receipt 接口（PaymentService::receiveReceipt）
 *  - 回执可能重复送达，由 PaymentService 幂等处理
 */
class MockPaymentGateway
{
    public function payout(Payment $payment): string
    {
        $ref = 'TXN-'.str_pad((string) $payment->id, 8, '0', STR_PAD_LEFT)
             .'-'.substr(hash('sha256', $payment->id.'|'.microtime()), 0, 8);
        $payment->update(['transfer_ref' => $ref]);

        return $ref;
    }

    /** 模拟银行生成成功回执号 */
    public function makeReceiptNo(Payment $payment): string
    {
        return 'RCPT-'.$payment->transfer_ref.'-OK';
    }
}
