<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        $token = $request->bearerToken();
        $user = $token ? User::where('api_token', $token)->first() : null;

        // 无令牌时回退默认 guard（API 请求无 session 时为 null；测试 actingAs 走此通道）
        $user ??= $request->user();

        if (!$user) {
            return response()->json(['message' => '未登录或令牌无效。'], 401);
        }

        if ($role !== null && $user->role !== $role) {
            return response()->json(['message' => "该功能仅 {$role} 角色可用。"], 403);
        }

        $request->setUserResolver(fn () => $user);
        app()->instance(User::class, $user);

        return $next($request);
    }
}
