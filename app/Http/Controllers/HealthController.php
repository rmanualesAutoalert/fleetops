<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthCheckService $health): JsonResponse
    {
        $result = $health->check();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503);
    }
}
