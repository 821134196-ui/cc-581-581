<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Disbursement;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 本地模拟支付服务：
 * - initiate(): 生成支付单号并落 outgoing 事件；
 * - receiveReceipt(): 接收支付回执。以 receipt_no 为幂等键，
 *   同一回执重复送达只记录一次到账，绝不重复发放补贴。
 */
class MockPaymentService
{
    /** 发起支付，返回支付单号 */
    public function initiate(Disbursement $disbursement): string
    {
        return DB::transaction(function () use ($disbursement) {
            $locked = Disbursement::whereKey($disbursement->id)->lockForUpdate()->first();

            if (in_array($locked->status, [Disbursement::STATUS_PAID], true)) {
                // 已到账的清单不允许再次发起
                throw new DomainException('already_paid', '该户补贴已到账，不能重复发起支付。', [
                    'disbursement_id' => $locked->id,
                ]);
            }

            if (! $locked->payment_no) {
                $locked->payment_no = 'PAY-' . strtoupper(Str::random(16));
            }
            $locked->status = Disbursement::STATUS_PAYING;
            $locked->save();

            PaymentEvent::create([
                'direction' => PaymentEvent::DIR_OUTGOING,
                'payment_no' => $locked->payment_no,
                'disbursement_id' => $locked->id,
                'payload' => [
                    'amount' => $locked->amount,
                    'payee_name' => $locked->payee_name,
                    'payee_account' => $locked->payee_account,
                ],
                'result' => PaymentEvent::RESULT_ACCEPTED,
            ]);

            return $locked->payment_no;
        });
    }

    /**
     * 接收支付回执（可能重复送达）。
     *
     * @return array{duplicate:bool, disbursement:Disbursement}
     */
    public function receiveReceipt(string $paymentNo, string $receiptNo, ?string $paidAt = null): array
    {
        return DB::transaction(function () use ($paymentNo, $receiptNo, $paidAt) {
            // 幂等第一道防线：receipt_no 全局唯一
            $existing = Disbursement::where('receipt_no', $receiptNo)->lockForUpdate()->first();
            if ($existing) {
                $existing->increment('receipt_count');
                PaymentEvent::create([
                    'direction' => PaymentEvent::DIR_INCOMING,
                    'payment_no' => $paymentNo,
                    'receipt_no' => $receiptNo,
                    'disbursement_id' => $existing->id,
                    'payload' => ['note' => '重复回执送达，已忽略，未重复记账'],
                    'result' => PaymentEvent::RESULT_DUPLICATE,
                ]);

                return ['duplicate' => true, 'disbursement' => $existing];
            }

            $disbursement = Disbursement::where('payment_no', $paymentNo)->lockForUpdate()->first();
            if (! $disbursement) {
                throw new DomainException('payment_not_found', '支付单号不存在，回执无法匹配。', [
                    'payment_no' => $paymentNo,
                ]);
            }

            if ($disbursement->status === Disbursement::STATUS_PAID) {
                // 第二道防线：不同 receipt_no 但同一支付单重复回执，同样不重复入账
                $disbursement->increment('receipt_count');
                PaymentEvent::create([
                    'direction' => PaymentEvent::DIR_INCOMING,
                    'payment_no' => $paymentNo,
                    'receipt_no' => $receiptNo,
                    'disbursement_id' => $disbursement->id,
                    'payload' => ['note' => '该支付单已到账，重复回执忽略'],
                    'result' => PaymentEvent::RESULT_DUPLICATE,
                ]);

                return ['duplicate' => true, 'disbursement' => $disbursement];
            }

            if ($disbursement->status === Disbursement::STATUS_FROZEN) {
                throw new DomainException('disbursement_frozen', '发放已冻结，回执暂不入账，待解冻后处理。', [
                    'payment_no' => $paymentNo,
                ]);
            }

            $disbursement->update([
                'status' => Disbursement::STATUS_PAID,
                'receipt_no' => $receiptNo,
                'paid_at' => $paidAt ?: now(),
                'receipt_count' => $disbursement->receipt_count + 1,
            ]);

            PaymentEvent::create([
                'direction' => PaymentEvent::DIR_INCOMING,
                'payment_no' => $paymentNo,
                'receipt_no' => $receiptNo,
                'disbursement_id' => $disbursement->id,
                'payload' => ['paid_at' => $disbursement->paid_at?->toDateTimeString()],
                'result' => PaymentEvent::RESULT_RECORDED,
            ]);

            return ['duplicate' => false, 'disbursement' => $disbursement->fresh()];
        });
    }
}
