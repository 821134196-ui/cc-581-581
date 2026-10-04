<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Batch;
use App\Models\EligibilityRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BatchService
{
    /** 配置/修改批次规则：仅规则冻结前允许 */
    public function configureRule(Batch $batch, array $attrs): EligibilityRule
    {
        if ($batch->rules_frozen) {
            throw new DomainException('rules_frozen', '本批资格规则已冻结，不能修改；预警后补报也按该规则执行。', [
                'batch_code' => $batch->code,
            ]);
        }

        return DB::transaction(function () use ($batch, $attrs) {
            $rule = $batch->rule;
            $version = $rule ? $rule->version + 1 : 1;
            $data = array_merge([
                'low_temp_threshold' => -5,
                'allowed_heating_methods' => ['集中供暖', '自采暖', '电暖'],
                'doc_valid_days' => 90,
                'required_documents' => ['idcard', 'residence', 'bankcard'],
                'subsidy_amount' => 300,
                'subsidy_unit' => '户',
                'residency_requirement' => '本社区户籍或连续居住满6个月',
            ], $attrs, ['version' => $version]);

            if ($rule) {
                $rule->update($data);
                return $rule->fresh();
            }

            return EligibilityRule::create(array_merge($data, ['batch_id' => $batch->id]));
        });
    }

    /** 冻结规则：冻结后页面（申请快照）与审核都以此为准 */
    public function freezeRules(Batch $batch, User $operator): Batch
    {
        if (! $batch->rule) {
            throw new DomainException('rule_missing', '请先配置资格规则再冻结。');
        }
        if ($batch->rules_frozen) {
            return $batch;
        }

        $batch->update([
            'rules_frozen' => true,
            'rules_frozen_at' => Carbon::now(),
            'rules_frozen_by' => $operator->id,
        ]);

        return $batch;
    }
}
