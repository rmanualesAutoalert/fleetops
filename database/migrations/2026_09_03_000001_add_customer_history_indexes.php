<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->index('email', 'customers_email_idx');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->index('customer_id', 'vehicles_customer_idx');
        });

        Schema::table('service_records', function (Blueprint $table) {
            $table->index(['vehicle_id', 'completed_at'], 'service_records_vehicle_completed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropIndex('service_records_vehicle_completed_idx');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_customer_idx');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_email_idx');
        });
    }
};
