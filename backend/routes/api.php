<?php

use App\Http\Controllers\AppealController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SmsController;
use Illuminate\Support\Facades\Route;

// 认证
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// 银行回执回调：模拟银行服务器调用，不走登录鉴权
Route::post('/bank/receipts', [PaymentController::class, 'receipt']);

Route::middleware('auth.token')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/sms', [SmsController::class, 'index']);

    // 低温预警批次
    Route::get('/batches', [BatchController::class, 'index']);
    Route::get('/batches/{batch}', [BatchController::class, 'show']);
    Route::post('/batches', [BatchController::class, 'store'])
        ->middleware('auth.token:street');
    Route::post('/batches/{batch}/end-alert', [BatchController::class, 'endAlert'])
        ->middleware('auth.token:street');

    // 居民申请
    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/my/applications', [ApplicationController::class, 'myIndex']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    Route::post('/applications/{application}/documents', [ApplicationController::class, 'submitDocument']);

    // 申诉（沿原申请）
    Route::post('/applications/{application}/appeals', [AppealController::class, 'store']);
    Route::get('/appeals/pending', [AppealController::class, 'pendingList']);
    Route::post('/appeals/{appeal}/handle', [AppealController::class, 'handle']);

    // 收款人变更与重新核验
    Route::post('/applications/{application}/payee-change', [PayeeController::class, 'change']);
    Route::get('/payee-changes/pending', [PayeeController::class, 'pendingList']);
    Route::post('/payee-changes/{payeeChangeRequest}/verify', [PayeeController::class, 'verify']);

    // 社区初审
    Route::get('/community/queue', [ReviewController::class, 'communityQueue'])
        ->middleware('auth.token:community');
    Route::post('/applications/{application}/community-review', [ReviewController::class, 'communityReview'])
        ->middleware('auth.token:community');

    // 街道复核、冻结、发放
    Route::get('/street/queue', [ReviewController::class, 'streetQueue'])
        ->middleware('auth.token:street');
    Route::post('/applications/{application}/street-review', [ReviewController::class, 'streetReview'])
        ->middleware('auth.token:street');
    Route::post('/applications/{application}/freeze', [ReviewController::class, 'freeze'])
        ->middleware('auth.token:street');
    Route::get('/batches/{batch}/roster', [PaymentController::class, 'roster'])
        ->middleware('auth.token:street');
    Route::post('/payments/{payment}/payout', [PaymentController::class, 'payout'])
        ->middleware('auth.token:street');
    Route::post('/payments/{payment}/simulate-receipt', [PaymentController::class, 'simulateBankReceipt'])
        ->middleware('auth.token:street');

    // 定时维护（街道触发；生产由调度器执行）
    Route::post('/maintenance/sweep-expired', [MaintenanceController::class, 'sweepExpired'])
        ->middleware('auth.token:street');
});
