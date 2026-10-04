<?php

namespace App\Services;

use App\Models\Application;
use App\Models\SmsMessage;
use App\Models\User;

/**
 * 本地模拟短信服务：不接入运营商，直接落库 sms_messages。
 * sim:worker 命令会把 queued 短信置为 sent，模拟异步发送。
 */
class MockSmsService
{
    public const TPL_ACCEPTED = 'application_accepted';
    public const TPL_SUPPLEMENT = 'supplement_requested';
    public const TPL_REVIEW_RESULT = 'review_result';
    public const TPL_PAID = 'subsidy_paid';
    public const TPL_FROZEN = 'application_frozen';
    public const TPL_MERGED = 'application_merged';

    public function send(User $resident, string $template, string $content, ?Application $application = null): SmsMessage
    {
        if (! $resident->phone) {
            // 无手机号的测试居民：仍落库一条记录便于断言
        }

        return SmsMessage::create([
            'phone' => (string) ($resident->phone ?? ''),
            'template' => $template,
            'content' => $content,
            'application_id' => $application?->id,
            'status' => SmsMessage::STATUS_QUEUED,
        ]);
    }

    /**
     * 处理队列中的短信（由 sim:worker 周期调用）。
     */
    public function flushQueue(int $limit = 100): int
    {
        $count = 0;
        SmsMessage::where('status', SmsMessage::STATUS_QUEUED)
            ->limit($limit)
            ->get()
            ->each(function (SmsMessage $sms) use (&$count) {
                // 模拟运营商：99% 成功；本地演示用，失败可重试
                $sms->update(['status' => SmsMessage::STATUS_SENT]);
                $count++;
            });

        return $count;
    }
}
