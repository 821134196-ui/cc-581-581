<?php

namespace App\Console\Commands;

use App\Services\ApplicationService;
use Illuminate\Console\Command;

class SweepExpiredDocuments extends Command
{
    protected $signature = 'subsidy:sweep-expired';

    protected $description = '扫描已过期材料，标记并把受影响申请退回补件';

    public function handle(ApplicationService $service): int
    {
        $result = $service->sweepExpiredDocuments();
        $this->info("标记过期材料 {$result['marked']} 份，退回补件申请 {$result['returned']} 件。");
        return self::SUCCESS;
    }
}
