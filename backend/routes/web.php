<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// 前端 SPA：构建产物在 public/spa，由 Laravel 一并托管（一条命令启动）。
Route::view('/spa/{path?}', 'spa')->where('path', '.*');

Route::get('/', function () {
    return redirect('/spa/');
});

// 健康检查（bootstrap/app.php 已注册 /up）之外，直接访问 / 时跳到 SPA。
Route::get('/storage-version', function () {
    return response()->json(['spa_exists' => File::exists(public_path('spa/index.html'))]);
});
