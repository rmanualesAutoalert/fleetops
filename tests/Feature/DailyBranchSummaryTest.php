<?php

namespace Tests\Feature;

use App\Jobs\RegenerateDailyBranchSummaries;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class DailyBranchSummaryTest extends TestCase
{
    use RefreshInMemoryDatabase;

    public function test_summary_matches_an_independent_source_calculation_for_a_branch_and_date(): void
    {
        [$branch, $appointment] = $this->createAppointment();
        [$otherBranch, $otherAppointment] = $this->createAppointment();

        $this->createRecord($appointment, '2026-09-08 00:00:00', '10.25');
        $recordToCorrect = $this->createRecord($appointment, '2026-09-08 23:59:59', '20.30');
        $this->createRecord($appointment, '2026-09-09 00:00:00', '99.00');
        $this->createRecord($otherAppointment, '2026-09-08 12:00:00', '500.00');

        (new RegenerateDailyBranchSummaries)->handle();

        $this->assertSummaryMatchesSource($branch, '2026-09-08');

        $recordToCorrect->update(['cost' => '30.35']);
        (new RegenerateDailyBranchSummaries)->handle();

        $this->assertSummaryMatchesSource($branch, '2026-09-08');
        $this->assertDatabaseCount('daily_branch_summaries', 3);
        $this->assertDatabaseHas('daily_branch_summaries', [
            'branch_id' => $otherBranch->id,
            'summary_date' => '2026-09-08',
            'jobs' => 1,
            'revenue' => 500,
        ]);
    }

    public function test_command_dispatches_the_rebuild_to_the_reports_redis_queue(): void
    {
        Queue::fake();

        $this->artisan('reports:regenerate-daily-branch-summaries')
            ->expectsOutput('Daily branch summary regeneration was queued on redis:reports.')
            ->assertSuccessful();

        Queue::assertPushed(RegenerateDailyBranchSummaries::class, fn (RegenerateDailyBranchSummaries $job): bool => $job->connection === 'redis'
            && $job->queue === 'reports');
    }

    private function assertSummaryMatchesSource(Branch $branch, string $date): void
    {
        $nextDate = Carbon::parse($date)->addDay()->toDateString();
        $source = ServiceRecord::query()
            ->where('branch_id', $branch->id)
            ->where('completed_at', '>=', $date.' 00:00:00')
            ->where('completed_at', '<', $nextDate.' 00:00:00')
            ->selectRaw('COUNT(*) as jobs, SUM(CAST(ROUND(cost * 100, 0) AS INTEGER)) as revenue_cents')
            ->firstOrFail();

        $summary = DB::table('daily_branch_summaries')
            ->select(['jobs'])
            ->selectRaw('CAST(ROUND(revenue * 100, 0) AS INTEGER) as revenue_cents')
            ->where('branch_id', $branch->id)
            ->where('summary_date', $date)
            ->first();

        $this->assertNotNull($summary);
        $this->assertSame((int) $source->jobs, (int) $summary->jobs);
        $this->assertSame((int) $source->revenue_cents, (int) $summary->revenue_cents);
    }

    /** @return array{Branch, Appointment} */
    private function createAppointment(): array
    {
        $branch = Branch::factory()->create();
        $advisor = Advisor::factory()->create(['branch_id' => $branch->id]);
        $customer = Customer::factory()->create();
        $vehicle = Vehicle::factory()->create(['customer_id' => $customer->id]);
        $appointment = Appointment::factory()->create([
            'branch_id' => $branch->id,
            'advisor_id' => $advisor->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
        ]);

        return [$branch, $appointment];
    }

    private function createRecord(Appointment $appointment, string $completedAt, string $cost): ServiceRecord
    {
        return ServiceRecord::factory()->create([
            'appointment_id' => $appointment->id,
            'branch_id' => $appointment->branch_id,
            'advisor_id' => $appointment->advisor_id,
            'vehicle_id' => $appointment->vehicle_id,
            'completed_at' => $completedAt,
            'cost' => $cost,
        ]);
    }
}
