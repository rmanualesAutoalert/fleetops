<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class BenchmarkAppointmentsHttp extends Command
{
    protected $signature = 'appointments:benchmark-http {--url=http://fleetops.test} {--samples=200} {--warmup=20} {--scenario=all : all, board, full-range, booked, search, no-match, page-two}';

    protected $description = 'Measure real authenticated local HTTP latency, including bootstrap and complete response transfer';

    public function handle(): int
    {
        $url = rtrim((string) $this->option('url'), '/');
        $parts = parse_url($url);
        // Never send the locally signed session to an arbitrary host or follow redirects.
        if (! app()->environment('local') || ! is_array($parts)
            || ! in_array($parts['host'] ?? '', ['fleetops.test', 'localhost', '127.0.0.1'], true)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! empty($parts['path'])) {
            $this->error('Use a local environment and the origin of fleetops.test, localhost or 127.0.0.1.');

            return self::FAILURE;
        }
        $samples = (int) $this->option('samples');
        $warmup = (int) $this->option('warmup');
        if ($samples < 20 || $samples > 10000 || $warmup < 0 || $warmup > 1000) {
            $this->error('Use 20–10000 samples and 0–1000 warmups.');

            return self::FAILURE;
        }
        $count = DB::table('appointments')->count();
        $user = User::query()->where('email', 'advisor@fleetops.test')->where('role', UserRole::ServiceAdvisor->value)->first();
        if ($count < 100000 || $user === null) {
            $this->error('Requires at least 100k appointments and the seeded service-advisor login.');

            return self::FAILURE;
        }
        $branch = DB::table('appointments')->select('branch_id')->selectRaw('COUNT(*) as total')
            ->groupBy('branch_id')->orderByDesc('total')->first();
        $range = DB::table('appointments')->selectRaw('MIN(scheduled_at) as first_date, MAX(scheduled_at) as last_date')->first();
        $base = ['branch_id' => $branch->branch_id, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->endOfMonth()->toDateString(), 'page' => 1];
        $full = ['from' => substr($range->first_date, 0, 10), 'to' => substr($range->last_date, 0, 10)];
        $scenarios = [
            'board' => [], 'full-range' => $full, 'booked' => ['status' => 'booked'],
            'search' => [...$full, 'q' => 'a'], 'no-match' => [...$full, 'q' => 'fleetops-no-match-987654321'],
            'page-two' => ['page' => 2],
        ];
        $selected = (string) $this->option('scenario');
        if ($selected !== 'all') {
            if (! array_key_exists($selected, $scenarios)) {
                $this->error('Unknown scenario.');

                return self::FAILURE;
            }
            $scenarios = [$selected => $scenarios[$selected]];
        }

        $session = app('session')->driver();
        $session->start();
        $session->put(Auth::guard('web')->getName(), $user->id);
        $session->save();
        $cookieName = config('session.cookie');
        $cookie = app('encrypter')->encrypt(CookieValuePrefix::create($cookieName, app('encrypter')->getKey()).$session->getId(), false);
        $client = Http::acceptJson()->withHeaders(['Cookie' => $cookieName.'='.rawurlencode($cookie), 'X-Board-Profile' => '1', 'Cache-Control' => 'no-cache'])
            ->withOptions(['allow_redirects' => false, 'proxy' => ''])->connectTimeout(5)->timeout(15);
        $this->line("Origin {$url}; {$count} appointments; branch {$branch->branch_id}; {$warmup} warmups + {$samples} measured requests per scenario.");
        $this->line('Real sequential HTTP, full response body. Includes network, PHP/Laravel bootstrap and middleware; excludes browser rendering/debounce.');
        $summary = [];
        $passed = true;
        try {
            $branchResponse = $client->get($url.'/api/branches');
            if ($branchResponse->status() !== 200 || $branchResponse->header('X-Board-Query-Count') !== '2') {
                $this->error('Branch bootstrap must return HTTP 200 with two queries. Check the running server configuration.');

                return self::FAILURE;
            }
            $this->line('Branch bootstrap: 2 queries once. Initial two-request load: 4 queries including both auth lookups (exceeds the original aggregate budget of 3).');
            $this->line('The following latency/query budgets measure appointment reloads only.');
            foreach ($scenarios as $name => $filters) {
                $timings = ['http' => [], 'routing' => [], 'bootstrap' => [], 'application' => [], 'sql' => [], 'dns' => [], 'connect' => [], 'ttfb' => [], 'transfer' => []];
                $maxQueries = 0;
                $parameters = array_merge($base, $filters);
                $this->line($name.': '.http_build_query($parameters));
                for ($i = 0; $i < $samples + $warmup; $i++) {
                    $start = hrtime(true);
                    $response = $client->get($url.'/api/appointments', $parameters);
                    $response->body();
                    $elapsed = (hrtime(true) - $start) / 1000000;
                    if ($i === 0) {
                        $this->line('Server: '.$response->header('X-Board-Runtime'));
                    }
                    if ($response->status() !== 200 || ! ctype_digit($response->header('X-Board-Query-Count'))) {
                        $this->error('Expected HTTP 200 and local profiling headers, received HTTP '.$response->status().'. Confirm the server uses this checkout, session configuration and local environment.');

                        return self::FAILURE;
                    }
                    if ($i >= $warmup) {
                        $timings['http'][] = $elapsed;
                        $maxQueries = max($maxQueries, (int) $response->header('X-Board-Query-Count'));
                        foreach (['routing', 'bootstrap', 'application', 'sql'] as $metric) {
                            if (! preg_match('/'.preg_quote($metric, '/').';dur=([0-9.]+)/', $response->header('Server-Timing'), $match)) {
                                throw new \RuntimeException('Missing server timing metric: '.$metric);
                            }
                            $timings[$metric][] = (float) $match[1];
                        }
                        $stats = $response->handlerStats();
                        foreach (['dns' => 'namelookup_time', 'connect' => 'connect_time', 'ttfb' => 'starttransfer_time', 'transfer' => 'total_time'] as $metric => $key) {
                            $timings[$metric][] = (float) ($stats[$key] ?? 0) * 1000;
                        }
                    }
                }
                $p95 = $this->percentile($timings['http']);
                $passed = $passed && $p95 < 200 && $maxQueries <= 3;
                $row = [$name, $maxQueries, number_format($p95, 2), number_format($this->percentile($timings['bootstrap']), 2), number_format($this->percentile($timings['application']), 2), number_format($this->percentile($timings['sql']), 2)];
                $summary[] = $row;
                $this->line(implode(' | ', $row));
                $this->line('Transport p95: DNS '.$this->percentile($timings['dns']).'ms; connect '.$this->percentile($timings['connect']).'ms; TTFB '.$this->percentile($timings['ttfb']).'ms; total '.$this->percentile($timings['transfer']).'ms; pre-Laravel '.$this->percentile($timings['routing']).'ms.');
            }
        } finally {
            $session->getHandler()->destroy($session->getId());
        }
        $this->table(['Scenario', 'Max SQL', 'HTTP p95 ms', 'Bootstrap p95', 'App p95', 'SQL p95'], $summary);
        $this->line($passed ? 'PASS for appointment reloads: HTTP p95 < 200ms and <= 3 queries in every scenario. Initial aggregate SQL budget remains 4, not 3.' : 'FAIL: HTTP latency or query budget exceeded.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<float> $samples */
    private function percentile(array $samples): float
    {
        if ($samples === []) {
            throw new \LogicException('No samples collected.');
        }
        sort($samples);

        return $samples[(int) ceil(count($samples) * 0.95) - 1];
    }
}
