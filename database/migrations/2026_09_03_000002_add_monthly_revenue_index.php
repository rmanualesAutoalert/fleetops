<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->index(
                ['completed_at', 'branch_id', 'cost'],
                'service_records_completed_branch_cost_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropIndex('service_records_completed_branch_cost_idx');
        });
    }
};
