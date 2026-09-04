<?php

namespace Tests\Feature;

use App\Models\Advisor;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class AppointmentBoardTest extends TestCase
{
    use RefreshInMemoryDatabase;

    private function url(array $overrides = []): string
    {
        return '/api/appointments?'.http_build_query(array_merge([
            'from' => '2026-09-01', 'to' => '2026-09-30', 'branch_id' => 1,
        ], $overrides));
    }

    private function createBoard(int $count = 1): array
    {
        $user = User::factory()->serviceAdvisor()->create();
        $branch = Branch::factory()->create();
        $advisor = Advisor::factory()->create(['branch_id' => $branch->id]);
        $advisor->user()->associate($user);
        $advisor->save();
        $customer = Customer::factory()->create(['name' => 'Customer 100%']);
        $vehicle = Vehicle::factory()->create(['customer_id' => $customer->id, 'plate_number' => 'ABC_123']);

        for ($i = 0; $i < $count; $i++) {
            DB::table('appointments')->insert([
                'branch_id' => $branch->id, 'advisor_id' => $advisor->id,
                'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
                'status' => 'booked', 'scheduled_at' => '2026-09-30 23:59:59',
            ]);
        }

        return [$user, $branch, $advisor];
    }

    public function test_page_and_api_require_a_service_advisor(): void
    {
        $this->get('/appointments')->assertRedirect('/login');
        $this->getJson($this->url())->assertUnauthorized();
        $this->getJson('/api/branches')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->get('/appointments')->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
        $this->getJson('/api/branches')->assertForbidden();
        $this->actingAs(User::factory()->serviceAdvisor()->create());
        $this->withoutVite()->get('/appointments')->assertOk();
    }

    public function test_filters_are_grouped_and_dates_include_the_whole_last_day(): void
    {
        [$user, $branch] = $this->createBoard();
        $other = Branch::factory()->create();
        $row = (array) DB::table('appointments')->first();
        unset($row['id']);
        DB::table('appointments')->insert(array_merge($row, ['branch_id' => $other->id]));
        DB::table('appointments')->insert(array_merge($row, ['status' => 'cancelled']));
        DB::table('appointments')->insert(array_merge($row, ['scheduled_at' => '2026-10-01 00:00:00']));
        DB::table('appointments')->insert(array_merge($row, ['scheduled_at' => '2026-08-31 23:59:59']));

        $this->actingAs($user)->getJson($this->url([
            'branch_id' => $branch->id, 'status' => 'booked', 'q' => 'ABC_123',
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Customer 100%');

        $this->getJson($this->url(['status' => 'booked', 'q' => '100%']))->assertJsonCount(1, 'data');
        $this->getJson($this->url(['q' => 'not present']))->assertJsonCount(0, 'data');
    }

    public function test_default_branch_and_pagination_are_stable_without_total_counts(): void
    {
        [$user, $branch] = $this->createBoard(51);
        $this->actingAs($user)->getJson('/api/branches')->assertOk()
            ->assertJsonPath('meta.default_branch_id', $branch->id)->assertJsonCount(1, 'data');
        $this->actingAs($user)->getJson($this->url())->assertOk()
            ->assertJsonPath('meta.branch_id', $branch->id)->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.has_more', true)->assertJsonPath('data.0.id', 1)
            ->assertJsonMissingPath('branches');
        $this->getJson($this->url(['page' => 2]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 51)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_invalid_filters_return_validation_errors(): void
    {
        [$user] = $this->createBoard();
        $this->actingAs($user);
        foreach ([['status' => 'offered'], ['to' => '2026-08-01'], ['from' => 'invalid'], ['branch_id' => null], ['branch_id' => 0], ['page' => 0], ['q' => str_repeat('x', 101)]] as $invalid) {
            $this->getJson($this->url($invalid))->assertUnprocessable();
        }
    }

    public function test_search_deduplicates_both_matches_and_paginates_without_losing_rows(): void
    {
        [$user] = $this->createBoard(60);
        DB::table('customers')->update(['name' => 'ABC_123']);
        $this->actingAs($user)->getJson($this->url(['q' => 'ABC_123']))
            ->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.has_more', true);
        $this->getJson($this->url(['q' => 'ABC_123', 'page' => 2]))
            ->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('data.0.id', 51)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_search_wildcards_and_sql_text_are_literal(): void
    {
        [$user] = $this->createBoard();
        DB::table('customers')->update(['name' => 'Plain customer']);
        DB::table('vehicles')->update(['plate_number' => 'ABCD123']);
        $this->actingAs($user);
        foreach (['ABC_123', '%', "' OR 1=1 --"] as $search) {
            $this->getJson($this->url(['q' => $search]))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_empty_database_returns_empty_options_and_results(): void
    {
        $this->actingAs(User::factory()->serviceAdvisor()->create())
            ->getJson('/api/branches')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.default_branch_id', null);
        // A deleted/unknown positive branch ID is an empty filtered result.
        $this->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_profiling_headers_are_opt_in_and_local_only(): void
    {
        [$user] = $this->createBoard();
        $this->actingAs($user);
        $this->withHeader('X-Board-Profile', '1')->getJson($this->url())
            ->assertOk()->assertHeaderMissing('Server-Timing');

        $this->app->instance('env', 'local');
        $this->getJson($this->url())->assertOk()->assertHeader('X-Board-Query-Count', '1')
            ->assertHeader('Server-Timing');
        $this->withHeader('X-Board-Profile', '0')->getJson($this->url())
            ->assertOk()->assertHeaderMissing('Server-Timing');
    }

    public function test_board_load_never_exceeds_three_queries_including_authentication(): void
    {
        [$user, $branch] = $this->createBoard(60);
        // Array sessions emulate non-SQL session storage; no auth bypass via actingAs.
        $this->withSession([Auth::guard('web')->getName() => $user->id]);
        $queries = [];
        $measuring = false;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$measuring): void {
            if ($measuring) {
                $queries[] = $query->sql;
            }
        });

        $scenarios = [
            'Default board' => [],
            'Status: booked' => ['status' => 'booked'],
            'Keyword: ABC' => ['q' => 'ABC'],
            'Keyword: no match' => ['q' => 'no match'],
            'Page two' => ['page' => 2],
            'Selected branch' => ['branch_id' => $branch->id],
        ];

        fwrite(STDOUT, "\nAppointment SQL counts (including authentication; expected: 2 per request):\n");
        foreach ($scenarios as $scenario => $filters) {
            Auth::forgetGuards();
            $queries = [];
            $measuring = true;
            $response = $this->getJson($this->url($filters));
            $measuring = false;
            fwrite(STDOUT, sprintf("  %-20s %d queries\n", $scenario, count($queries)));
            $response->assertOk();
            $this->assertCount(2, $queries, $scenario."\n".implode("\n", $queries));
            $response->assertJsonMissingPath('branches');
            $this->assertStringNotContainsString('branches', implode("\n", $queries));
        }

        Auth::forgetGuards();
        $queries = [];
        $measuring = true;
        $this->getJson('/api/branches')->assertOk();
        $measuring = false;
        fwrite(STDOUT, sprintf("  Branch bootstrap     %d queries (once per board mount)\n", count($queries)));
        $this->assertCount(2, $queries);
        fwrite(STDOUT, "  Initial load total   4 queries across two authenticated requests\n");
    }
}
