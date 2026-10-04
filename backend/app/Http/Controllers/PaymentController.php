<?php

namespace App\Http\Controllers;

use App\Models\AlertBatch;
use App\Models\Payment;
use App\Models\User;
use App\Services\MockPaymentGateway;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly MockPaymentGateway $gateway,
    ) {
    }

    /** 本批发放清单（复核通过自动入清单） */
    public function roster(AlertBatch $batch)
    {
        $data = $this->payments->roster($batch)->map(fn (Payment $p) => [
            'payment_id' => $p->id,
            'application_id' => $p->application_id,
            'address' => $p->application->household->address,
            'applicant' => $p->application->applicant->name,
            'members' => $p->application->members->pluck('name')->all(),
            'payee' => $p->application->payee_name,
            'account' => $p->application->payee_account,
            'amount' => $p->amount,
            'status' => $p->status,
            'transfer_ref' => $p->transfer_ref,
            'paid_at' => $p->paid_at?->toDateTimeString(),
            'frozen' => $p->application->frozen,
            'payee_verified' => $p->application->payee_verified,
        ]);

        return response()->json(['data' => $data]);
    }

    /** 街道按清单发起转账 */
    public function payout(Payment $payment, Request $request)
    {
        $updated = $this->payments->payout($payment, $request->user());
        return response()->json(['data' => [
            'payment_id' => $updated->id,
            'status' => $updated->status,
            'transfer_ref' => $updated->transfer_ref,
        ]]);
    }

    /**
     * 银行支付回执回调（本地模拟，无鉴权，模拟银行服务器调用）。
     * 同一 receipt_no 重复送达时只记一次到账。
     */
    public function receipt(Request $request)
    {
        $data = $request->validate([
            'receipt_no' => ['required', 'string', 'max:80'],
            'transfer_ref' => ['required', 'string'],
            'outcome' => ['required', 'in:success,failed'],
        ]);

        $payment = Payment::where('transfer_ref', $data['transfer_ref'])->firstOrFail();
        $result = $this->payments->receiveReceipt(
            $data['receipt_no'], $payment->id, $data['outcome'], $request->except(['receipt_no', 'transfer_ref', 'outcome']),
        );

        return response()->json([
            'data' => [
                'payment_id' => $result['payment']->id,
                'status' => $result['payment']->status,
            ],
            'duplicated' => $result['duplicated'],
        ], $result['duplicated'] ? 200 : 201);
    }

    /**
     * 本地模拟银行：发起转账后一键送达成功回执（也可重复送达验证幂等）。
     */
    public function simulateBankReceipt(Payment $payment, Request $request)
    {
        abort_if(blank($payment->transfer_ref), 422, '该笔补贴尚未发起转账。');
        $receiptNo = $request->input('receipt_no') ?: $this->gateway->makeReceiptNo($payment);

        $result = $this->payments->receiveReceipt(
            $receiptNo, $payment->id, 'success', ['simulated' => true],
        );

        return response()->json([
            'data' => [
                'payment_id' => $result['payment']->id,
                'status' => $result['payment']->status,
                'receipt_no' => $receiptNo,
                'paid_at' => $result['payment']->paid_at?->toDateTimeString(),
            ],
            'duplicated' => $result['duplicated'],
        ], $result['duplicated'] ? 200 : 201);
    }
}
