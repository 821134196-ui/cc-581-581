<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Services\DisbursementService;
use Illuminate\Http\Request;

class PaymentSimController extends Controller
{
    public function __construct(private readonly DisbursementService $disbursements) {}

    /**
     * 模拟支付服务回执回调。
     * 同一 receipt_no 重复送达只记一次到账（返回 duplicate=true 提示调用方）。
     */
    public function receipt(Request $request)
    {
        $data = $request->validate([
            'payment_no' => ['required', 'string', 'max:60'],
            'receipt_no' => ['required', 'string', 'max:60'],
            'paid_at' => ['nullable', 'date'],
        ]);

        try {
            $res = $this->disbursements->handleReceipt(
                $data['payment_no'], $data['receipt_no'], $data['paid_at'] ?? null,
            );
        } catch (\App\Exceptions\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => $e->errorCode,
                'context' => $e->context,
            ], 422);
        }

        $d = $res['disbursement'];

        return response()->json([
            'message' => $res['duplicate'] ? '重复回执：系统只记录一次到账，未重复发放。' : '回执已入账，补贴到账。',
            'duplicate' => $res['duplicate'],
            'disbursement' => [
                'id' => $d->id,
                'status' => $d->status,
                'receipt_no' => $d->receipt_no,
                'receipt_count' => $d->receipt_count,
                'paid_at' => $d->paid_at?->toDateTimeString(),
            ],
        ]);
    }

    /** 本地模拟短信收件箱（演示/测试用） */
    public function smsInbox(Request $request)
    {
        $q = SmsMessage::query()->orderByDesc('id');
        if ($phone = $request->query('phone')) {
            $q->where('phone', $phone);
        }
        if ($applicationId = $request->query('application_id')) {
            $q->where('application_id', $applicationId);
        }

        return response()->json(['messages' => $q->limit(200)->get()]);
    }
}
