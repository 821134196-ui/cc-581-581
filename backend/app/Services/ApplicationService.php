<?php

namespace App\Services;

use App\Exceptions\DuplicateApplicationException;
use App\Models\AlertBatch;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\Document;
use App\Models\Household;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationService
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * 居民提交申请。
     *
     * 关键点：
     *  - batch_id 必须由申请人显式指定，服务端绝不自动切换批次；
     *  - 预警结束后的补报按本批规则判断（is_late + 补报期限）；
     *  - 规则在受理瞬间快照进 application.rule_snapshot，之后批次改规则不影响本申请；
     *  - 同户同批已有在途申请时返回 409 合并提示，确认后并入原申请。
     *
     * @param array{
     *   alert_batch_id:int, address:string, heating_method:string,
     *   payee_name:string, payee_account:string, payee_bank?:?string,
     *   members:array<int,array{name:string,id_number:string,phone?:?string}>,
     *   merge_application_id?:?int, note?:?string
     * } $data
     */
    public function apply(User $user, array $data, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        /** @var AlertBatch $batch */
        $batch = AlertBatch::findOrFail($data['alert_batch_id']);

        if (!$batch->acceptsLateFiling($now)) {
            throw ValidationException::withMessages([
                'alert_batch_id' => $batch->isEnded($now)
                    ? '本批低温预警已结束，且已过补报截止日期，不能再按本批申请。'
                    : '本批次当前不受理申请。',
            ]);
        }

        $household = Household::firstOrCreateByAddress($data['address']);

        // 同一户在同一批次的在途申请（不含已合并、已终局驳回、已到账的）
        $existing = Application::query()
            ->where('alert_batch_id', $batch->id)
            ->where('household_id', $household->id)
            ->whereNotIn('status', ['merged', 'community_rejected', 'street_rejected', 'paid'])
            ->with(['applicant', 'members'])
            ->get();

        if ($existing->isNotEmpty() && !isset($data['merge_application_id'])) {
            $target = $existing->first();
            throw new DuplicateApplicationException([[
                'application_id' => $target->id,
                'applicant' => $target->applicant->name,
                'members' => $target->members->pluck('name')->all(),
                'status' => $target->status,
            ]], '同一住址在本批低温预警已有在途申请，请与家人确认后并入该申请，不要重复申领。');
        }

        return DB::transaction(function () use ($data, $batch, $household, $user, $now) {
            $memberIds = [];
            foreach ($data['members'] as $m) {
                $member = Member::firstOrCreate(
                    ['id_number' => $m['id_number']],
                    [
                        'household_id' => $household->id,
                        'name' => $m['name'],
                        'phone' => $m['phone'] ?? null,
                    ],
                );
                if ($member->household_id !== $household->id) {
                    throw ValidationException::withMessages([
                        'members' => "证件号 {$m['id_number']} 已登记在其他住址，不能重复登记。",
                    ]);
                }
                // 首位成员视为申请人本人，关联登录账号（居民只能看自己的记录）
                if ($memberIds === []) {
                    $member->update(['user_id' => $user->id, 'phone' => $m['phone'] ?? $user->phone]);
                }
                $memberIds[] = $member->id;
            }

            // 显式并入同户已有申请
            if (isset($data['merge_application_id'])) {
                $target = Application::with('members')->findOrFail($data['merge_application_id']);
                abort_if($target->alert_batch_id !== $batch->id || $target->household_id !== $household->id,
                    422, '只能并入同户同批次的在途申请。');
                abort_if(in_array($target->status, ['merged', 'paid'], true),
                    422, '目标申请已不可并入。');

                $target->members()->syncWithoutDetaching($memberIds);
                $target->load('members');

                ApplicationEvent::log($target, 'members_merged', $user,
                    "家庭成员 {$user->name} 的重复申请已并入，本申请现覆盖 "
                    .$target->members->pluck('name')->implode('、').'。');

                // 保留合并痕迹：重复申请落为 merged 并指向原申请
                $stub = Application::create([
                    'alert_batch_id' => $batch->id,
                    'household_id' => $household->id,
                    'applicant_user_id' => $user->id,
                    'status' => 'merged',
                    'is_late' => $batch->isEnded($now),
                    'rule_snapshot' => $batch->rules,
                    'payload' => ['heating_method' => $data['heating_method'], 'merged_reason' => 'duplicate_in_household'],
                    'payee_name' => $data['payee_name'],
                    'payee_account' => $data['payee_account'],
                    'payee_bank' => $data['payee_bank'] ?? null,
                    'merged_into_id' => $target->id,
                ]);
                $stub->members()->sync($memberIds);
                ApplicationEvent::log($stub, 'merged_into', $user,
                    "与同户申请 #{$target->id} 重复，已并入该申请。");

                $this->sms->send($user, "【社区取暖补贴】您的申请已并入同户申请 #{$target->id}，"
                    .'后续审核与到账进度请在该申请中查看，补贴按户只发一次。', 'merge');

                return ['merged' => true, 'application_id' => $target->id];
            }

            $isLate = $batch->isEnded($now);
            $application = Application::create([
                'alert_batch_id' => $batch->id,
                'household_id' => $household->id,
                'applicant_user_id' => $user->id,
                'status' => 'submitted',
                'is_late' => $isLate,
                // 关键：页面保存的是「本批资格规则」+「申请时资料」的快照
                'rule_snapshot' => $batch->rules,
                'payload' => [
                    'heating_method' => $data['heating_method'],
                    'note' => $data['note'] ?? null,
                    'submitted_at' => $now->toDateTimeString(),
                ],
                'payee_name' => $data['payee_name'],
                'payee_account' => $data['payee_account'],
                'payee_bank' => $data['payee_bank'] ?? null,
            ]);
            $application->members()->sync($memberIds);

            foreach ($batch->rules['required_documents'] ?? [] as $doc) {
                Document::create([
                    'application_id' => $application->id,
                    'doc_type' => $doc['type'],
                    'label' => $doc['label'],
                    'status' => 'required',
                ]);
            }

            ApplicationEvent::log($application, 'submitted', $user,
                ($isLate ? '预警结束后的补报申请' : '申请已提交')."，按批次「{$batch->name}」规则受理。",
                ['batch_id' => $batch->id, 'is_late' => $isLate]);

            $this->sms->send($user,
                "【社区取暖补贴】您按「{$batch->name}」提交的取暖补贴申请已受理"
                .($isLate ? '（预警结束后补报，按本批规则审核）' : '').'，可在系统查看审核进度。',
                'submitted');

            return ['merged' => false, 'application_id' => $application->id];
        });
    }

    /**
     * 补交材料：始终沿原申请跟进，不新建申请、不换批次。
     */
    public function submitDocument(Application $application, User $user, string $docType,
                                   ?string $fileRef, ?Carbon $expiresAt = null, ?Carbon $now = null): Document
    {
        $now ??= Carbon::now();
        abort_if($application->status === 'merged', 422, '该申请已并入其他申请，请在原申请中补件。');
        abort_if($application->status === 'paid', 422, '申请已到账，无需补件。');

        $doc = $application->documents()->where('doc_type', $docType)->firstOr(fn () =>
            throw ValidationException::withMessages(['doc_type' => "本批规则不要求材料：{$docType}"]));

        $doc->update([
            'status' => $expiresAt?->isBefore($now) ? 'expired' : 'submitted',
            'file_ref' => $fileRef ?: ('mock-upload-'.$docType.'-'.$now->timestamp),
            'expires_at' => $expiresAt,
            'submitted_by' => $user->id,
        ]);

        ApplicationEvent::log($application, 'document_submitted', $user,
            "材料「{$doc->label}」已补交。");

        // 补件齐备：等待补件的申请重新进入社区初审队列
        if ($application->status === 'supplementing') {
            $check = $this->documentStatus($application, $now);
            if (empty($check['missing']) && empty($check['expired'])) {
                $application->update(['status' => 'submitted']);
                ApplicationEvent::log($application, 'resubmitted', $user,
                    '材料已补齐，申请重新进入社区初审队列。');
                $this->sms->send($application->applicant,
                    '【社区取暖补贴】您补交的材料已齐备，申请重新进入审核流程。', 'supplement_done');
            }
        }

        return $doc->fresh();
    }

    /**
     * @return array{missing:array<int,array{type:string,label:string}>,expired:array<int,array{type:string,label:string}>}
     */
    public function documentStatus(Application $application, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $missing = [];
        $expired = [];
        foreach ($application->documents as $doc) {
            if ($doc->status === 'required') {
                $missing[] = ['type' => $doc->doc_type, 'label' => $doc->label];
            } elseif ($doc->status === 'expired' || $doc->isExpired($now)) {
                $expired[] = ['type' => $doc->doc_type, 'label' => $doc->label];
            }
        }
        return ['missing' => $missing, 'expired' => $expired];
    }

    /**
     * 按申请自身保存的规则快照判断资格（批次后续改规则不影响本申请）。
     */
    public function eligibility(Application $application, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $rules = $application->rule_snapshot;
        $heating = $application->payload['heating_method'] ?? null;

        $heatingOk = in_array($heating, $rules['eligible_heating_methods'] ?? [], true);
        $docs = $this->documentStatus($application, $now);

        $reasons = [];
        if (!$heatingOk) {
            $reasons[] = "供暖方式「{$heating}」不在本批补贴范围（"
                .implode('、', $rules['eligible_heating_methods'] ?? []).'）';
        }
        foreach ($docs['missing'] as $d) {
            $reasons[] = "缺少材料：{$d['label']}";
        }
        foreach ($docs['expired'] as $d) {
            $reasons[] = "材料已过期，请重新提交：{$d['label']}";
        }

        return [
            'eligible' => $heatingOk && empty($docs['missing']) && empty($docs['expired']),
            'heating_ok' => $heatingOk,
            'missing_documents' => $docs['missing'],
            'expired_documents' => $docs['expired'],
            'reasons' => $reasons,
            'rule_version' => $rules['version'] ?? null,
        ];
    }

    /**
     * 扫描材料过期：把过期材料标 expired，在途申请退回补件并短信提示。
     * 已到账申请不动钱，只记录。
     *
     * @return array{marked:int,returned:int}
     */
    public function sweepExpiredDocuments(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $marked = 0;
        $returned = 0;

        Document::query()
            ->where('status', 'submitted')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $now->toDateString())
            ->with('application')
            ->chunkById(200, function ($docs) use (&$marked) {
                foreach ($docs as $doc) {
                    $doc->update(['status' => 'expired']);
                    ApplicationEvent::log($doc->application, 'document_expired', null,
                        "材料「{$doc->label}」已过期，需补充更新。");
                    $marked++;
                }
            });

        $appIds = Document::where('status', 'expired')
            ->distinct()->pluck('application_id');
        Application::query()
            ->whereIn('id', $appIds)
            ->whereIn('status', ['submitted', 'pending_street', 'approved', 'supplementing'])
            ->with('applicant')
            ->chunkById(200, function ($apps) use (&$returned) {
                foreach ($apps as $app) {
                    if ($app->status !== 'supplementing') {
                        $app->update(['status' => 'supplementing']);
                        ApplicationEvent::log($app, 'returned_for_expiry', null,
                            '因材料过期，申请退回补件，补齐后重新进入审核。');
                        $this->sms->send($app->applicant,
                            '【社区取暖补贴】您的申请有材料已过期，请尽快登录系统补交，以免影响补贴发放。',
                            'doc_expired');
                        $returned++;
                    }
                }
            });

        return ['marked' => $marked, 'returned' => $returned];
    }
}
