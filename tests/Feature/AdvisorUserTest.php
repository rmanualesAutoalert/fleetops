<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Advisor;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshInMemoryDatabase;
use Tests\TestCase;

class AdvisorUserTest extends TestCase
{
    use RefreshInMemoryDatabase;

    public function test_local_seed_links_an_existing_advisor_and_the_user_can_log_in(): void
    {
        $branch = Branch::factory()->create();
        $advisor = Advisor::factory()->create(['branch_id' => $branch->id]);
        $this->app->instance('env', 'local');

        $this->seed(UserSeeder::class);

        $user = User::query()->where('email', 'advisor@fleetops.test')->firstOrFail();
        $this->assertSame(UserRole::ServiceAdvisor, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertTrue($user->advisor->is($advisor));
        $this->assertTrue($advisor->fresh()->user->is($user));
        $this->assertSame($branch->id, $user->advisor->branch_id);
        $this->assertDatabaseCount('advisors', 1);

        $this->app->instance('env', 'testing');

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_seed_links_an_existing_login_without_resetting_credentials_and_is_repeatable(): void
    {
        $user = User::factory()->serviceAdvisor()->create([
            'email' => 'advisor@fleetops.test',
            'password' => 'my-existing-password',
        ]);
        $originalPassword = $user->password;
        $this->app->instance('env', 'local');

        $this->seed(UserSeeder::class);
        $advisorId = $user->fresh()->advisor->id;
        $this->seed(UserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('advisors', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertSame($advisorId, $user->fresh()->advisor->id);
    }

    public function test_seed_does_not_create_a_demo_login_outside_local(): void
    {
        $this->app->instance('env', 'production');
        $this->app->make(UserSeeder::class)->run();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('advisors', 0);
        $this->assertDatabaseCount('branches', 0);
    }

    public function test_deleting_a_login_preserves_the_advisor(): void
    {
        $this->app->instance('env', 'local');
        $this->seed(UserSeeder::class);
        $user = User::query()->firstOrFail();
        $advisor = $user->advisor;

        $user->delete();

        $this->assertDatabaseHas('advisors', ['id' => $advisor->id, 'user_id' => null]);
    }

    public function test_a_user_cannot_be_assigned_to_two_advisors(): void
    {
        $this->app->instance('env', 'local');
        $this->seed(UserSeeder::class);
        $advisor = Advisor::query()->firstOrFail();
        $otherAdvisor = $advisor->replicate();

        $this->expectException(QueryException::class);
        $otherAdvisor->save();
    }
}
