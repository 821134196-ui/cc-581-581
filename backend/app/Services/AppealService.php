<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AppealService
{
    public function __construct(private readonly SmsService $sms)
    {
    }

    /**
     * 居民申诉：沿原申请跟进，不新建申请、不换批次。
     */
    public function create(Application $application, User $user, string $reason): Appeal
    {
        abort_if($application->applicant_user_id !== $user->id, 403,
            '只能对本人申请提交申诉。');
        abort_if($application->status === 'merged', 422,
            '该申请已并入同户其他申请，请在并入后的原申请上申诉。');

        $appeal = $application->appeals()->create([
            'user_id' => $user->id,
            'reason' => $reason,
            'status' => 'pending',
        ]);
        ApplicationEvent::log($application, 'appeal_submitted', $user,
            "居民提交申诉：{$reason}");

        return $appeal;
    }

    public function handle(Appeal $appeal, User $handler, bool $accepted, ?string $reply): Appeal
    {
        abort_unless($handler->isStreet() || $handler->isCommunity(), 403, '仅工作人员可处理申诉。');

        return DB::transaction(function () use ($appeal, $handler, $accepted, $reply) {
            $appeal->update([
                'status' => $accepted ? 'accepted' : 'rejected',
                'handler_id' => $handler->id,
                'reply' => $reply,
                'handled_at' => now(),
            ]);

            $application = $appeal->application;
            if ($accepted) {
                // 申诉成立：按申请当前所处环节退回相应审核队列，沿原申请继续
                $target = match ($application->status) {
                    'community_rejected' => 'submitted',
                    'street_rejected' => 'submitted',
                    default => $application->status,
                };
                if ($target !== $application->status) {
                    $application->update(['status' => $target]);
                }
                ApplicationEvent::log($application, 'appeal_accepted', $handler,
                    "申诉成立（{$appeal->reason}），申请按原批次规则重新审核。回复：{$reply}");
            } else {
                ApplicationEvent::log($application, 'appeal_rejected', $handler,
                    "申诉未成立。回复：{$reply}");
            }

            $this->sms->send($application->applicant,
                $accepted
                    ? '【社区取暖补贴】您的申诉已受理成立，申请按原批次重新审核，请关注进度。'
                    : '【社区取暖补贴】您的申诉已处理，结果为未成立，可查看回复说明。',
                $accepted ? 'appeal_ok' : 'appeal_no');

            return $appeal->fresh();
        });
    }
}
