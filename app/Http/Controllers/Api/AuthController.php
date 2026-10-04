<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'idcard' => ['nullable', 'string', 'max:18'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['nullable', Rule::in([User::ROLE_RESIDENT, User::ROLE_COMMUNITY, User::ROLE_STREET])],
            'employee_no' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'idcard' => $data['idcard'] ?? null,
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? User::ROLE_RESIDENT,
            'employee_no' => $data['employee_no'] ?? null,
            'is_household_head' => false,
        ]);

        return response()->json(['token' => $this->issueToken($user), 'user' => $this->userPayload($user)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => '邮箱或密码错误', 'error_code' => 'bad_credentials'], 422);
        }

        return response()->json(['token' => $this->issueToken($user), 'user' => $this->userPayload($user)]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $user->forceFill(['api_token' => null])->save();
        return response()->json(['message' => '已退出']);
    }

    private function issueToken(User $user): string
    {
        $plain = Str::random(48);
        $user->forceFill(['api_token' => hash('sha256', $plain)])->save();
        return $plain;
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'role_label' => [
                User::ROLE_RESIDENT => '居民',
                User::ROLE_COMMUNITY => '社区初审员',
                User::ROLE_STREET => '街道复核员',
            ][$user->role] ?? $user->role,
            'employee_no' => $user->employee_no,
            'household_id' => $user->household_id,
        ];
    }
}
