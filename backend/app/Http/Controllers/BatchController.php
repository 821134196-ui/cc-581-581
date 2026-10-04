<?php

namespace App\Http\Controllers;

use App\Models\AlertBatch;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    /** 批次列表（居民申请时选择本批，规则随批次一起返回） */
    public function index()
    {
        $batches = AlertBatch::query()
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (AlertBatch $b) => $this->present($b));

        return response()->json(['data' => $batches]);
    }

    public function show(AlertBatch $batch)
    {
        return response()->json(['data' => $this->present($batch)]);
    }

    /** 街道/社区发布低温预警批次（含本批资格规则） */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'level' => ['required', 'in:blue,yellow,orange,red'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'late_filing_deadline' => ['nullable', 'date'],
            'subsidy_amount' => ['required', 'integer', 'min:1'],
            'rules.required_documents' => ['present', 'array'],
            'rules.required_documents.*.type' => ['required', 'string'],
            'rules.required_documents.*.label' => ['required', 'string'],
            'rules.eligible_heating_methods' => ['present', 'array', 'min:1'],
            'rules.eligible_heating_methods.*' => ['string'],
            'rules.version' => ['nullable', 'string'],
        ]);
        $data['rules']['version'] ??= 'v'.time();

        $batch = AlertBatch::create($data + ['is_accepting' => true]);

        return response()->json(['data' => $this->present($batch)], 201);
    }

    /** 结束预警（之后的申请按补报处理，仍用本批规则） */
    public function endAlert(AlertBatch $batch, Request $request)
    {
        $request->validate(['late_filing_deadline' => ['nullable', 'date']]);
        $batch->update([
            'ended_at' => now(),
            'late_filing_deadline' => $request->input('late_filing_deadline')
                ?? now()->addDays(7)->toDateString(),
        ]);
        return response()->json(['data' => $this->present($batch->fresh())]);
    }

    private function present(AlertBatch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'level' => $b->level,
            'started_at' => $b->started_at?->toDateTimeString(),
            'ended_at' => $b->ended_at?->toDateTimeString(),
            'is_accepting' => $b->is_accepting,
            'late_filing_deadline' => $b->late_filing_deadline?->toDateString(),
            'subsidy_amount' => $b->subsidy_amount,
            'rules' => $b->rules,
            'alert_ended' => $b->isEnded(now()),
            'accepts_late_filing' => $b->acceptsLateFiling(now()),
        ];
    }
}
