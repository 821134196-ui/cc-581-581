<?php

namespace App\Services;

use App\Models\Application;
use App\Models\StatusLog;

/**
 * 申请进度时间线：所有状态流转统一在此留痕，居民端按时间线展示。
 */
class Timeline
{
    public static function log(
        Application $application,
        string $event,
        string $message,
        ?int $operatorId = null,
        array $context = [],
    ): StatusLog {
        $from = $application->getOriginal('status') ?: $application->status;

        return StatusLog::create([
            'application_id' => $application->id,
            'from_status' => $from,
            'to_status' => $application->status,
            'event' => $event,
            'message' => $message,
            'operator_id' => $operatorId,
            'context' => $context ?: null,
        ]);
    }
}
