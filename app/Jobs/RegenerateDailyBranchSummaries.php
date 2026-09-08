<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegenerateDailyBranchSummaries implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public int $backoff = 30;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        $startedAt = hrtime(true);
        $sourceRows = 0;
        $summaryRows = 0;
        $latestSummaryDate = null;

        DB::transaction(function () use (&$sourceRows, &$summaryRows, &$latestSummaryDate): void {
            $summaries = DB::table('service_records')
                ->selectRaw('branch_id, DATE(completed_at) as summary_date, COUNT(*) as jobs, SUM(cost) as revenue')
                ->groupBy('branch_id')
                ->groupByRaw('DATE(completed_at)')
                ->orderBy('branch_id')
                ->orderBy('summary_date')
                ->get();

            $sourceRows = (int) $summaries->sum(fn (object $summary): int => (int) $summary->jobs);
            $summaryRows = $summaries->count();
            $latestSummaryDate = $summaries->max('summary_date');
            $now = now();

            DB::table('daily_branch_summaries')->delete();

            $summaries
                ->map(fn (object $summary): array => [
                    'branch_id' => $summary->branch_id,
                    'summary_date' => $summary->summary_date,
                    'jobs' => $summary->jobs,
                    'revenue' => $summary->revenue,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->chunk(500)
                ->each(fn ($chunk) => DB::table('daily_branch_summaries')->insert($chunk->all()));
        });

        Log::info('Daily branch summaries regenerated.', [
            'event' => 'daily_branch_summaries.regenerated',
            'source_rows' => $sourceRows,
            'summary_rows' => $summaryRows,
            'latest_summary_date' => $latestSummaryDate,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ]);
    }

    public function uniqueId(): string
    {
        return 'daily-branch-summaries';
    }
}
