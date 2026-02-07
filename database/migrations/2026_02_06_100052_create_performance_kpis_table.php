<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('performance_review_id')->constrained()->onDelete('cascade');
            
            // KPI definition
            $table->string('pillar');
            $table->string('kpi_name');
            $table->text('kpi_definition');
            $table->string('data_source'); // model/calculation reference
            $table->string('calculation_method')->nullable(); // Formula or query reference
            
            // Targets and actuals
            $table->string('target_value');
            $table->string('actual_value')->nullable();
            $table->string('unit'); // percentage, count, days, dollars, etc.
            
            // Period
            $table->date('period_start');
            $table->date('period_end');
            
            // Automation
            $table->boolean('is_automated')->default(false);
            $table->datetime('last_synced_at')->nullable();
            $table->text('sync_notes')->nullable();
            
            $table->timestamps();

            $table->index(['performance_review_id', 'pillar']);
            $table->index(['is_automated', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_kpis');
    }
};
