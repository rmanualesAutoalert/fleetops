<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogReportRequest
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $providedRequestId = $request->header('X-Request-ID');
        $requestId = is_string($providedRequestId)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $providedRequestId) === 1
                ? $providedRequestId
                : (string) Str::uuid();
        $startedAt = hrtime(true);
        $response = null;
        $failure = null;

        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $requestId);

            return $response;
        } catch (Throwable $exception) {
            $failure = $exception;

            throw $exception;
        } finally {
            Log::channel('reports')->info('Report request completed.', [
                'event' => 'report.request.completed',
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->getAuthIdentifier(),
                'status' => $response?->getStatusCode() ?? 500,
                'outcome' => $failure === null ? 'completed' : 'failed',
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ]);
        }
    }
}
