<?php
// FleetOps · ServiceRecordController
// Legacy module carried over from the original build — works, but the
// branch board has been complaining about page load times lately.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ServiceRecord;
use App\Models\Advisor;
use Illuminate\Http\JsonResponse;

class ServiceRecordController extends Controller
{
    /**
     * GET /api/service-records
     * Recent service records with vehicle + customer names for the branch board.
     */
    public function index(): JsonResponse
    {
        $records = ServiceRecord::where('branch_id', request('branch_id'))
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get();

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id'            => $record->id,
                'service_type'  => $record->service_type,
                'cost'          => $record->cost,
                'completed_at'  => $record->completed_at,
                'plate'         => $record->vehicle->plate_number,
                'customer_name' => $record->vehicle->customer->name,
                'advisor'       => $record->appointment->advisor->name,
            ];
        }

        return response()->json($rows);
    }

    /**
     * GET /api/advisors/workload?branch_id=
     * Advisor leaderboard: completed jobs + revenue this month.
     */
    public function advisorWorkload(): JsonResponse
    {
        $advisors = Advisor::where('branch_id', request('branch_id'))->get();

        $board = [];
        foreach ($advisors as $advisor) {
            $jobs = ServiceRecord::where('advisor_id', $advisor->id)
                ->whereMonth('completed_at', now()->month)
                ->get();

            $board[] = [
                'advisor'  => $advisor->name,
                'jobs'     => $jobs->count(),
                'revenue'  => $jobs->sum('cost'),
                'last_job' => ServiceRecord::where('advisor_id', $advisor->id)
                    ->orderByDesc('completed_at')
                    ->first()?->completed_at,
            ];
        }

        usort($board, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return response()->json($board);
    }

    /**
     * GET /api/appointments/{appointment}/full-history
     * Everything about an appointment for the detail drawer.
     * "It was slow, so I eager-loaded it" — a previous dev's fix.
     */
    public function fullHistory(Appointment $appointment): JsonResponse
    {
        $appointment->load([
            'customer.vehicles.serviceRecords.appointment.advisor.branch',
            'vehicle.serviceRecords',
            'advisor.branch',
            'branch.advisors',
            'serviceRecords.notes.author',
            'serviceRecords.parts',
            'serviceRecords.auditLog',
        ]);

        return response()->json([
            'id'           => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at,
            'status'       => $appointment->status,
            'customer'     => $appointment->customer->name,
            'plate'        => $appointment->vehicle->plate_number,
            'advisor'      => $appointment->advisor->name,
            'records'      => $appointment->serviceRecords->map(fn ($r) => [
                'service_type' => $r->service_type,
                'cost'         => $r->cost,
            ]),
        ]);
    }
}
