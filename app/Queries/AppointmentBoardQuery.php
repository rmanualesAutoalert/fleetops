<?php

namespace App\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentBoardQuery
{
    public function build(int $branchId, string $from, string $to, ?string $status, string $search, int $window = 51): Builder
    {
        // Existing seeded DATETIME values are interpreted in the application timezone.
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->addDay()->startOfDay();

        $query = DB::table('appointments as a')
            ->leftJoin('customers as c', 'c.id', '=', 'a.customer_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'a.vehicle_id')
            ->leftJoin('advisors as advisor', 'advisor.id', '=', 'a.advisor_id')
            ->select(['a.id', 'a.scheduled_at', 'a.status', 'a.bay', 'c.name as customer_name', 'v.plate_number', 'advisor.name as advisor_name'])
            ->where('a.branch_id', $branchId)
            ->where('a.scheduled_at', '>=', $start->toDateTimeString())
            ->where('a.scheduled_at', '<', $end->toDateTimeString())
            ->when($status, fn (Builder $query) => $query->where('a.status', $status))
            ->orderBy('a.scheduled_at')
            ->orderBy('a.id');

        if ($search !== '') {
            // Treat LIKE wildcards literally; ! is a portable explicit escape character.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            // Materialize small match sets, then find appointment IDs through indexed
            // customer/vehicle references. UNION removes IDs matching both criteria.
            $customers = DB::table('customers')->select('id')
                ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->groupBy('id');
            $vehicles = DB::table('vehicles')->select('id')
                ->whereRaw("plate_number LIKE ? ESCAPE '!'", [$pattern])->groupBy('id');
            $candidates = fn (Builder $matches, string $column): Builder => DB::table('appointments as candidate')
                ->joinSub($matches, 'matched', 'matched.id', '=', 'candidate.'.$column)
                ->select('candidate.id')
                ->where('candidate.branch_id', $branchId)
                ->where('candidate.scheduled_at', '>=', $start->toDateTimeString())
                ->where('candidate.scheduled_at', '<', $end->toDateTimeString())
                ->when($status, fn (Builder $query) => $query->where('candidate.status', $status))
                ->orderBy('candidate.scheduled_at')->orderBy('candidate.id')->limit($window);

            // The first K union rows must be in the first K rows of either source.
            // Bound each ordered source before joining expensive display columns.
            $ids = $candidates($customers, 'customer_id')->union($candidates($vehicles, 'vehicle_id'));
            $query->joinSub($ids, 'matched_appointment', 'matched_appointment.id', '=', 'a.id');
        }

        return $query;
    }
}
