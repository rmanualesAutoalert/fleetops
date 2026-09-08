<?php

namespace Tests\Feature;

use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class ServiceRecordQueryTest extends TestCase
{
    use RefreshInMemoryDatabase;

    public function test_index_uses_at_most_five_queries(): void
    {
        [$branch] = $this->createRecords(10);

        [$response, $queries] = $this->measureQueries(
            fn () => $this->getJson("/api/service-records?branch_id={$branch->id}"),
        );

        $response
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonFragment([
                'service_type' => 'Service 0',
                'plate' => 'TEST-0000',
                'customer_name' => 'Customer 0',
                'advisor' => 'Advisor 0',
            ]);

        $this->assertQueryCountAtMost(5, $queries);
    }

    public function test_index_cursor_is_stable_with_tied_timestamps_and_does_not_repeat_rows(): void
    {
        [$branch] = $this->createRecords(60);
        ServiceRecord::query()->where('branch_id', $branch->id)->update([
            'completed_at' => '2026-09-08 12:00:00',
        ]);

        $first = $this->getJson("/api/service-records?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50);
        $cursor = $first->json('meta.next_cursor');
        $firstIds = collect($first->json('data'))->pluck('id');

        $second = $this->getJson('/api/service-records?'.http_build_query([
            'branch_id' => $branch->id,
            'cursor' => $cursor,
        ]))->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.next_cursor', null);
        $secondIds = collect($second->json('data'))->pluck('id');

        $this->assertSame([], $firstIds->intersect($secondIds)->values()->all());
        $this->assertSame(
            ServiceRecord::query()->where('branch_id', $branch->id)->orderByDesc('id')->pluck('id')->all(),
            $firstIds->concat($secondIds)->all(),
        );
    }

    public function test_index_rejects_missing_branch_and_malformed_cursors(): void
    {
        $this->getJson('/api/service-records')->assertUnprocessable();
        $this->getJson('/api/service-records?branch_id=1&cursor=not-a-cursor')->assertUnprocessable();
    }

    public function test_advisor_workload_uses_one_query_and_current_month_aggregates(): void
    {
        [$branch, $appointments] = $this->createRecords(8);
        $firstAppointment = $appointments->first();

        ServiceRecord::factory()->create([
            'appointment_id' => $firstAppointment->id,
            'branch_id' => $branch->id,
            'advisor_id' => $firstAppointment->advisor_id,
            'vehicle_id' => $firstAppointment->vehicle_id,
            'service_type' => 'Old service',
            'cost' => 999,
            'completed_at' => now()->subYear(),
        ]);

        [$response, $queries] = $this->measureQueries(
            fn () => $this->getJson("/api/advisors/workload?branch_id={$branch->id}"),
        );

        $response
            ->assertOk()
            ->assertJsonCount(8)
            ->assertJsonFragment([
                'advisor' => 'Advisor 0',
                'jobs' => 1,
                'revenue' => 100,
            ]);

        $this->assertQueryCountAtMost(1, $queries);
    }

    public function test_full_history_loads_only_response_relations_in_five_queries(): void
    {
        [, $appointments] = $this->createRecords(1);
        $appointment = $appointments->first();

        [$response, $queries] = $this->measureQueries(
            fn () => $this->getJson("/api/appointments/{$appointment->id}/full-history"),
        );

        $response
            ->assertOk()
            ->assertJsonPath('id', $appointment->id)
            ->assertJsonPath('customer', 'Customer 0')
            ->assertJsonPath('plate', 'TEST-0000')
            ->assertJsonPath('advisor', 'Advisor 0')
            ->assertJsonPath('records.0.service_type', 'Service 0')
            ->assertJsonPath('records.0.cost', '100.00');

        $this->assertQueryCountAtMost(5, $queries);
    }

    /**
     * @return array{Branch, Collection<int, Appointment>}
     */
    private function createRecords(int $count): array
    {
        $branch = Branch::factory()->create();
        $appointments = collect();

        for ($index = 0; $index < $count; $index++) {
            $advisor = Advisor::factory()->create([
                'branch_id' => $branch->id,
                'name' => "Advisor {$index}",
            ]);
            $customer = Customer::factory()->create(['name' => "Customer {$index}"]);
            $vehicle = Vehicle::factory()->create([
                'customer_id' => $customer->id,
                'plate_number' => sprintf('TEST-%04d', $index),
            ]);
            $appointment = Appointment::factory()->create([
                'branch_id' => $branch->id,
                'advisor_id' => $advisor->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
            ]);

            ServiceRecord::factory()->create([
                'appointment_id' => $appointment->id,
                'branch_id' => $branch->id,
                'advisor_id' => $advisor->id,
                'vehicle_id' => $vehicle->id,
                'service_type' => "Service {$index}",
                'cost' => 100 + $index,
                'completed_at' => now()->subMinutes($index),
            ]);

            $appointments->push($appointment);
        }

        return [$branch, $appointments];
    }

    /**
     * @return array{TestResponse, list<string>}
     */
    private function measureQueries(callable $request): array
    {
        $queries = [];
        $measuring = true;

        DB::listen(function (QueryExecuted $query) use (&$queries, &$measuring): void {
            if ($measuring) {
                $queries[] = $query->sql;
            }
        });

        $response = $request();
        $measuring = false;

        return [$response, $queries];
    }

    /** @param list<string> $queries */
    private function assertQueryCountAtMost(int $maximum, array $queries): void
    {
        $this->assertLessThanOrEqual(
            $maximum,
            count($queries),
            "Expected at most {$maximum} queries, but received:\n".implode("\n", $queries),
        );
    }
}
