<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Application;
use App\Models\Batch;
use App\Models\Document;
use App\Models\EligibilityRule;
use App\Models\FollowUp;
use App\Models\Household;
use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplicationService
{
    public function __construct(
        private readonly MockSmsService $sms,
        private readonly EligibilityChecker $checker,
    ) {}

    /**
     * 居民提交申请。
     *
     * @param array{
     *   address:string, address_raw?:string, heating_method:string,
     *   residency_met:bool, payee:array{name:string,account:string,bank?:string},
     *   documents:array<int,array{doc_type:string,issued_on:string,file_path?:string}>,
     *   household_head_name?:string, household_head_idcard?:string,
     *   members?:array<int,array{name:string,idcard:string,relation:string}>
     * } $data
     *
     * @return array{application:Application, merged:bool, merged_into:?Application}
     */
    public function submit(User $resident, int $batchId, array $data, ?Carbon $on = null): array
    {
        $on ??= Carbon::now();

        /** @var Batch $batch */
        $batch = Batch::with('rule')->findOrFail($batchId);
        $rule = $batch->rule;
        if (! $rule) {
            throw new DomainException('rule_missing', '该批次尚未配置资格规则，无法受理申请。');
        }

        if (! $batch->isAccepting($on)) {
            throw new DomainException('batch_closed', '本批次受理已截止，无法提交申请。', [
                'late_deadline' => $batch->late_apply_deadline->toDateString(),
            ]);
        }

        // 预警结束后的补报：仍按本批规则判断，禁止悄悄换批次
        $isLate = $batch->isWithinLateWindow($on);
        if (! $isLate && ! $batch->isWithinApplyWindow($on)) {
            throw new DomainException('batch_closed', '不在受理时间范围内。');
        }

        $address = Household::normalizeAddress($data['address']);
        $headName = $data['household_head_name'] ?? $resident->name;
        $headIdcard = $data['household_head_idcard'] ?? ($resident->idcard ?? '');

        return DB::transaction(function () use (
            $resident, $batch, $rule, $data, $address, $headName, $headIdcard, $isLate, $on
        ) {
            // 同一户判定：标准化住址 + 户主身份证
            $household = Household::query()
                ->where('address', $address)
                ->where('household_head_idcard', $headIdcard)
                ->first();

            $existing = null;
            if ($household) {
                $existing = Application::where('batch_id', $batch->id)
                    ->where('household_id', $household->id)
                    ->where('merged', false)
                    ->latest('id')
                    ->first();
            }

            if ($existing) {
                // 一户多名成员重复申请：并入原申请，不另立申请、不重复发钱
                if ($resident->household_id && $resident->household_id !== $household->id) {
                    $resident->update(['household_id' => $household->id, 'is_household_head' => false]);
                } elseif (! $resident->household_id) {
                    $resident->update(['household_id' => $household->id, 'is_household_head' => false]);
                }

                $dup = $this->createMergedShadow($resident, $batch, $household, $existing, $data, $isLate);

                Timeline::log(
                    $existing,
                    'household_duplicate_merged',
                    "家庭成员「{$resident->name}」就同一批次重复提交申请，已并入本申请（重复件编号 {$dup->apply_no}），不重复受理。",
                    $resident->id,
                    ['shadow_apply_no' => $dup->apply_no],
                );

                $this->sms->send(
                    $resident,
                    MockSmsService::TPL_MERGED,
                    "【暖冬补贴】您提交的 {$batch->code} 批次申请与本户已受理申请 {$existing->apply_no} 重复，已自动合并，不会重复发放。",
                    $existing,
                );

                return ['application' => $existing, 'merged' => true, 'merged_into' => $existing];
            }

            // 新户（唯一索引保证并发提交也只会建出一户）
            $household = Household::firstOrCreate(
                ['address' => $address, 'household_head_idcard' => $headIdcard],
                [
                    'address_raw' => $data['address_raw'] ?? $data['address'],
                    'household_head_name' => $headName,
                ],
            );

            if ($resident->household_id && $resident->household_id !== $household->id) {
                // 居民已挂在别的户（如同地址换户主），跟随新户
            }
            $resident->update([
                'household_id' => $household->id,
                'idcard' => $resident->idcard ?: $headIdcard,
                'is_household_head' => true,
            ]);

            $payload = $this->buildPayload($resident, $data);
            $application = Application::create([
                'apply_no' => $this->genApplyNo($batch),
                'batch_id' => $batch->id,
                'household_id' => $household->id,
                'applicant_id' => $resident->id,
                'rule_snapshot' => $rule->toSnapshot(),
                'payload_snapshot' => $payload,
                'heating_method' => $data['heating_method'],
                'address' => $household->address_raw,
                'is_late' => $isLate,
                'status' => Application::STATUS_SUBMITTED,
            ]);

            $this->syncDocuments($application, $data['documents'] ?? [], $rule, $on);

            Timeline::log(
                $application,
                'submitted',
                $isLate
                    ? "预警结束后补报受理，按 {$batch->code} 批次规则核验（申请编号 {$application->apply_no}）。"
                    : "申请已受理（申请编号 {$application->apply_no}），等待社区初审。",
                $resident->id,
                ['is_late' => $isLate, 'rule_version' => $rule->version],
            );

            $this->sms->send(
                $resident,
                MockSmsService::TPL_ACCEPTED,
                "【暖冬补贴】您的 {$batch->code} 批次低温取暖补贴申请已受理，编号 {$application->apply_no}，"
                . ($isLate ? '属预警后补报，按本批规则核验。' : '将依次进行社区初审、街道复核。'),
                $application,
            );

            return ['application' => $application, 'merged' => false, 'merged_into' => null];
        });
    }

    /**
     * 居民补件：沿原申请跟进（编号、批次、规则快照均不变）。
     *
     * @param array<int,array{doc_type:string,issued_on:string,file_path?:string}> $documents
     */
    public function supplement(Application $application, User $resident, array $documents, ?string $note = null): FollowUp
    {
        $this->assertBelongsToHousehold($application, $resident);

        if (! in_array($application->status, [
            Application::STATUS_SUPPLEMENT_REQUESTED,
            Application::STATUS_COMMUNITY_REJECTED,
        ], true)) {
            throw new DomainException('supplement_not_allowed', '当前状态不允许补件，请按页面提示操作。', [
                'status' => $application->status,
            ]);
        }

        if ($application->merged) {
            throw new DomainException('merged_application', '该申请已合并到本户主申请，请在主申请上补件。');
        }

        return DB::transaction(function () use ($application, $resident, $documents, $note) {
            $rule = new EligibilityRule($application->rule_snapshot);
            $this->syncDocuments($application, $documents, $rule, Carbon::now());

            $followUp = FollowUp::create([
                'application_id' => $application->id,
                'type' => FollowUp::TYPE_SUPPLEMENT,
                'user_id' => $resident->id,
                'reason' => $note ?: '居民按要求补充材料',
                'status' => FollowUp::STATUS_PENDING,
            ]);

            $application->update(['status' => Application::STATUS_SUBMITTED]);
            Timeline::log(
                $application,
                'supplement_submitted',
                '居民已补交材料 ' . count($documents) . ' 份，沿原申请重新进入社区初审。',
                $resident->id,
                ['follow_up_id' => $followUp->id, 'doc_types' => array_column($documents, 'doc_type')],
            );

            return $followUp;
        });
    }

    /**
     * 居民申诉：沿原申请跟进，不另立新批次。
     */
    public function appeal(Application $application, User $resident, string $reason): FollowUp
    {
        $this->assertBelongsToHousehold($application, $resident);

        if (! in_array($application->status, [
            Application::STATUS_COMMUNITY_REJECTED,
            Application::STATUS_REJECTED,
        ], true)) {
            throw new DomainException('appeal_not_allowed', '仅初审/复核不通过的申请可以申诉。', [
                'status' => $application->status,
            ]);
        }

        if ($application->merged) {
            throw new DomainException('merged_application', '该申请已合并，请在主申请上操作。');
        }

        return DB::transaction(function () use ($application, $resident, $reason) {
            $followUp = FollowUp::create([
                'application_id' => $application->id,
                'type' => FollowUp::TYPE_APPEAL,
                'user_id' => $resident->id,
                'reason' => $reason,
                'status' => FollowUp::STATUS_PENDING,
            ]);

            $application->update(['status' => Application::STATUS_APPEALING]);
            Timeline::log(
                $application,
                'appeal_submitted',
                "居民提出申诉：{$reason}",
                $resident->id,
                ['follow_up_id' => $followUp->id],
            );

            return $followUp;
        });
    }

    /**
     * 变更收款人：申请冻结，必须重新核验后方可继续发放。
     */
    public function changePayee(Application $application, User $resident, array $newPayee): Application
    {
        $this->assertBelongsToHousehold($application, $resident);

        foreach (['name', 'account'] as $k) {
            if (empty($newPayee[$k])) {
                throw new DomainException('payee_incomplete', '收款人姓名和账户不能为空。');
            }
        }

        if ($application->merged) {
            throw new DomainException('merged_application', '该申请已合并，请在主申请上操作。');
        }

        if ($application->status === Application::STATUS_DISBURSED) {
            throw new DomainException('already_disbursed', '补贴已到账，不能变更收款人。');
        }

        return DB::transaction(function () use ($application, $resident, $newPayee) {
            $old = $application->payee();
            if ($old['account'] === $newPayee['account'] && $old['name'] === $newPayee['name']) {
                throw new DomainException('payee_unchanged', '新收款人与原收款人一致，无需变更。');
            }

            // 已进入复核/发放环节后变更收款人 → 冻结并重新核验
            $needsFreeze = in_array($application->status, [
                Application::STATUS_STREET_PENDING,
                Application::STATUS_APPROVED,
                Application::STATUS_FROZEN,
            ], true) || $application->reviews()->exists();

            $payload = $application->payload_snapshot;
            $payload['payee'] = [
                'name' => $newPayee['name'],
                'account' => $newPayee['account'],
                'bank' => $newPayee['bank'] ?? ($payload['payee']['bank'] ?? null),
                'changed_at' => Carbon::now()->toDateTimeString(),
                'changed_by' => $resident->id,
                'previous' => $old,
            ];
            $application->payload_snapshot = $payload;

            if ($needsFreeze) {
                if ($application->status !== Application::STATUS_FROZEN) {
                    $application->status_before_freeze = $application->status;
                }
                $application->status = Application::STATUS_FROZEN;
                $application->frozen_by = $resident->id;
                $application->frozen_at = Carbon::now();
                $application->freeze_reason = '收款人变更，冻结等待街道重新核验';
                $application->reverify_required = true;
            }
            $application->save();

            if ($disbursement = $application->disbursement) {
                if ($disbursement->status !== \App\Models\Disbursement::STATUS_PAID) {
                    $disbursement->update(['status' => \App\Models\Disbursement::STATUS_FROZEN]);
                }
            }

            Timeline::log(
                $application,
                'payee_changed_frozen',
                "收款人由「{$old['name']}（{$old['account']}）」变更为「{$newPayee['name']}（{$newPayee['account']}）」，"
                . ($needsFreeze ? '申请已冻结，须街道重新核验后继续发放。' : '资料已更新。'),
                $resident->id,
            );

            if ($needsFreeze) {
                $this->sms->send(
                    $resident,
                    MockSmsService::TPL_FROZEN,
                    "【暖冬补贴】因收款人变更，您的申请 {$application->apply_no} 已冻结，重新核验通过后继续发放。",
                    $application,
                );
            }

            return $application->fresh();
        });
    }

    private function createMergedShadow(
        User $resident, Batch $batch, Household $household,
        Application $target, array $data, bool $isLate,
    ): Application {
        return Application::create([
            'apply_no' => $this->genApplyNo($batch),
            'batch_id' => $batch->id,
            'household_id' => $household->id,
            'applicant_id' => $resident->id,
            'rule_snapshot' => $target->rule_snapshot,
            'payload_snapshot' => $data,
            'heating_method' => $data['heating_method'],
            'address' => $household->address_raw,
            'is_late' => $isLate,
            'status' => Application::STATUS_DRAFT,
            'merged' => true,
            'merged_into_id' => $target->id,
        ]);
    }

    /**
     * @param array<int,array{doc_type:string,issued_on:string,file_path?:string}> $documents
     */
    private function syncDocuments(Application $application, array $documents, EligibilityRule $rule, Carbon $on): void
    {
        foreach ($documents as $d) {
            if (empty($d['doc_type']) || empty($d['issued_on'])) {
                throw new DomainException('document_incomplete', '材料类型和签发日期不能为空。');
            }
            $issued = Carbon::parse($d['issued_on']);
            $application->documents()->create([
                'doc_type' => $d['doc_type'],
                'doc_name' => Document::TYPE_LABELS[$d['doc_type']] ?? $d['doc_type'],
                'file_path' => $d['file_path'] ?? null,
                'issued_on' => $issued->toDateString(),
                // 到期日按【本批规则】的 doc_valid_days 计算
                'expire_on' => $issued->copy()->addDays($rule->doc_valid_days)->toDateString(),
                'status' => Document::STATUS_SUBMITTED,
            ]);
        }
    }

    private function buildPayload(User $resident, array $data): array
    {
        return [
            'address' => $data['address'],
            'heating_method' => $data['heating_method'],
            'residency_met' => (bool) ($data['residency_met'] ?? false),
            'members' => $data['members'] ?? [[
                'name' => $resident->name,
                'idcard' => $resident->idcard ?? '',
                'relation' => '户主',
            ]],
            'payee' => [
                'name' => $data['payee']['name'],
                'account' => $data['payee']['account'],
                'bank' => $data['payee']['bank'] ?? null,
            ],
        ];
    }

    private function genApplyNo(Batch $batch): string
    {
        return $batch->code . '-' . strtoupper(Str::random(8));
    }

    private function assertBelongsToHousehold(Application $application, User $user): void
    {
        if ($application->applicant_id === $user->id) {
            return;
        }
        if ($user->household_id && $user->household_id === $application->household_id) {
            return;
        }
        throw new DomainException('not_your_application', '只能查看或操作本户的申请。');
    }
}
