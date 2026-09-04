<?php

namespace App\Http\Controllers;

use App\Http\Requests\AppointmentBoardRequest;
use App\Queries\AppointmentBoardQuery;
use Illuminate\Http\JsonResponse;

class AppointmentBoardController extends Controller
{
    public function __invoke(AppointmentBoardRequest $request, AppointmentBoardQuery $query): JsonResponse
    {
        $branchId = $request->integer('branch_id');

        $page = $request->integer('page', 1);
        $rows = $query->build(
            (int) $branchId,
            $request->string('from')->toString(),
            $request->string('to')->toString(),
            $request->filled('status') ? $request->string('status')->toString() : null,
            trim($request->string('q')->toString()),
            $page * 50 + 1,
        )->offset(($page - 1) * 50)->limit(51)->get();

        return response()->json([
            'data' => $rows->take(50)->values(),
            'meta' => [
                'branch_id' => $branchId,
                'page' => $page,
                'per_page' => 50,
                'has_more' => $rows->count() > 50,
                'timezone' => config('app.timezone'),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
