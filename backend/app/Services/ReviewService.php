<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\PayeeChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * 社区初审。
     *
     * @param 'approve'|'return_supplement'|'reject' $decision
     */
    public function communityReview(Application $application, User $reviewer,
                                    string $decision, ?string $note = null): Application
    {
        abort_unless($reviewer->isCommunity(), 403, '仅社区初审员可以进行初审。');
        abort_if($application->frozen, 423, '申请已冻结，暂不能审核。');
        abort_unless(in_array($application->status, ['submitted'], true),
            422, '当前状态（'.Application::STATUSES[$application->status].'）不能进行初审。');

        // 初审即按申请自带的规则快照核验资格
        if ($decision === 'approve') {
            $app = app(ApplicationService::class)->eligibility($application);
            if (!$app['eligible']) {
                throw ValidationException::withMessages([
                    'eligibility' => $app['reasons'],
                ]);
            }
        }

        return DB::transaction(function () use ($application, $reviewer, $decision, $note) {
            $application->reviews()->create([
                'stage' => 'community',
                'reviewer_id' => $reviewer->id,
                'decision' => $decision,
                'note' => $note,
            ]);

            $next = match ($decision) {
                'approve' => 'pending_street',
                'return_supplement' => 'supplementing',
                'reject' => 'community_rejected',
            };
            $application->update(['status' => $next]);

            ApplicationEvent::log($application, 'community_'.$decision, $reviewer,
                '社区初审：'.$this->decisionText($decision).($note ? "（{$note}）" : ''));

            $this->sms->send($application->applicant, match ($decision) {
                'approve' => '【社区取暖补贴】您的申请已通过社区初审，上报街道复核。',
                'return_supplement' => '【社区取暖补贴】社区初审要求补充材料，请登录系统查看缺件清单并尽快补交。',
                'reject' => '【社区取暖补贴】您的申请未通过社区初审，如有异议可在系统中提交申诉。',
            }, 'community_'.$decision);

            return $application->fresh();
        });
    }

    /**
     * 街道复核。
     * 关键控制：做过该申请初审的人不得复核同一份申请。
     *
     * @param 'approve'|'return_supplement'|'reject' $decision
     */
    public function streetReview(Application $application, User $reviewer,
                                 string $decision, ?string $note = null): Application
    {
        abort_unless($reviewer->isStreet(), 403, '仅街道复核员可以进行复核。');
        abort_if($application->frozen, 423, '申请已冻结，暂不能复核。');
        abort_unless($application->status === 'pending_street', 422,
            '当前状态（'.Application::STATUSES[$application->status].'）不能进行街道复核。');

        $communityReviewerIds = $application->reviews()
            ->where('stage', 'community')->pluck('reviewer_id')->unique();

        abort_if($communityReviewerIds->contains($reviewer->id), 422,
            '初审与复核必须由不同人员完成：您已对该申请做过初审，不能再复核。');

        if (!$application->payee_verified) {
            throw ValidationException::withMessages([
                'payee' => '收款人信息变更后尚未重新核验通过，不能复核通过。',
            ]);
        }

        return DB::transaction(function () use ($application, $reviewer, $decision, $note) {
            $application->reviews()->create([
                'stage' => 'street',
                'reviewer_id' => $reviewer->id,
                'decision' => $decision,
                'note' => $note,
            ]);

            if ($decision === 'approve') {
                $application->update(['status' => 'approved']);
                // 复核通过即进入本批发放清单（每户一笔）
                app(PaymentService::class)->createRosterEntry($application);
                $sms = '【社区取暖补贴】街道复核通过，您的申请已列入本批补贴发放清单，请注意查看到账情况。';
            } else {
                $application->update(['status' => $decision === 'reject' ? 'street_rejected' : 'supplementing']);
                $sms = $decision === 'reject'
                    ? '【社区取暖补贴】街道复核未通过，如有异议可在系统中提交申诉。'
                    : '【社区取暖补贴】街道复核要求补充材料，请登录系统补交。';
            }

            ApplicationEvent::log($application, 'street_'.$decision, $reviewer,
                '街道复核：'.$this->decisionText($decision).($note ? "（{$note}）" : ''));
            $this->sms->send($application->applicant, $sms, 'street_'.$decision);

            return $application->fresh();
        });
    }

    public function freeze(Application $application, User $operator, bool $frozen, ?string $reason = null): Application
    {
        abort_unless($operator->isStreet(), 403, '仅街道可以冻结/解冻申请。');
        $application->update(['frozen' => $frozen]);
        ApplicationEvent::log($application, $frozen ? 'frozen' : 'unfrozen', $operator,
            $frozen ? '申请已冻结'.($reason ? "（{$reason}）" : '').'，期间审核与发放暂停。'
                    : '申请已解冻，恢复审核与发放。');
        $this->sms->send($application->applicant,
            $frozen ? '【社区取暖补贴】您的申请已被临时冻结，变更收款人等信息需重新核验。'
                    : '【社区取暖补贴】您的申请已解冻。',
            $frozen ? 'frozen' : 'unfrozen');
        return $application->fresh();
    }

    /**
     * 变更收款人。
     * 申请冻结（或已核准待发放）后：不直接改，生成待核验变更，街道核验通过才生效。
     */
    public function requestPayeeChange(Application $application, User $requester, array $data): PayeeChangeRequest
    {
        abort_if(in_array($application->status, ['paid', 'merged'], true), 422,
            '该申请状态下不能变更收款人。');

        $needsVerification = $application->frozen
            || in_array($application->status, ['approved', 'pending_street'], true);

        return DB::transaction(function () use ($application, $requester, $data, $needsVerification) {
            $request = $application->payeeChangeRequests()->create([
                'payee_name' => $data['payee_name'],
                'payee_account' => $data['payee_account'],
                'payee_bank' => $data['payee_bank'] ?? null,
                'status' => 'pending',
            ]);

            if ($needsVerification) {
                // 旧收款人保留，新收款人未经街道核验前不得打款
                $application->update(['payee_verified' => false]);
                ApplicationEvent::log($application, 'payee_change_requested', $requester,
                    "申请".($application->frozen ? '已冻结' : '已进入复核/发放环节')
                    .'，收款人变更（→'.$data['payee_name'].'）需街道重新核验后生效。');
                $this->sms->send($application->applicant,
                    '【社区取暖补贴】收款人变更申请已提交，需街道重新核验，核验通过前不会打款。',
                    'payee_change');
            } else {
                $this->applyPayeeChange($application, $request);
            }

            return $request->fresh();
        });
    }

    public function verifyPayeeChange(PayeeChangeRequest $request, User $reviewer, bool $pass, ?string $note = null): PayeeChangeRequest
    {
        abort_unless($reviewer->isStreet(), 403, '仅街道可以核验收款人变更。');
        abort_if($request->status !== 'pending', 422, '该变更已处理。');

        return DB::transaction(function () use ($request, $reviewer, $pass, $note) {
            $request->update([
                'status' => $pass ? 'verified' : 'rejected',
                'reviewer_id' => $reviewer->id,
                'note' => $note,
            ]);
            $application = $request->application;

            if ($pass) {
                $this->applyPayeeChange($application, $request);
                ApplicationEvent::log($application, 'payee_change_verified', $reviewer,
                    "收款人变更已重新核验通过，现收款人为 {$request->payee_name}。");
                $this->sms->send($application->applicant,
                    "【社区取暖补贴】收款人变更（{$request->payee_name}）已核验通过。", 'payee_verified');
            } else {
                // 驳回：维持原收款人，仍视为已核验的旧信息
                $application->update(['payee_verified' => true]);
                ApplicationEvent::log($application, 'payee_change_rejected', $reviewer,
                    '收款人变更核验未通过，维持原收款人信息。');
            }

            return $request->fresh();
        });
    }

    private function applyPayeeChange(Application $application, PayeeChangeRequest $request): void
    {
        $application->update([
            'payee_name' => $request->payee_name,
            'payee_account' => $request->payee_account,
            'payee_bank' => $request->payee_bank,
            'payee_verified' => true,
        ]);
        $request->update(['status' => 'verified']);
    }

    private function decisionText(string $decision): string
    {
        return match ($decision) {
            'approve' => '通过',
            'return_supplement' => '退回补件',
            'reject' => '不通过',
            default => $decision,
        };
    }
}
