<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    /** 短信收件箱（本地模拟）：居民只看自己手机号，工作人员看全部 */
    public function index(Request $request)
    {
        $user = $request->user();

        $messages = SmsMessage::query()
            ->when($user->isResident(), fn ($q) => $q->where('phone', $user->phone))
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (SmsMessage $m) => [
                'id' => $m->id,
                'phone' => $m->phone,
                'content' => $m->content,
                'tag' => $m->tag,
                'at' => $m->created_at->toDateTimeString(),
            ]);

        return response()->json(['data' => $messages]);
    }
}
