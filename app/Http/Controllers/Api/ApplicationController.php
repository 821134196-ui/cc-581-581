<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Document;
use App\Models\EligibilityRule;
use App\Services\ApplicationService;
use App\Services\EligibilityChecker;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly ApplicationService $applications,
        private readonly EligibilityChecker $checker,
    ) {}

    public function batches()
    {
        return response()->json([
            'batches' => Batch::with('rule')->orderByDesc('id')->get()->map(fn (Batch $b) => $this->batchPayload($b)),
        ]);
    }

    public function batchDetail(Batch $batch)
    {
        $batch->load('rule');
        return response()->json(['batch' => $this->batchPayload($batch)]);
    }

    public function myApplications(Request $request)
    {
        $user = $request->user();
        $apps = Application::with(['batch', 'disbursement', 'documents'])
            ->where('merged', false)
            ->where(function ($q) use ($user) {
                $q->where('applicant_id', $user->id)
                    ->orWhere('household_id', $user->household_id);
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['applications' => $apps->map(fn (Application $a) => $this->applicationPayload($a))]);
    }

    public function show(Request $request, Application $application)
    {
        $this->assertHousehold($request, $application);

        if ($application->merged) {
            $application->load('mergedInto');
        }

        $application->load(['batch.rule', 'documents', 'reviews.reviewer', 'followUps', 'disbursement', 'statusLogs']);

        // 居民查看时即时给出“缺哪些材料 / 是否过期”的提示
        $rawReport = $application->merged ? null : $this->checker->check($application, Carbon::now());
        $report = $rawReport === null ? null : [
            'eligible' => $rawReport['eligible'],
            'missing_docs' => $rawReport['missingDocs'],
            'expired_docs' => $rawReport['expiredDocs'],
            'failed_items' => $rawReport['failedItems'],
            'checked' => $rawReport['checked'],
        ];

        return response()->json([
            'application' => $this->applicationPayload($application, true),
            'eligibility' => $report,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:batches,id'],
            'address' => ['required', 'string', 'max:200'],
            'household_head_name' => ['nullable', 'string', 'max:50'],
            'household_head_idcard' => ['nullable', 'string', 'max:18'],
            'heating_method' => ['required', 'string', 'max:30'],
            'residency_met' => ['required', 'boolean'],
            'payee' => ['required', 'array:name,account,bank'],
            'payee.name' => ['required', 'string', 'max:50'],
            'payee.account' => ['required', 'string', 'max:40'],
            'payee.bank' => ['nullable', 'string', 'max:50'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.doc_type' => ['required', 'string', 'max:30'],
            'documents.*.issued_on' => ['required', 'date'],
            'documents.*.file_path' => ['nullable', 'string', 'max:200'],
            'members' => ['nullable', 'array'],
        ]);

        $result = $this->applications->submit($request->user(), $data['batch_id'], $data);

        return response()->json([
            'message' => $result['merged'] ? '该户已存在本批次申请，已自动合并。' : '申请已提交。',
            'merged' => $result['merged'],
            'application' => $this->applicationPayload($result['application']->load(['batch', 'documents'])),
        ], $result['merged'] ? 200 : 201);
    }

    public function supplement(Request $request, Application $application)
    {
        $this->assertHousehold($request, $application);
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.doc_type' => ['required', 'string', 'max:30'],
            'documents.*.issued_on' => ['required', 'date'],
            'documents.*.file_path' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $followUp = $this->applications->supplement(
            $application, $request->user(), $data['documents'], $data['note'] ?? null,
        );

        return response()->json([
            'message' => '补件已提交，沿原申请跟进。',
            'follow_up_id' => $followUp->id,
        ]);
    }

    public function appeal(Request $request, Application $application)
    {
        $this->assertHousehold($request, $application);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $followUp = $this->applications->appeal($application, $request->user(), $data['reason']);

        return response()->json(['message' => '申诉已提交，沿原申请处理。', 'follow_up_id' => $followUp->id]);
    }

    public function changePayee(Request $request, Application $application)
    {
        $this->assertHousehold($request, $application);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'account' => ['required', 'string', 'max:40'],
            'bank' => ['nullable', 'string', 'max:50'],
        ]);

        $app = $this->applications->changePayee($application, $request->user(), $data);

        return response()->json([
            'message' => $app->status === Application::STATUS_FROZEN
                ? '收款人已变更，申请已冻结，等待街道重新核验。'
                : '收款人信息已更新。',
            'application' => $this->applicationPayload($app->fresh('batch')),
        ]);
    }

    public function timeline(Request $request, Application $application)
    {
        $this->assertHousehold($request, $application);
        $logs = $application->statusLogs()->orderBy('id')->get(['id', 'from_status', 'to_status', 'event', 'message', 'created_at']);

        return response()->json(['timeline' => $logs]);
    }

    private function assertHousehold(Request $request, Application $application): void
    {
        $user = $request->user();
        if ($application->applicant_id === $user->id
            || ($user->household_id && $user->household_id === $application->household_id)) {
            return;
        }
        abort(403, '只能查看本户的申请与到账记录。');
    }

    private function batchPayload(Batch $b): array
    {
        return [
            'id' => $b->id,
            'code' => $b->code,
            'title' => $b->title,
            'warning_start' => $b->warning_start->toDateString(),
            'warning_end' => $b->warning_end->toDateString(),
            'apply_deadline' => $b->apply_deadline->toDateString(),
            'late_apply_deadline' => $b->late_apply_deadline->toDateString(),
            'rules_frozen' => $b->rules_frozen,
            'status' => $b->status,
            'rule' => $b->rule ? [
                'version' => $b->rule->version,
                'low_temp_threshold' => $b->rule->low_temp_threshold,
                'allowed_heating_methods' => $b->rule->allowed_heating_methods,
                'doc_valid_days' => $b->rule->doc_valid_days,
                'required_documents' => $b->rule->required_documents,
                'required_document_names' => collect($b->rule->required_documents)
                    ->map(fn ($c) => Document::TYPE_LABELS[$c] ?? $c),
                'subsidy_amount' => $b->rule->subsidy_amount,
                'subsidy_unit' => $b->rule->subsidy_unit,
                'residency_requirement' => $b->rule->residency_requirement,
            ] : null,
        ];
    }

    private function applicationPayload(Application $a, bool $detail = false): array
    {
        $base = [
            'id' => $a->id,
            'apply_no' => $a->apply_no,
            'batch_code' => $a->batch?->code,
            'status' => $a->status,
            'status_label' => $a->statusLabel(),
            'heating_method' => $a->heating_method,
            'address' => $a->address,
            'is_late' => $a->is_late,
            'merged' => $a->merged,
            'merged_into_id' => $a->merged_into_id,
            'frozen' => $a->status === Application::STATUS_FROZEN,
            'freeze_reason' => $a->freeze_reason,
            'reverify_required' => $a->reverify_required,
            'subsidy_amount' => $a->subsidyAmount(),
            'payee' => $a->payee(),
            'created_at' => $a->created_at?->toDateTimeString(),
        ];

        if (! $detail) {
            $base['disbursement'] = $a->disbursement ? [
                'status' => $a->disbursement->status,
                'status_label' => $a->disbursement->statusLabel(),
                'amount' => $a->disbursement->amount,
                'paid_at' => $a->disbursement->paid_at?->toDateTimeString(),
            ] : null;
            return $base;
        }

        $base += [
            'rule_snapshot' => $a->rule_snapshot,
            'payload_snapshot' => $a->payload_snapshot,
            'documents' => $a->documents->map(fn (Document $d) => [
                'id' => $d->id,
                'doc_type' => $d->doc_type,
                'doc_name' => $d->doc_name,
                'issued_on' => $d->issued_on->toDateString(),
                'expire_on' => $d->expire_on->toDateString(),
                'status' => $d->status,
                'is_expired_now' => $d->isExpiredOn(Carbon::now()),
            ]),
            'reviews' => $a->reviews->map(fn ($r) => [
                'level' => $r->level,
                'result' => $r->result,
                'comment' => $r->comment,
                'reviewer' => $r->reviewer?->name,
                'checked_items' => $r->checked_items,
                'created_at' => $r->created_at?->toDateTimeString(),
            ]),
            'follow_ups' => $a->followUps->map(fn ($f) => [
                'id' => $f->id,
                'type' => $f->type,
                'reason' => $f->reason,
                'status' => $f->status,
                'handle_comment' => $f->handle_comment,
                'created_at' => $f->created_at?->toDateTimeString(),
            ]),
            'disbursement' => $a->disbursement ? [
                'id' => $a->disbursement->id,
                'status' => $a->disbursement->status,
                'status_label' => $a->disbursement->statusLabel(),
                'amount' => $a->disbursement->amount,
                'payment_no' => $a->disbursement->payment_no,
                'receipt_no' => $a->disbursement->receipt_no,
                'paid_at' => $a->disbursement->paid_at?->toDateTimeString(),
            ] : null,
            'timeline' => $a->statusLogs->sortBy('id')->map(fn ($l) => [
                'event' => $l->event,
                'message' => $l->message,
                'created_at' => $l->created_at?->toDateTimeString(),
            ])->values(),
        ];

        return $base;
    }
}
