<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BoardBranchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $branches = DB::table('branches as b')
            ->leftJoin('advisors as advisor', function (JoinClause $join) use ($request): void {
                $join->on('advisor.branch_id', '=', 'b.id')->where('advisor.user_id', $request->user()->id);
            })
            ->select(['b.id', 'b.name', 'advisor.id as own_advisor_id'])
            ->orderBy('b.name')->orderBy('b.id')->get();
        $default = $branches->first(fn (object $branch): bool => $branch->own_advisor_id !== null) ?? $branches->first();

        return response()->json([
            'data' => $branches->map(fn (object $branch): array => ['id' => (int) $branch->id, 'name' => $branch->name]),
            'meta' => ['default_branch_id' => $default === null ? null : (int) $default->id],
        ])->header('Cache-Control', 'private, no-store');
    }
}
