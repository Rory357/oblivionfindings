<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            
            // Snapshot data (immutable)
            $table->json('snapshot_data'); // All dashboard metrics
            $table->string('period_type'); // today, week, month, year
            $table->date('period_start');
            $table->date('period_end');
            
            // Integrity
            $table->string('checksum');
            
            // Source tracking
            $table->datetime('captured_at');
            $table->foreignId('captured_by')->constrained('users');
            
            // Data freshness indicators
            $table->json('data_freshness')->nullable(); // When each data source was last updated
            
            $table->timestamps();

            $table->index(['period_type', 'period_start']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
};
