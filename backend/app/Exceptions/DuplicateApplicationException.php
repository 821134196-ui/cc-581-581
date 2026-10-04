<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DuplicateApplicationException extends HttpException
{
    /**
     * @param array<int,array<string,mixed>> $existing
     */
    public function __construct(public readonly array $existing, string $message)
    {
        parent::__construct(409, $message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'DUPLICATE_IN_HOUSEHOLD',
            'existing_applications' => $this->existing,
        ], 409);
    }
}
