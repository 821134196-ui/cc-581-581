<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 每日扫描材料过期（本地可用 php artisan schedule:work 一条命令常驻）
Schedule::command('subsidy:sweep-expired')->dailyAt('08:00');
