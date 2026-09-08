<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class ServiceAdvisorReportTest extends TestCase
{
    use RefreshInMemoryDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('reports.services.index'))
            ->assertRedirect(route('login'));
    }

    public function test_non_service_advisor_cannot_access_service_reports(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Customer,
        ]);

        $this->actingAs($user)
            ->get(route('reports.services.index'))
            ->assertForbidden();
    }

    public function test_service_advisor_can_access_service_reports(): void
    {
        $advisor = User::factory()->serviceAdvisor()->create();

        $this->actingAs($advisor)
            ->get(route('reports.services.index'))
            ->assertOk()
            ->assertJson(['data' => []]);
    }

    public function test_report_reads_the_daily_summary_and_never_aggregates_service_records(): void
    {
        $advisor = User::factory()->serviceAdvisor()->create();
        $branch = Branch::factory()->create();
        DB::table('daily_branch_summaries')->insert([
            ['branch_id' => $branch->id, 'summary_date' => '2026-09-01', 'jobs' => 2, 'revenue' => 10.25],
            ['branch_id' => $branch->id, 'summary_date' => '2026-09-02', 'jobs' => 3, 'revenue' => 20.30],
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($advisor)
            ->get(route('reports.services.index'))
            ->assertOk()
            ->assertExactJson(['data' => [[
                'branch_id' => $branch->id,
                'month' => '2026-09',
                'jobs' => 5,
                'revenue' => '30.55',
            ]]]);

        $this->assertNotEmpty($queries);
        $this->assertStringNotContainsString('service_records', strtolower(implode("\n", $queries)));
        $this->assertLessThanOrEqual(2, count($queries));
    }

    public function test_report_logs_duration_and_propagates_the_request_id(): void
    {
        $advisor = User::factory()->serviceAdvisor()->create();
        $requestId = 'req-test-123';
        Log::shouldReceive('channel')->once()->with('reports')->andReturnSelf();
        Log::shouldReceive('info')->once()->with(
            'Report request completed.',
            Mockery::on(fn (array $context): bool => $context['event'] === 'report.request.completed'
                && $context['request_id'] === $requestId
                && $context['status'] === 200
                && is_float($context['duration_ms'])),
        );

        $this->actingAs($advisor)
            ->withHeader('X-Request-ID', $requestId)
            ->get(route('reports.services.index'))
            ->assertOk()
            ->assertHeader('X-Request-ID', $requestId);
    }
}
