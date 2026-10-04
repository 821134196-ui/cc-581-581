<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\FollowUp;
use App\Services\ReviewService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function queue(Request $request)
    {
        $status = $request->query('status');
        $q = Application::with(['batch', 'household', 'applicant', 'documents'])
            ->where('merged', false);

        if ($status) {
            $q->where('status', $status);
        }
        // 社区只看待初审/待补件；街道只看待复核/冻结/申诉，街道也可看全部
        $user = $request->user();
        if ($user->role === 'community') {
            $q->whereIn('status', [
                Application::STATUS_SUBMITTED,
                Application::STATUS_SUPPLEMENT_REQUESTED,
                Application::STATUS_APPEALING,
            ]);
        } elseif ($user->role === 'street') {
            if (! $status) {
                $q->whereIn('status', [
                    Application::STATUS_STREET_PENDING,
                    Application::STATUS_FROZEN,
                    Application::STATUS_APPEALING,
                    Application::STATUS_APPROVED,
                ]);
            }
        }

        return response()->json([
            'applications' => $q->orderByDesc('id')->get()->map(fn (Application $a) => [
                'id' => $a->id,
                'apply_no' => $a->apply_no,
                'status' => $a->status,
                'status_label' => $a->statusLabel(),
                'batch_code' => $a->batch?->code,
                'address' => $a->address,
                'heating_method' => $a->heating_method,
                'applicant' => $a->applicant?->name,
                'is_late' => $a->is_late,
                'frozen' => $a->status === Application::STATUS_FROZEN,
                'freeze_reason' => $a->freeze_reason,
                'reverify_required' => $a->reverify_required,
                'doc_summary' => $a->documents->groupBy('status')->map->count(),
                'created_at' => $a->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function show(Application $application)
    {
        $application->load([
            'batch.rule', 'household.members', 'applicant',
            'documents', 'reviews.reviewer', 'followUps.user', 'disbursement', 'statusLogs',
        ]);

        return response()->json([
            'application' => [
                'id' => $application->id,
                'apply_no' => $application->apply_no,
                'status' => $application->status,
                'status_label' => $application->statusLabel(),
                'is_late' => $application->is_late,
                'frozen' => $application->status === Application::STATUS_FROZEN,
                'freeze_reason' => $application->freeze_reason,
                'reverify_required' => $application->reverify_required,
                'heating_method' => $application->heating_method,
                'address' => $application->address,
                'rule_snapshot' => $application->rule_snapshot,
                'payload_snapshot' => $application->payload_snapshot,
                'batch' => ['code' => $application->batch->code, 'title' => $application->batch->title],
                'applicant' => ['id' => $application->applicant->id, 'name' => $application->applicant->name],
                'household_members' => $application->household?->members?->map(fn ($m) => [
                    'id' => $m->id, 'name' => $m->name, 'phone' => $m->phone,
                ]),
                'documents' => $application->documents->map(fn ($d) => [
                    'id' => $d->id,
                    'doc_type' => $d->doc_type,
                    'doc_name' => $d->doc_name,
                    'issued_on' => $d->issued_on->toDateString(),
                    'expire_on' => $d->expire_on->toDateString(),
                    'status' => $d->status,
                ]),
                'reviews' => $application->reviews->map(fn ($r) => [
                    'id' => $r->id,
                    'level' => $r->level,
                    'result' => $r->result,
                    'comment' => $r->comment,
                    'reviewer_id' => $r->reviewer_id,
                    'reviewer' => $r->reviewer?->name,
                    'checked_items' => $r->checked_items,
                    'created_at' => $r->created_at?->toDateTimeString(),
                ]),
                'follow_ups' => $application->followUps->map(fn ($f) => [
                    'id' => $f->id,
                    'type' => $f->type,
                    'reason' => $f->reason,
                    'status' => $f->status,
                    'user' => $f->user?->name,
                    'handle_comment' => $f->handle_comment,
                    'created_at' => $f->created_at?->toDateTimeString(),
                ]),
                'disbursement' => $application->disbursement?->only([
                    'id', 'status', 'amount', 'payee_name', 'payee_account',
                    'payment_no', 'receipt_no', 'paid_at', 'receipt_count',
                ]),
                'timeline' => $application->statusLogs->sortBy('id')->map(fn ($l) => [
                    'event' => $l->event,
                    'message' => $l->message,
                    'created_at' => $l->created_at?->toDateTimeString(),
                ])->values(),
            ],
        ]);
    }

    public function communityReview(Request $request, Application $application)
    {
        $data = $request->validate([
            'result' => ['required', 'in:pass,reject,supplement'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $review = $this->reviews->communityReview($application, $request->user(), $data['result'], $data['comment'] ?? null);

        return response()->json(['message' => '初审已完成', 'review_id' => $review->id]);
    }

    public function streetReview(Request $request, Application $application)
    {
        $data = $request->validate([
            'result' => ['required', 'in:pass,reject'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $review = $this->reviews->streetReview($application, $request->user(), $data['result'], $data['comment'] ?? null);

        return response()->json(['message' => '复核已完成', 'review_id' => $review->id]);
    }

    public function reverify(Request $request, Application $application)
    {
        $data = $request->validate([
            'result' => ['required', 'in:pass,reject'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $review = $this->reviews->reverifyAfterFreeze($application, $request->user(), $data['result'], $data['comment'] ?? null);

        return response()->json(['message' => '重新核验已完成', 'review_id' => $review->id]);
    }

    public function handleAppeal(Request $request, Application $application, FollowUp $followUp)
    {
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $follow = $this->reviews->handleAppeal(
            $application, $followUp, $request->user(), $data['accepted'], $data['comment'] ?? null,
        );

        return response()->json(['message' => '申诉已处理', 'follow_up_id' => $follow->id]);
    }
}
