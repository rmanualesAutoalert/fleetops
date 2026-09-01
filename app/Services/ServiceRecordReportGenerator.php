<?php

namespace App\Services;

use App\Contracts\ReportGenerator;
use App\Models\ServiceRecord;

class ServiceRecordReportGenerator implements ReportGenerator
{
    public function generate(): array
    {
        return ServiceRecord::query()
            ->selectRaw('service_type, COUNT(*) as total_services, SUM(cost) as total_revenue')
            ->groupBy('service_type')
            ->orderBy('service_type')
            ->get()
            ->toArray();
    }
}
