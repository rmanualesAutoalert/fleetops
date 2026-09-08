<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheckService
{
    /** @return array{status: string, checks: array<string, array{status: string, duration_ms: float}>} */
    public function check(): array
    {
        $redisConnection = (string) config('queue.connections.redis.connection', 'default');
        $queueName = (string) config('queue.connections.redis.queue', 'default');
        $checks = [
            'database' => $this->run(fn () => DB::select('SELECT 1')),
            'redis' => $this->run(fn () => Redis::connection($redisConnection)->command('ping')),
            'queue' => $this->run(fn () => Queue::connection('redis')->size($queueName)),
        ];
        $healthy = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array{status: string, duration_ms: float} */
    private function run(callable $check): array
    {
        $startedAt = hrtime(true);

        try {
            $check();

            return [
                'status' => 'ok',
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ];
        } catch (Throwable) {
            return [
                'status' => 'failed',
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ];
        }
    }
}
