<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer Token 认证（本地演示）：
 * Authorization: Bearer <users.api_token>
 */
class AuthenticateToken
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return response()->json(['message' => '未登录', 'error_code' => 'unauthenticated'], 401);
        }

        $user = User::where('api_token', hash('sha256', $token))->first();
        if (! $user) {
            return response()->json(['message' => '登录已失效，请重新登录', 'error_code' => 'unauthenticated'], 401);
        }

        if ($role && $user->role !== $role) {
            return response()->json(['message' => '无权访问该功能', 'error_code' => 'forbidden_role'], 403);
        }

        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
