<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 业务规则异常：返回 422 与中文错误码，前端直接展示 message。
 */
class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'context' => $this->context,
        ], 422);
    }

    /** 业务规则拒绝不是系统故障，不写入 ERROR 日志 */
    public function shouldReport(): bool
    {
        return false;
    }
}
