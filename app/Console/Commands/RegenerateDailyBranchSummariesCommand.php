<?php

namespace App\Console\Commands;

use App\Jobs\RegenerateDailyBranchSummaries;
use Illuminate\Console\Command;

class RegenerateDailyBranchSummariesCommand extends Command
{
    protected $signature = 'reports:regenerate-daily-branch-summaries';

    protected $description = 'Queue an atomic rebuild of the daily branch report summaries';

    public function handle(): int
    {
        RegenerateDailyBranchSummaries::dispatch();

        $this->info('Daily branch summary regeneration was queued on redis:reports.');

        return self::SUCCESS;
    }
}
