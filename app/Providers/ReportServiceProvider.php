<?php

namespace App\Providers;

use App\Contracts\ReportGenerator;
use App\Services\ServiceRecordReportGenerator;
use Illuminate\Support\ServiceProvider;

class ReportServiceProvider extends ServiceProvider
{
    /**
     * Register report services in the container.
     */
    public function register(): void
    {
        $this->app->bind(ReportGenerator::class, ServiceRecordReportGenerator::class);
    }

    /**
     * Bootstrap report services after all providers are registered.
     */
    public function boot(): void
    {
        // No report services need application bootstrapping yet.
    }
}
