<?php

namespace App\Http\Controllers;

use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\ServiceRecord;
use Illuminate\Http\JsonResponse;

class ServiceRecordController extends Controller
{
    public function index(): JsonResponse
    {
        $records = ServiceRecord::query()
            ->select([
                'id',
                'appointment_id',
                'vehicle_id',
                'service_type',
                'cost',
                'completed_at',
            ])
            ->with([
                'vehicle:id,customer_id,plate_number',
                'vehicle.customer:id,name',
                'appointment:id,advisor_id',
                'appointment.advisor:id,name',
            ])
            ->where('branch_id', request('branch_id'))
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get();

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => $record->id,
                'service_type' => $record->service_type,
                'cost' => $record->cost,
                'completed_at' => $record->completed_at,
                'plate' => $record->vehicle->plate_number,
                'customer_name' => $record->vehicle->customer->name,
                'advisor' => $record->appointment->advisor->name,
            ];
        }

        return response()->json($rows);
    }

    public function advisorWorkload(): JsonResponse
    {
        $monthStart = now()->startOfMonth();
        // copy() matters because Carbon dates are mutable. Without it, modifying the date could also alter $monthStart.
        $nextMonthStart = $monthStart->copy()->addMonth();

        // correctly handles different month lengths and avoids accidentally including the same month from prior years.
        $currentMonth = fn ($query) => $query
            ->where('completed_at', '>=', $monthStart)
            ->where('completed_at', '<', $nextMonthStart);

        $board = Advisor::query()
            ->select(['id', 'name'])
            ->withCount(['serviceRecords as jobs' => $currentMonth])
            ->withSum(['serviceRecords as revenue' => $currentMonth], 'cost')
            ->withMax('serviceRecords as last_job', 'completed_at')
            ->withCasts(['last_job' => 'datetime'])
            ->where('branch_id', request('branch_id'))
            ->orderByDesc('revenue')
            ->get()
            ->map(fn (Advisor $advisor): array => [
                'advisor' => $advisor->name,
                'jobs' => (int) $advisor->getAttribute('jobs'),
                'revenue' => (float) ($advisor->getAttribute('revenue') ?? 0),
                'last_job' => $advisor->getAttribute('last_job'),
            ]);

        return response()->json($board);
    }

    public function fullHistory(Appointment $appointment): JsonResponse
    {
        $appointment->load([
            'customer:id,name',
            'vehicle:id,plate_number',
            'advisor:id,name',
            'serviceRecords:id,appointment_id,service_type,cost',
        ]);

        return response()->json([
            'id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at,
            'status' => $appointment->status,
            'customer' => $appointment->customer->name,
            'plate' => $appointment->vehicle->plate_number,
            'advisor' => $appointment->advisor->name,
            'records' => $appointment->serviceRecords->map(fn ($record) => [
                'service_type' => $record->service_type,
                'cost' => $record->cost,
            ]),
        ]);
    }
}
