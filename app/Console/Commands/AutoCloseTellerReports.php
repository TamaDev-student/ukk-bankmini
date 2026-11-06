<?php

namespace App\Console\Commands;

use App\Services\DailyReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoCloseTellerReports extends Command
{
    protected $signature = 'bank:auto-close-teller-reports';

    protected $description = 'Create unverified end-of-day cash reports for active tellers';

    public function handle(DailyReportService $reportService): int
    {
        $closedCount = $reportService->autoCloseForDate(
            Carbon::today('Asia/Jakarta')
        );

        $this->info("Automatic cash closure created for {$closedCount} teller(s).");

        return self::SUCCESS;
    }
}
