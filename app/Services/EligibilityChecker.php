<?php

namespace App\Services;

use App\Models\Application;
use Carbon\Carbon;

/**
 * 资格判定：一律按申请保存的规则快照（rule_snapshot）判断，
 * 即使批次规则后来修改、或预警结束后补报，也不换规则。
 */
class EligibilityChecker
{
    /**
     * @return array{
     *   eligible:bool,
     *   missing_docs:array<int,array{doc_type:string,doc_name:string,reason:string}>,
     *   expired_docs:array<int,array{id:int,doc_type:string,doc_name:string,expire_on:string}>,
     *   failed_items:array<int,string>,
     *   checked:array<string,bool>
     * }
     */
    public function check(Application $application, ?Carbon $on = null, bool $markExpired = false): array
    {
        $on ??= Carbon::now();
        $rule = $application->rule_snapshot;

        $missingDocs = [];
        $expiredDocs = [];
        $failedItems = [];
        $checked = [];

        // 1) 供暖方式
        $heatingOk = in_array($application->heating_method, $rule['allowed_heating_methods'] ?? [], true);
        $checked['heating_method'] = $heatingOk;
        if (! $heatingOk) {
            $failedItems[] = "供暖方式「{$application->heating_method}」不在本批允许范围";
        }

        // 2) 居住情况（申请资料中声明，初审核实）
        $payload = $application->payload_snapshot;
        $residencyOk = (bool) ($payload['residency_met'] ?? false);
        $checked['residency'] = $residencyOk;
        if (! $residencyOk) {
            $failedItems[] = '不满足本批居住要求：' . ($rule['residency_requirement'] ?? '');
        }

        // 3) 材料齐备性 + 有效性（按快照中的 doc_valid_days 判断是否过期）
        $required = $rule['required_documents'] ?? [];
        $docs = $application->documents()->get();

        foreach ($required as $docType) {
            // 每份必备材料取该类型下“最新一份”作为当前有效件
            $doc = $docs->where('doc_type', $docType)
                ->reject(fn ($d) => in_array($d->status, ['waived'], true))
                ->sortByDesc('id')
                ->first();

            $docName = \App\Models\Document::TYPE_LABELS[$docType] ?? $docType;

            if (! $doc) {
                $missingDocs[] = [
                    'doc_type' => $docType,
                    'doc_name' => $docName,
                    'reason' => '尚未提交',
                ];
                continue;
            }

            if ($doc->isExpiredOn($on->copy())) {
                if ($markExpired && $doc->status === 'submitted') {
                    $doc->update(['status' => 'expired']);
                }
                $expiredDocs[] = [
                    'id' => $doc->id,
                    'doc_type' => $docType,
                    'doc_name' => $docName,
                    'expire_on' => $doc->expire_on->toDateString(),
                ];
            }
        }

        $checked['documents_complete'] = empty($missingDocs) && empty($expiredDocs);

        $eligible = $heatingOk && $residencyOk && empty($missingDocs) && empty($expiredDocs);

        return compact('eligible', 'missingDocs', 'expiredDocs', 'failedItems', 'checked');
    }
}
