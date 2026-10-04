<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\Application;
use App\Services\AppealService;
use Illuminate\Http\Request;

class AppealController extends Controller
{
    public function __construct(private readonly AppealService $appeals)
    {
    }

    /** 居民提交申诉（沿原申请） */
    public function store(Application $application, Request $request)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $appeal = $this->appeals->create($application, $request->user(), $request->input('reason'));
        return response()->json(['data' => ['id' => $appeal->id, 'status' => $appeal->status]], 201);
    }

    /** 工作人员处理申诉 */
    public function handle(Appeal $appeal, Request $request)
    {
        $data = $request->validate([
            'accepted' => ['required', 'boolean'],
            'reply' => ['nullable', 'string', 'max:500'],
        ]);
        $updated = $this->appeals->handle(
            $appeal, $request->user(), (bool) $data['accepted'], $data['reply'] ?? null,
        );
        return response()->json(['data' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    /** 待处理申诉队列 */
    public function pendingList(Request $request)
    {
        $items = Appeal::where('status', 'pending')
            ->with('application.household', 'user')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'application_id' => $p->application_id,
                'address' => $p->application->household->address,
                'resident' => $p->user->name,
                'reason' => $p->reason,
                'at' => $p->created_at->toDateTimeString(),
            ]);
        return response()->json(['data' => $items]);
    }
}
