<?php

namespace App\Services;

use App\Models\SmsMessage;
use App\Models\User;

/**
 * 本地短信服务模拟：所有短信落库 sms_messages，
 * 前端「短信收件箱」可查看，不接入任何外部通道。
 */
class SmsService
{
    public function send(User|string $to, string $content, string $tag = ''): SmsMessage
    {
        $phone = $to instanceof User ? $to->phone : $to;

        return SmsMessage::create([
            'phone' => $phone,
            'content' => $content,
            'tag' => $tag,
        ]);
    }
}
