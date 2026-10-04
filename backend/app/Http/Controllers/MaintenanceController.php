<?php

namespace App\Http\Controllers;

use App\Services\ApplicationService;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function __construct(private readonly ApplicationService $applications)
    {
    }

    /** 扫描材料过期：标记 expired 并把在途申请退回补件（模拟每日定时任务，也可手动触发） */
    public function sweepExpired(Request $request)
    {
        $result = $this->applications->sweepExpiredDocuments();
        return response()->json(['data' => $result]);
    }
}
