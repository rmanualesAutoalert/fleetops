<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['customer_id', 'branch_id', 'scheduled_at'], 'appointments_customer_branch_scheduled_idx');
            $table->index(['vehicle_id', 'branch_id', 'scheduled_at'], 'appointments_vehicle_branch_scheduled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_customer_branch_scheduled_idx');
            $table->dropIndex('appointments_vehicle_branch_scheduled_idx');
        });
    }
};
