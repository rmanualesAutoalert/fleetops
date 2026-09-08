<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_branch_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('summary_date');
            $table->unsignedBigInteger('jobs');
            $table->decimal('revenue', 14, 2);
            $table->timestamps();

            $table->unique(['branch_id', 'summary_date']);
            $table->index(['summary_date', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_branch_summaries');
    }
};
