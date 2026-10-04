<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Disbursement;
use App\Models\User;
use App\Services\DisbursementService;
use Illuminate\Http\Request;

class DisbursementController extends Controller
{
    public function __construct(private readonly DisbursementService $disbursements) {}

    public function index(Request $request)
    {
        $q = Disbursement::with(['batch', 'application', 'household'])->orderByDesc('id');
        if ($batchId = $request->query('batch_id')) {
            $q->where('batch_id', $batchId);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json([
            'disbursements' => $q->get()->map(fn (Disbursement $d) => [
                'id' => $d->id,
                'batch_code' => $d->batch?->code,
                'apply_no' => $d->application?->apply_no,
                'address' => $d->household?->address_raw,
                'amount' => $d->amount,
                'payee_name' => $d->payee_name,
                'payee_account' => $d->payee_account,
                'status' => $d->status,
                'status_label' => $d->statusLabel(),
                'payment_no' => $d->payment_no,
                'receipt_no' => $d->receipt_no,
                'receipt_count' => $d->receipt_count,
                'paid_at' => $d->paid_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function issue(Request $request)
    {
        if ($request->user()->role !== User::ROLE_STREET) {
            return response()->json(['message' => '仅街道可发起发放', 'error_code' => 'forbidden_role'], 403);
        }

        $data = $request->validate(['batch_id' => ['required', 'integer', 'exists:batches,id']]);
        $nos = $this->disbursements->issuePayments($data['batch_id']);

        return response()->json([
            'message' => '已向模拟支付服务发起 ' . count($nos) . ' 笔支付。',
            'payment_nos' => $nos,
        ]);
    }
}
