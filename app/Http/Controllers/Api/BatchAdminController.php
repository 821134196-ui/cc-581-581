<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\User;
use App\Services\BatchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BatchAdminController extends Controller
{
    public function __construct(private readonly BatchService $batches) {}

    public function index(Request $request)
    {
        $batches = Batch::with('rule')->orderByDesc('id')->get();
        return response()->json(['batches' => $batches]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== User::ROLE_STREET) {
            return response()->json(['message' => '仅街道可创建批次', 'error_code' => 'forbidden_role'], 403);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:batches,code'],
            'title' => ['required', 'string', 'max:100'],
            'warning_start' => ['required', 'date'],
            'warning_end' => ['required', 'date', 'after_or_equal:warning_start'],
            'apply_deadline' => ['required', 'date'],
            'late_apply_deadline' => ['required', 'date', 'after_or_equal:warning_end'],
            'rule.low_temp_threshold' => ['nullable', 'integer'],
            'rule.allowed_heating_methods' => ['nullable', 'array'],
            'rule.doc_valid_days' => ['nullable', 'integer', 'min:1'],
            'rule.required_documents' => ['nullable', 'array'],
            'rule.subsidy_amount' => ['nullable', 'numeric', 'min:0'],
            'rule.residency_requirement' => ['nullable', 'string', 'max:200'],
            'freeze' => ['nullable', 'boolean'],
        ]);

        $batch = Batch::create(collect($data)->except('rule', 'freeze')->toArray());
        $this->batches->configureRule($batch, $data['rule'] ?? []);

        if (! empty($data['freeze'])) {
            $this->batches->freezeRules($batch->fresh(), $request->user());
        }

        return response()->json(['batch' => $batch->fresh('rule')], 201);
    }

    public function updateRule(Request $request, Batch $batch)
    {
        if ($request->user()->role !== User::ROLE_STREET) {
            return response()->json(['message' => '仅街道可修改规则', 'error_code' => 'forbidden_role'], 403);
        }

        $data = $request->validate([
            'low_temp_threshold' => ['nullable', 'integer'],
            'allowed_heating_methods' => ['nullable', 'array'],
            'doc_valid_days' => ['nullable', 'integer', 'min:1'],
            'required_documents' => ['nullable', 'array'],
            'subsidy_amount' => ['nullable', 'numeric', 'min:0'],
            'residency_requirement' => ['nullable', 'string', 'max:200'],
        ]);

        $rule = $this->batches->configureRule($batch, $data);

        return response()->json(['message' => '规则已更新（新版本）', 'rule' => $rule]);
    }

    public function freezeRules(Request $request, Batch $batch)
    {
        if ($request->user()->role !== User::ROLE_STREET) {
            return response()->json(['message' => '仅街道可冻结规则', 'error_code' => 'forbidden_role'], 403);
        }

        $batch = $this->batches->freezeRules($batch, $request->user());

        return response()->json(['message' => '本批规则已冻结，此后一律按此快照审核（含补报）。', 'batch' => $batch]);
    }
}
