<?php

namespace App\Http\Controllers;

use App\Contracts\ReportGenerator;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportGenerator $reports,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->generate(),
        ]);
    }
}
