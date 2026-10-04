<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Application;
use App\Models\Disbursement;
use App\Models\FollowUp;
use App\Models\Review;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(
        private readonly EligibilityChecker $checker,
        private readonly MockSmsService $sms,
    ) {}

    /**
     * 社区初审。
     *
     * @param 'pass'|'reject'|'supplement' $result
     */
    public function communityReview(Application $application, User $reviewer, string $result, ?string $comment = null): Review
    {
        $this->assertRole($reviewer, User::ROLE_COMMUNITY);
        $application = Application::whereKey($application->id)->lockForUpdate()->first();

        if (! in_array($application->status, [
            Application::STATUS_SUBMITTED,
            Application::STATUS_SUPPLEMENT_REQUESTED,
        ], true)) {
            throw new DomainException('wrong_status', '当前状态不允许社区初审。', ['status' => $application->status]);
        }

        return DB::transaction(function () use ($application, $reviewer, $result, $comment) {
            // 先检查材料过期（审核当日口径），过期件自动标记并要求补充
            $report = $this->checker->check($application, Carbon::now(), markExpired: true);

            if ($result === Review::RESULT_PASS) {
                if (! $report['eligible']) {
                    // 资格不符不能人工放行：缺件转补件，其余不符驳回
                    if (! empty($report['missingDocs']) || ! empty($report['expiredDocs'])) {
                        return $this->requestSupplement($application, $reviewer, $report, $comment ?: '材料不齐或已过期，请补充。');
                    }
                    throw new DomainException('not_eligible', '资格核验未通过，不能初审通过：' . implode('；', $report['failedItems']));
                }

                $review = $this->recordReview($application, $reviewer, Review::LEVEL_COMMUNITY, Review::RESULT_PASS, $comment, $report);
                $application->update(['status' => Application::STATUS_STREET_PENDING]);
                Timeline::log($application, 'community_pass', '社区初审通过，已转街道复核。', $reviewer->id);
                return $review;
            }

            if ($result === Review::RESULT_SUPPLEMENT) {
                return $this->requestSupplement($application, $reviewer, $report, $comment ?: '请按要求补充材料。');
            }

            // reject
            $review = $this->recordReview($application, $reviewer, Review::LEVEL_COMMUNITY, Review::RESULT_REJECT, $comment, $report);
            $application->update(['status' => Application::STATUS_COMMUNITY_REJECTED]);
            Timeline::log($application, 'community_reject', "社区初审不通过：{$comment}", $reviewer->id);
            $this->notifyApplicant($application, '社区初审未通过', $comment);
            return $review;
        });
    }

    /**
     * 街道复核。强约束：复核员不得是该申请的初审员。
     *
     * @param 'pass'|'reject' $result
     */
    public function streetReview(Application $application, User $reviewer, string $result, ?string $comment = null): Review
    {
        $this->assertRole($reviewer, User::ROLE_STREET);
        $application = Application::whereKey($application->id)->lockForUpdate()->first();

        // 职责分离：初审人不得复核同一份申请
        $communityReviewerId = Review::where('application_id', $application->id)
            ->where('level', Review::LEVEL_COMMUNITY)
            ->where('result', Review::RESULT_PASS)
            ->latest('id')
            ->value('reviewer_id');

        if ($communityReviewerId && (int) $communityReviewerId === (int) $reviewer->id) {
            throw new DomainException(
                'segregation_conflict',
                '您已对该申请做过社区初审，不能再复核同一份申请，请换其他街道人员复核。',
                ['application_id' => $application->id, 'reviewer_id' => $reviewer->id],
            );
        }

        if ($application->status !== Application::STATUS_STREET_PENDING) {
            throw new DomainException('wrong_status', '仅“待街道复核”的申请可以复核。', ['status' => $application->status]);
        }

        return DB::transaction(function () use ($application, $reviewer, $result, $comment, $communityReviewerId) {
            $report = $this->checker->check($application, Carbon::now(), markExpired: true);

            if ($result === Review::RESULT_PASS) {
                if (! $communityReviewerId) {
                    throw new DomainException('no_community_pass', '缺少社区初审通过记录，不能复核。');
                }
                if ($report['eligible']) {
                    // ok
                } elseif (! empty($report['missingDocs']) || ! empty($report['expiredDocs'])) {
                    // 复核环节发现材料过期：退回补件
                    return $this->requestSupplement($application, $reviewer, $report, '街道复核发现材料问题，退回补充。', Review::LEVEL_STREET);
                } else {
                    throw new DomainException('not_eligible', '资格核验未通过：' . implode('；', $report['failedItems']));
                }

                $review = $this->recordReview($application, $reviewer, Review::LEVEL_STREET, Review::RESULT_PASS, $comment, $report);
                $application->update(['status' => Application::STATUS_APPROVED]);
                Timeline::log($application, 'street_pass', '街道复核通过，已进入本批发放清单。', $reviewer->id);

                $this->createOrRestoreDisbursement($application);
                $this->notifyApplicant($application, '街道复核通过', '补贴已进入发放清单，请注意查看到账进度。');
                return $review;
            }

            $review = $this->recordReview($application, $reviewer, Review::LEVEL_STREET, Review::RESULT_REJECT, $comment, $report);
            $application->update(['status' => Application::STATUS_REJECTED]);
            Timeline::log($application, 'street_reject', "街道复核不通过：{$comment}", $reviewer->id);
            $this->notifyApplicant($application, '街道复核未通过', $comment);
            return $review;
        });
    }

    /**
     * 收款人变更冻结后的街道重新核验。仍受“初审/复核同人禁止”约束。
     */
    public function reverifyAfterFreeze(Application $application, User $reviewer, string $result, ?string $comment = null): Review
    {
        $this->assertRole($reviewer, User::ROLE_STREET);
        $application = Application::whereKey($application->id)->lockForUpdate()->first();

        if ($application->status !== Application::STATUS_FROZEN || ! $application->reverify_required) {
            throw new DomainException('not_frozen', '该申请不处于待重新核验的冻结状态。', ['status' => $application->status]);
        }

        $communityReviewerId = Review::where('application_id', $application->id)
            ->where('level', Review::LEVEL_COMMUNITY)
            ->where('result', Review::RESULT_PASS)
            ->latest('id')
            ->value('reviewer_id');
        if ($communityReviewerId && (int) $communityReviewerId === (int) $reviewer->id) {
            throw new DomainException('segregation_conflict', '初审与重新核验不得为同一人。');
        }

        return DB::transaction(function () use ($application, $reviewer, $result, $comment) {
            $report = $this->checker->check($application, Carbon::now(), markExpired: true);

            if ($result !== Review::RESULT_PASS) {
                $review = $this->recordReview($application, $reviewer, Review::LEVEL_STREET, Review::RESULT_REJECT, $comment ?: '重新核验不通过', $report);
                $application->update(['status' => Application::STATUS_REJECTED, 'reverify_required' => false]);
                Timeline::log($application, 'reverify_reject', "收款人变更后重新核验不通过：{$comment}", $reviewer->id);
                $this->notifyApplicant($application, '重新核验未通过', $comment);
                return $review;
            }

            if (! $report['eligible']) {
                throw new DomainException('not_eligible', '材料或资格仍不满足，不能核验通过。');
            }

            $review = $this->recordReview($application, $reviewer, Review::LEVEL_STREET, Review::RESULT_REVERIFY, $comment ?: '收款人变更重新核验通过', $report);

            $restore = $application->status_before_freeze ?: Application::STATUS_APPROVED;
            $application->update([
                'status' => $restore,
                'reverify_required' => false,
                'frozen_at' => null,
                'frozen_by' => null,
                'freeze_reason' => null,
                'status_before_freeze' => null,
            ]);
            Timeline::log($application, 'reverify_pass', '收款人变更重新核验通过，申请恢复到冻结前状态继续发放。', $reviewer->id);

            if ($disbursement = $application->disbursement) {
                if (in_array($disbursement->status, [Disbursement::STATUS_FROZEN, Disbursement::STATUS_LISTED], true)) {
                    // 解冻恢复，并同步重新核验后的收款账户
                    $payee = $application->payee();
                    $disbursement->update([
                        'status' => Disbursement::STATUS_LISTED,
                        'payee_name' => $payee['name'],
                        'payee_account' => $payee['account'],
                    ]);
                }
            } else {
                $this->createOrRestoreDisbursement($application);
            }
            $this->notifyApplicant($application, '重新核验通过', '收款人信息核验完成，补贴将继续发放。');
            return $review;
        });
    }

    /**
     * 处理居民申诉（沿原申请）。成立则回到对应环节重审，不成立维持驳回。
     */
    public function handleAppeal(Application $application, FollowUp $appeal, User $reviewer, bool $accepted, ?string $comment = null): FollowUp
    {
        if ($appeal->type !== FollowUp::TYPE_APPEAL || $appeal->application_id !== $application->id) {
            throw new DomainException('bad_followup', '申诉记录与申请不匹配。');
        }
        if ($application->status !== Application::STATUS_APPEALING) {
            throw new DomainException('wrong_status', '该申请当前不在申诉中。', ['status' => $application->status]);
        }
        // 街道驳回的申诉由街道处理，社区驳回的由社区处理
        $wasStreetReject = Review::where('application_id', $application->id)
            ->where('level', Review::LEVEL_STREET)
            ->where('result', Review::RESULT_REJECT)
            ->exists();
        $this->assertRole($reviewer, $wasStreetReject ? User::ROLE_STREET : User::ROLE_COMMUNITY);

        return DB::transaction(function () use ($application, $appeal, $reviewer, $accepted, $comment, $wasStreetReject) {
            $appeal->update([
                'status' => $accepted ? FollowUp::STATUS_ACCEPTED : FollowUp::STATUS_REJECTED,
                'handle_comment' => $comment,
                'handled_by' => $reviewer->id,
                'handled_at' => Carbon::now(),
            ]);

            if ($accepted) {
                $target = $wasStreetReject
                    ? Application::STATUS_STREET_PENDING
                    : Application::STATUS_SUBMITTED;
                $application->update(['status' => $target]);
                Timeline::log(
                    $application,
                    'appeal_accepted',
                    ($wasStreetReject ? '街道' : '社区') . "认定申诉成立，沿原申请重新审核。处理意见：{$comment}",
                    $reviewer->id,
                );
            } else {
                $target = $wasStreetReject ? Application::STATUS_REJECTED : Application::STATUS_COMMUNITY_REJECTED;
                $application->update(['status' => $target]);
                Timeline::log($application, 'appeal_rejected', "申诉不成立，维持原结论。处理意见：{$comment}", $reviewer->id);
            }
            $this->notifyApplicant($application, $accepted ? '申诉成立' : '申诉未成立', $comment);
            return $appeal;
        });
    }

    private function requestSupplement(Application $application, User $reviewer, array $report, string $comment, string $level = Review::LEVEL_COMMUNITY): Review
    {
        $review = $this->recordReview($application, $reviewer, $level, Review::RESULT_SUPPLEMENT, $comment, $report);
        $application->update(['status' => Application::STATUS_SUPPLEMENT_REQUESTED]);

        $names = collect(array_merge($report['missingDocs'], $report['expiredDocs']))
            ->pluck('doc_name')->unique()->implode('、');
        $msg = "请补充材料：{$names}。补件沿原申请 {$application->apply_no} 跟进，无需重新申请。";
        Timeline::log($application, 'supplement_requested', $msg, $reviewer->id, [
            'missing' => $report['missingDocs'],
            'expired' => $report['expiredDocs'],
        ]);
        $this->notifyApplicant($application, '请补充申请材料', $msg);
        return $review;
    }

    private function recordReview(Application $application, User $reviewer, string $level, string $result, ?string $comment, array $report): Review
    {
        return Review::create([
            'application_id' => $application->id,
            'level' => $level,
            'reviewer_id' => $reviewer->id,
            'result' => $result,
            'comment' => $comment,
            'checked_items' => [
                'checked' => $report['checked'],
                'missing_docs' => $report['missingDocs'],
                'expired_docs' => $report['expiredDocs'],
                'failed_items' => $report['failedItems'],
            ],
        ]);
    }

    private function createOrRestoreDisbursement(Application $application): Disbursement
    {
        $payee = $application->payee();
        return Disbursement::firstOrCreate(
            ['application_id' => $application->id],
            [
                'batch_id' => $application->batch_id,
                'household_id' => $application->household_id,
                'amount' => $application->subsidyAmount(),
                'payee_name' => $payee['name'],
                'payee_account' => $payee['account'],
                'status' => Disbursement::STATUS_LISTED,
            ],
        );
    }

    private function notifyApplicant(Application $application, string $title, ?string $detail): void
    {
        $applicant = $application->applicant;
        if ($applicant) {
            $this->sms->send(
                $applicant,
                MockSmsService::TPL_REVIEW_RESULT,
                "【暖冬补贴】您的申请 {$application->apply_no}：{$title}。" . ($detail ? " {$detail}" : ''),
                $application,
            );
        }
    }

    private function assertRole(User $user, string $role): void
    {
        if ($user->role !== $role) {
            throw new DomainException('forbidden_role', '当前账号无权进行该操作。', [
                'need' => $role, 'actual' => $user->role,
            ]);
        }
    }
}
