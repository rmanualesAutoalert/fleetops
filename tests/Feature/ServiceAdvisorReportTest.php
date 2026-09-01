<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAdvisorReportTest extends TestCase
{
    use RefreshDatabase;

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
}
