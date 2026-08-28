<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('appointment_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('advisor_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('service_type');
            $table->decimal('cost', 8, 2);
            $table->dateTime('completed_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_records');
    }
};
