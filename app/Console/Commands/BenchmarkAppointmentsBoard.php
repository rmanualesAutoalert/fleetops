<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Queries\AppointmentBoardQuery;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BenchmarkAppointmentsBoard extends Command
{
    private bool $measuring = false;

    /** @var list<string> */
    private array $queries = [];

    protected $signature = 'appointments:benchmark {--samples=200} {--warmup=20} {--session= : Explicit session driver override (file or database)}';

    protected $description = 'Measure the authenticated board HTTP kernel against at least 100k appointments (local only)';

    private function queryCount(): int
    {
        return count($this->queries);
    }

    public function handle(Kernel $kernel, AppointmentBoardQuery $board): int
    {
        if (! app()->environment('local')) {
            $this->error('Run this benchmark only in the local environment.');

            return self::FAILURE;
        }

        $samples = (int) $this->option('samples');
        $warmup = (int) $this->option('warmup');
        if ($samples < 20 || $samples > 10000 || $warmup < 0 || $warmup > 1000) {
            $this->error('Use 20–10000 samples and 0–1000 warmups.');

            return self::FAILURE;
        }

        if ($this->option('session')) {
            if (! in_array($this->option('session'), ['file', 'database'], true)) {
                $this->error('The session override must be file or database.');

                return self::FAILURE;
            }
            config(['session.driver' => $this->option('session')]);
        }

        $total = DB::table('appointments')->count();
        if ($total < 100000) {
            $this->error("Found {$total} appointments; at least 100000 are required. Seed a dedicated local dataset first.");

            return self::FAILURE;
        }

        $user = User::query()->where('role', UserRole::ServiceAdvisor->value)->first();
        if ($user === null) {
            $this->error('Seed a service advisor login before benchmarking.');

            return self::FAILURE;
        }

        $branch = DB::table('appointments')->select('branch_id')->selectRaw('COUNT(*) as total')
            ->groupBy('branch_id')->orderByDesc('total')->first();
        $range = DB::table('appointments')->selectRaw('MIN(scheduled_at) as first_date, MAX(scheduled_at) as last_date')->first();
        $from = substr($range->first_date, 0, 10);
        $to = substr($range->last_date, 0, 10);
        $base = ['branch_id' => $branch->branch_id, 'from' => $from, 'to' => $to];
        $scenarios = [
            'all statuses / full seeded range' => [],
            'booked / full seeded range' => ['status' => 'booked'],
            'contains search / common letter' => ['q' => 'a'],
            'contains search / no match' => ['q' => 'fleetops-no-match-987654321'],
            'page two' => ['page' => 2],
            'single day' => ['from' => $to, 'to' => $to],
        ];

        // Persist a real session. Each request reloads the user through the auth guard.
        $session = app('session')->driver();
        $session->start();
        $session->put(Auth::guard('web')->getName(), $user->id);
        $session->save();
        $cookieName = config('session.cookie');
        $cookie = app('encrypter')->encrypt(
            CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$session->getId(), false,
        );

        DB::listen(function (QueryExecuted $query): void {
            if ($this->measuring) {
                $this->queries[] = $query->sql;
            }
        });

        $this->line('PHP '.PHP_VERSION.'; '.PHP_OS_FAMILY.'; DB '.DB::connection()->getDriverName().'; rows '.$total);
        $this->line('DB version '.DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION).'; busiest branch '.$branch->branch_id.' ('.$branch->total.' appointments); range '.$from.' through '.$to);
        $this->line('Session '.config('session.driver').'; debug '.(config('app.debug') ? 'on' : 'off').'; timezone '.config('app.timezone'));
        $this->line("Warm HTTP kernel + middleware + auth + SQL + JSON + termination; {$warmup} warmups, {$samples} samples/scenario. Excludes network and application bootstrap.");
        $passed = true;
        $summary = [];

        try {
            foreach ($scenarios as $label => $filters) {
                $times = [];
                $maxQueries = 0;
                for ($i = 0; $i < $warmup + $samples; $i++) {
                    Auth::forgetGuards();
                    $request = Request::create('/api/appointments?'.http_build_query(array_merge($base, $filters)), 'GET', [], [$cookieName => $cookie], [], ['HTTP_ACCEPT' => 'application/json']);
                    $this->queries = [];
                    $this->measuring = true;
                    $start = hrtime(true);
                    $response = $kernel->handle($request);
                    $kernel->terminate($request, $response);
                    $elapsed = (hrtime(true) - $start) / 1000000;
                    $this->measuring = false;
                    if ($response->getStatusCode() !== 200) {
                        $this->error('Unexpected HTTP '.$response->getStatusCode().': '.$response->getContent());

                        return self::FAILURE;
                    }
                    if ($i >= $warmup) {
                        $times[] = $elapsed;
                        $maxQueries = max($maxQueries, $this->queryCount());
                    }
                }
                if ($times === []) {
                    throw new \LogicException('No benchmark samples were collected.');
                }
                sort($times);
                $p95 = $times[(int) ceil($samples * 0.95) - 1];
                $passed = $passed && $p95 < 200 && $maxQueries <= 3;
                $summary[] = [$label, $maxQueries, number_format($p95, 2), number_format(max($times), 2)];
                $this->line($label.': '.$maxQueries.' queries, p95 '.number_format($p95, 2).'ms');
            }
        } finally {
            $this->measuring = false;
            $session->getHandler()->destroy($session->getId());
        }

        $this->table(['Scenario', 'Max queries', 'p95 ms', 'Max ms'], $summary);
        foreach ([null, 'booked'] as $status) {
            $query = $board->build((int) $branch->branch_id, $from, $to, $status, '')->limit(51);
            $prefix = DB::connection()->getDriverName() === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ';
            $this->line('EXPLAIN '.($status ?? 'all statuses').': '.json_encode(DB::select($prefix.$query->toSql(), $query->getBindings()), JSON_THROW_ON_ERROR));
        }
        $this->line($passed ? 'PASS: every scenario has <= 3 queries and p95 < 200ms.' : 'FAIL: query or latency budget exceeded.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
