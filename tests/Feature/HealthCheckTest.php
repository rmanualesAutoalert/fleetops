<?php

namespace Tests\Feature;

use App\Services\HealthCheckService;
use Mockery;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_healthz_returns_ok_when_all_dependencies_are_available(): void
    {
        $this->app->instance(HealthCheckService::class, $this->healthService('ok'));

        $this->getJson('/healthz')
            ->assertOk()
            ->assertExactJson($this->healthResult('ok'));
    }

    public function test_healthz_returns_a_sanitized_service_unavailable_response(): void
    {
        $this->app->instance(HealthCheckService::class, $this->healthService('degraded'));

        $this->getJson('/healthz')
            ->assertServiceUnavailable()
            ->assertExactJson($this->healthResult('degraded'))
            ->assertDontSee('connection refused');
    }

    private function healthService(string $status): HealthCheckService
    {
        $service = Mockery::mock(HealthCheckService::class);
        $service->shouldReceive('check')->once()->andReturn($this->healthResult($status));

        return $service;
    }

    /** @return array{status: string, checks: array<string, array{status: string, duration_ms: float}>} */
    private function healthResult(string $status): array
    {
        return [
            'status' => $status,
            'checks' => [
                'database' => ['status' => 'ok', 'duration_ms' => 1.0],
                'redis' => ['status' => $status === 'ok' ? 'ok' : 'failed', 'duration_ms' => 1.0],
                'queue' => ['status' => 'ok', 'duration_ms' => 1.0],
            ],
        ];
    }
}
