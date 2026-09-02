<?php

namespace Tests\Feature;

use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ServiceRecord;
use App\Models\Vehicle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ServiceRecordQueryMeasurementTest extends TestCase
{
    use RefreshDatabase;

    public function test_measure_index_query_count(): void
    {
        [$branch] = $this->createRecords(10);

        [$response, $queries] = $this->measureQueries(
            fn () => $this->getJson("/api/service-records?branch_id={$branch->id}"),
        );

        $this->printMeasurement('index', $response, $queries);
    }

    public function test_measure_advisor_workload_query_count(): void
    {
        [$branch] = $this->createRecords(8);

        [$response, $queries] = $this->measureQueries(
            fn () => $this->getJson("/api/advisors/workload?branch_id={$branch->id}"),
        );

        $this->printMeasurement('advisorWorkload', $response, $queries);
    }

    public function test_measure_full_history_query_count(): void
    {
        [, $appointment] = $this->createRecords(1);

        [$response, $queries] = $this->measureQueries(
            fn () => $this->getJson("/api/appointments/{$appointment->id}/full-history"),
        );

        $this->printMeasurement('fullHistory', $response, $queries);
    }

    /** @return array{Branch, Appointment} */
    private function createRecords(int $count): array
    {
        $branch = Branch::factory()->create();
        $lastAppointment = null;

        for ($index = 0; $index < $count; $index++) {
            $advisor = Advisor::factory()->create(['branch_id' => $branch->id]);
            $customer = Customer::factory()->create();
            $vehicle = Vehicle::factory()->create(['customer_id' => $customer->id]);
            $lastAppointment = Appointment::factory()->create([
                'branch_id' => $branch->id,
                'advisor_id' => $advisor->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
            ]);

            ServiceRecord::factory()->create([
                'appointment_id' => $lastAppointment->id,
                'branch_id' => $branch->id,
                'advisor_id' => $advisor->id,
                'vehicle_id' => $vehicle->id,
                'completed_at' => now()->subMinutes($index),
            ]);
        }

        return [$branch, $lastAppointment];
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
    private function printMeasurement(string $endpoint, TestResponse $response, array $queries): void
    {
        fwrite(
            STDERR,
            sprintf(
                "\n%s: %d queries (HTTP %d)\n",
                $endpoint,
                count($queries),
                $response->getStatusCode(),
            ),
        );

        $this->assertNotEmpty($queries);
    }
}
