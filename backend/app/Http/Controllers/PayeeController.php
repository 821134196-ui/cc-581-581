<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\PayeeChangeRequest;
use App\Services\ReviewService;
use Illuminate\Http\Request;

class PayeeController extends Controller
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** 居民申请变更收款人（冻结/核准后须街道重新核验） */
    public function change(Application $application, Request $request)
    {
        abort_unless($application->applicant_user_id === $request->user()->id,
            403, '只能变更本人申请的收款人。');

        $data = $request->validate([
            'payee_name' => ['required', 'string', 'max:32'],
            'payee_account' => ['required', 'string', 'max:64'],
            'payee_bank' => ['nullable', 'string', 'max:32'],
        ]);

        $change = $this->reviews->requestPayeeChange($application, $request->user(), $data);

        return response()->json(['data' => [
            'id' => $change->id,
            'status' => $change->status,
            'needs_verification' => $change->status === 'pending',
        ]], 201);
    }

    /** 街道核验收款人变更 */
    public function verify(PayeeChangeRequest $payeeChangeRequest, Request $request)
    {
        $data = $request->validate(['pass' => ['required', 'boolean'], 'note' => ['nullable', 'string']]);
        $change = $this->reviews->verifyPayeeChange(
            $payeeChangeRequest, $request->user(), (bool) $data['pass'], $data['note'] ?? null,
        );
        return response()->json(['data' => ['id' => $change->id, 'status' => $change->status]]);
    }

    /** 街道待核验的收款人变更列表 */
    public function pendingList(Request $request)
    {
        $items = PayeeChangeRequest::where('status', 'pending')
            ->with('application.household')
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'application_id' => $c->application_id,
                'address' => $c->application->household->address,
                'payee_name' => $c->payee_name,
                'payee_account' => $c->payee_account,
                'payee_bank' => $c->payee_bank,
                'at' => $c->created_at->toDateTimeString(),
            ]);
        return response()->json(['data' => $items]);
    }
}
