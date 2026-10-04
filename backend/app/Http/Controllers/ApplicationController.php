<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Member;
use App\Services\ApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $applications)
    {
    }

    /** 居民提交申请（或确认合并） */
    public function store(Request $request)
    {
        $data = $request->validate([
            'alert_batch_id' => ['required', 'integer', Rule::exists('alert_batches', 'id')],
            'address' => ['required', 'string', 'max:128'],
            'heating_method' => ['required', 'string', 'max:32'],
            'payee_name' => ['required', 'string', 'max:32'],
            'payee_account' => ['required', 'string', 'max:64'],
            'payee_bank' => ['nullable', 'string', 'max:32'],
            'members' => ['required', 'array', 'min:1'],
            'members.*.name' => ['required', 'string', 'max:32'],
            'members.*.id_number' => ['required', 'string', 'max:32'],
            'members.*.phone' => ['nullable', 'string', 'max:20'],
            'merge_application_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->applications->apply($request->user(), $data);

        return response()->json($result, 201);
    }

    /** 居民查看「我的申请」（仅本人提交或作为成员挂接的申请） */
    public function myIndex(Request $request)
    {
        $user = $request->user();
        $memberIds = Member::where('user_id', $user->id)->pluck('id');

        $apps = Application::query()
            ->where('applicant_user_id', $user->id)
            ->orWhereHas('members', fn ($q) => $q->whereIn('members.id', $memberIds))
            ->with(['batch', 'household', 'payment', 'members'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Application $a) => $this->present($a));

        return response()->json(['data' => $apps]);
    }

    public function show(Application $application, Request $request)
    {
        $this->authorizeView($application, $request->user());
        $application->load(['batch', 'household', 'documents', 'appeals', 'reviews.reviewer',
            'payment', 'members', 'events', 'payeeChangeRequests', 'mergedInto']);

        return response()->json(['data' => $this->presentDetail($application)]);
    }

    /** 补交/更新材料（沿原申请） */
    public function submitDocument(Application $application, Request $request)
    {
        $this->authorizeOwner($application, $request->user());
        $data = $request->validate([
            'doc_type' => ['required', 'string'],
            'file_ref' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $doc = $this->applications->submitDocument(
            $application,
            $request->user(),
            $data['doc_type'],
            $data['file_ref'] ?? null,
            isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return response()->json(['data' => [
            'doc_type' => $doc->doc_type,
            'status' => $doc->status,
            'expires_at' => $doc->expires_at?->toDateString(),
        ]]);
    }

    private function authorizeOwner(Application $application, \App\Models\User $user): void
    {
        abort_unless($application->applicant_user_id === $user->id, 403, '只能操作本人的申请。');
    }

    private function authorizeView(Application $application, \App\Models\User $user): void
    {
        if ($user->isCommunity() || $user->isStreet()) {
            return;
        }
        // 申请人本人，或作为同户成员挂接在该申请上的居民，可只读查看
        $isMember = $application->members()->where('members.user_id', $user->id)->exists();
        abort_unless($application->applicant_user_id === $user->id || $isMember,
            403, '只能查看本人（或本户）的申请。');
    }

    private function present(Application $a): array
    {
        return [
            'id' => $a->id,
            'batch_name' => $a->batch->name,
            'batch_id' => $a->alert_batch_id,
            'is_late' => $a->is_late,
            'address' => $a->household->address,
            'heating_method' => $a->payload['heating_method'] ?? null,
            'status' => $a->status,
            'status_text' => Application::STATUSES[$a->status] ?? $a->status,
            'frozen' => $a->frozen,
            'payee_verified' => $a->payee_verified,
            'merged_into_id' => $a->merged_into_id,
            'members' => $a->members->pluck('name')->all(),
            'payment' => $a->payment ? [
                'status' => $a->payment->status,
                'amount' => $a->payment->amount,
                'transfer_ref' => $a->payment->transfer_ref,
                'paid_at' => $a->payment->paid_at?->toDateTimeString(),
            ] : null,
        ];
    }

    private function presentDetail(Application $a): array
    {
        $eligibility = $this->applications->eligibility($a);

        return $this->present($a) + [
            'rule_snapshot' => $a->rule_snapshot,
            'payee' => [
                'name' => $a->payee_name,
                'account' => $a->payee_account,
                'bank' => $a->payee_bank,
                'verified' => $a->payee_verified,
            ],
            'eligibility' => $eligibility,
            'documents' => $a->documents->map(fn ($d) => [
                'doc_type' => $d->doc_type,
                'label' => $d->label,
                'status' => $d->isExpired(now()) ? 'expired' : $d->status,
                'file_ref' => $d->file_ref,
                'expires_at' => $d->expires_at?->toDateString(),
            ])->all(),
            'reviews' => $a->reviews->map(fn ($r) => [
                'stage' => $r->stage,
                'reviewer' => $r->reviewer->name,
                'decision' => $r->decision,
                'note' => $r->note,
                'at' => $r->created_at->toDateTimeString(),
            ])->all(),
            'appeals' => $a->appeals->map(fn ($p) => [
                'reason' => $p->reason,
                'status' => $p->status,
                'reply' => $p->reply,
                'at' => $p->created_at->toDateTimeString(),
            ])->all(),
            'payee_change_requests' => $a->payeeChangeRequests->map(fn ($c) => [
                'payee_name' => $c->payee_name,
                'status' => $c->status,
                'at' => $c->created_at->toDateTimeString(),
            ])->all(),
            'timeline' => $a->events->map(fn ($e) => [
                'type' => $e->type,
                'message' => $e->message,
                'at' => $e->created_at->toDateTimeString(),
            ])->all(),
        ];
    }
}
