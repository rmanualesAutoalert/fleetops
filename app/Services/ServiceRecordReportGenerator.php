<?php

namespace App\Services;

use App\Contracts\ReportGenerator;
use Illuminate\Support\Facades\DB;

class ServiceRecordReportGenerator implements ReportGenerator
{
    public function generate(): array
    {
        $dailySummaries = DB::table('daily_branch_summaries')
            ->select(['branch_id', 'summary_date', 'jobs', 'revenue'])
            ->where('summary_date', '>=', '2026-01-01')
            ->orderBy('branch_id')
            ->orderBy('summary_date')
            ->get()
            ->all();

        $monthly = [];

        foreach ($dailySummaries as $summary) {
            $month = substr((string) $summary->summary_date, 0, 7);
            $key = $summary->branch_id.'|'.$month;
            $monthly[$key] ??= [
                'branch_id' => (int) $summary->branch_id,
                'month' => $month,
                'jobs' => 0,
                'revenue_cents' => 0,
            ];
            $monthly[$key]['jobs'] += (int) $summary->jobs;
            $monthly[$key]['revenue_cents'] += $this->toCents((string) $summary->revenue);
        }

        return array_values(array_map(fn (array $summary): array => [
            'branch_id' => $summary['branch_id'],
            'month' => $summary['month'],
            'jobs' => $summary['jobs'],
            'revenue' => $this->formatCents($summary['revenue_cents']),
        ], $monthly));
    }

    private function toCents(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function formatCents(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '').sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
    }
}
