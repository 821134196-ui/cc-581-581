<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** 社区初审队列 */
    public function communityQueue(Request $request)
    {
        $apps = Application::where('status', 'submitted')
            ->where('frozen', false)
            ->with(['batch', 'household', 'members', 'applicant', 'documents'])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $apps->map(fn ($a) => $this->queueItem($a))]);
    }

    /** 街道复核队列 */
    public function streetQueue(Request $request)
    {
        $apps = Application::where('status', 'pending_street')
            ->where('frozen', false)
            ->with(['batch', 'household', 'members', 'applicant', 'documents', 'reviews'])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $apps->map(function ($a) use ($request) {
            $item = $this->queueItem($a);
            $item['blocked_for_me'] = $a->reviews
                ->where('stage', 'community')
                ->contains(fn ($r) => $r->reviewer_id === $request->user()->id)
                || !$a->payee_verified;
            $item['block_reason'] = !$a->payee_verified ? '收款人变更待重新核验' : null;
            return $item;
        })]);
    }

    public function communityReview(Application $application, Request $request)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return_supplement', 'reject'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $app = $this->reviews->communityReview(
            $application, $request->user(), $data['decision'], $data['note'] ?? null,
        );

        return response()->json(['data' => ['id' => $app->id, 'status' => $app->status]]);
    }

    public function streetReview(Application $application, Request $request)
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'return_supplement', 'reject'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $app = $this->reviews->streetReview(
            $application, $request->user(), $data['decision'], $data['note'] ?? null,
        );

        return response()->json(['data' => ['id' => $app->id, 'status' => $app->status]]);
    }

    public function freeze(Application $application, Request $request)
    {
        $data = $request->validate(['frozen' => ['required', 'boolean'], 'reason' => ['nullable', 'string']]);
        $app = $this->reviews->freeze($application, $request->user(),
            (bool) $data['frozen'], $data['reason'] ?? null);
        return response()->json(['data' => ['id' => $app->id, 'frozen' => $app->frozen]]);
    }

    private function queueItem(Application $a): array
    {
        return [
            'id' => $a->id,
            'batch_name' => $a->batch->name,
            'address' => $a->household->address,
            'applicant' => $a->applicant->name,
            'members' => $a->members->pluck('name')->all(),
            'is_late' => $a->is_late,
            'heating_method' => $a->payload['heating_method'] ?? null,
            'payee' => $a->payee_name.' '.$a->payee_account,
            'payee_verified' => $a->payee_verified,
            'documents' => $a->documents->map(fn ($d) => [
                'label' => $d->label,
                'status' => $d->isExpired(now()) ? 'expired' : $d->status,
            ])->all(),
        ];
    }
}
