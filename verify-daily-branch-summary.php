<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/*
|--------------------------------------------------------------------------
| Set the branch and date you want to verify
|--------------------------------------------------------------------------
|
| SUMMARY_DATE must use YYYY-MM-DD. The script is read-only: it compares a
| direct service_records calculation with the prepared daily summary row.
|
*/
$branchId = 1;
$summaryDate = '2026-09-04';

if ($branchId < 1) {
    fail('BRANCH ID must be a positive integer.', 2);
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $summaryDate);

if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $summaryDate) {
    fail('SUMMARY DATE must be a real date in YYYY-MM-DD format.', 2);
}

$nextDate = $parsedDate->modify('+1 day')->format('Y-m-d');

$source = DB::table('service_records')
    ->where('branch_id', $branchId)
    ->where('completed_at', '>=', $summaryDate.' 00:00:00')
    ->where('completed_at', '<', $nextDate.' 00:00:00')
    ->selectRaw('COUNT(*) AS jobs, COALESCE(SUM(cost), 0) AS revenue')
    ->first();

$summary = DB::table('daily_branch_summaries')
    ->where('branch_id', $branchId)
    ->where('summary_date', $summaryDate)
    ->select(['jobs', 'revenue', 'updated_at'])
    ->first();

if ($source === null) {
    fail('The direct source calculation returned no result.', 1);
}

$sourceJobs = (int) $source->jobs;
$sourceRevenueCents = amountToCents((string) $source->revenue);

fwrite(STDOUT, PHP_EOL.'DAILY BRANCH SUMMARY VERIFICATION'.PHP_EOL);
fwrite(STDOUT, str_repeat('=', 43).PHP_EOL);
fwrite(STDOUT, sprintf("Branch ID:       %d\n", $branchId));
fwrite(STDOUT, sprintf("Summary date:    %s\n", $summaryDate));
fwrite(STDOUT, sprintf("Source window:   %s 00:00:00 <= completed_at < %s 00:00:00\n", $summaryDate, $nextDate));
fwrite(STDOUT, PHP_EOL."DIRECT SOURCE COMPUTATION (service_records)\n");
fwrite(STDOUT, sprintf("Jobs:            %d\n", $sourceJobs));
fwrite(STDOUT, sprintf("Revenue:         %s\n", formatCents($sourceRevenueCents)));

if ($summary === null) {
    fwrite(STDOUT, PHP_EOL."DAILY SUMMARY (daily_branch_summaries)\n");
    fwrite(STDOUT, "No matching summary row was found.\n");
    fail('FAIL - Run the summary regeneration job, then try again.', 1);
}

$summaryJobs = (int) $summary->jobs;
$summaryRevenueCents = amountToCents((string) $summary->revenue);
$jobsMatch = $sourceJobs === $summaryJobs;
$revenueMatches = $sourceRevenueCents === $summaryRevenueCents;

fwrite(STDOUT, PHP_EOL."DAILY SUMMARY (daily_branch_summaries)\n");
fwrite(STDOUT, sprintf("Jobs:            %d\n", $summaryJobs));
fwrite(STDOUT, sprintf("Revenue:         %s\n", formatCents($summaryRevenueCents)));
fwrite(STDOUT, sprintf("Last rebuilt:    %s\n", $summary->updated_at ?? 'unknown'));
fwrite(STDOUT, PHP_EOL."COMPARISON\n");
fwrite(STDOUT, sprintf("Jobs match:      %s\n", $jobsMatch ? 'YES' : 'NO'));
fwrite(STDOUT, sprintf("Revenue matches: %s\n", $revenueMatches ? 'YES' : 'NO'));

if (! $jobsMatch || ! $revenueMatches) {
    fail('FAIL - The source and daily summary values do not match.', 1);
}

fwrite(STDOUT, PHP_EOL."RESULT: PASS - The daily summary matches the direct source computation.\n".PHP_EOL);

exit(0);

function amountToCents(string $amount): int
{
    $amount = trim($amount);
    $negative = str_starts_with($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '');
    $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

    return $negative ? -$cents : $cents;
}

function formatCents(int $cents): string
{
    $absolute = abs($cents);

    return ($cents < 0 ? '-' : '').sprintf('%d.%02d', intdiv($absolute, 100), $absolute % 100);
}

function fail(string $message, int $exitCode): never
{
    fwrite(STDERR, PHP_EOL.$message.PHP_EOL.PHP_EOL);

    exit($exitCode);
}
