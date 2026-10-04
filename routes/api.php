<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchAdminController;
use App\Http\Controllers\Api\DisbursementController;
use App\Http\Controllers\Api\PaymentSimController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

/*
| 居民端
*/
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth.token');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth.token');
});

Route::middleware('auth.token')->group(function () {
    // 批次公开信息（规则快照、受理窗口）
    Route::get('batches', [ApplicationController::class, 'batches']);
    Route::get('batches/{batch}', [ApplicationController::class, 'batchDetail']);

    // 居民：我的申请
    Route::get('my/applications', [ApplicationController::class, 'myApplications']);
    Route::get('my/applications/{application}', [ApplicationController::class, 'show']);
    Route::post('my/applications', [ApplicationController::class, 'store']);
    Route::post('my/applications/{application}/supplement', [ApplicationController::class, 'supplement']);
    Route::post('my/applications/{application}/appeal', [ApplicationController::class, 'appeal']);
    Route::post('my/applications/{application}/payee', [ApplicationController::class, 'changePayee']);
    Route::get('my/applications/{application}/timeline', [ApplicationController::class, 'timeline']);
});

/*
| 社区 / 街道工作台
*/
Route::middleware(['auth.token'])->prefix('admin')->group(function () {
    // 批次与规则（街道管理批次；社区只读）
    Route::get('batches', [BatchAdminController::class, 'index']);
    Route::post('batches', [BatchAdminController::class, 'store']);
    Route::put('batches/{batch}/rule', [BatchAdminController::class, 'updateRule']);
    Route::post('batches/{batch}/freeze-rules', [BatchAdminController::class, 'freezeRules']);

    // 审核队列与操作
    Route::get('applications', [ReviewController::class, 'queue']);
    Route::get('applications/{application}', [ReviewController::class, 'show']);
    Route::post('applications/{application}/community-review', [ReviewController::class, 'communityReview']);
    Route::post('applications/{application}/street-review', [ReviewController::class, 'streetReview']);
    Route::post('applications/{application}/reverify', [ReviewController::class, 'reverify']);
    Route::post('applications/{application}/appeals/{followUp}', [ReviewController::class, 'handleAppeal']);

    // 发放清单
    Route::get('disbursements', [DisbursementController::class, 'index']);
    Route::post('disbursements/issue', [DisbursementController::class, 'issue']);
});

/*
| 本地模拟支付服务回调（支付服务 → 补贴系统）
*/
Route::post('sim/payment/receipt', [PaymentSimController::class, 'receipt']);
Route::get('sim/sms', [PaymentSimController::class, 'smsInbox']);
