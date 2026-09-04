<?php

namespace App\Http\Middleware;

use App\Support\QueryProfile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ProfileBoardRequest
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('local') || ! $request->is('api/appointments', 'api/branches') || $request->header('X-Board-Profile') !== '1') {
            return $next($request);
        }

        $start = microtime(true);
        $metrics = new QueryProfile;
        DB::listen($metrics->record(...));

        try {
            $response = $next($request);
        } finally {
            $metrics->stop();
        }

        $bootstrap = defined('LARAVEL_START') ? ($start - LARAVEL_START) * 1000 : 0;
        $routing = defined('LARAVEL_START') && isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? max(0, (LARAVEL_START - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000) : 0;
        $response->headers->set('Server-Timing', sprintf(
            'routing;dur=%.2f, bootstrap;dur=%.2f, application;dur=%.2f, sql;dur=%.2f',
            $routing, $bootstrap, (microtime(true) - $start) * 1000, $metrics->milliseconds,
        ));
        $response->headers->set('X-Board-Query-Count', (string) $metrics->count);
        $response->headers->set('X-Board-Runtime', 'PHP '.PHP_VERSION.' '.PHP_SAPI.'; opcache='.(extension_loaded('Zend OPcache') ? ini_get('opcache.enable') : 'unavailable'));

        return $response;
    }
}
