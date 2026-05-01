<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_gap_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coverage_requirement_id')->nullable()->constrained('site_coverage_requirements')->nullOnDelete();
            $table->string('coverage_window_key');
            $table->dateTime('window_starts_at');
            $table->dateTime('window_ends_at');
            $table->string('state', 20);
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');
            $table->dateTime('cleared_at')->nullable();

            $table->index(['coverage_window_key', 'cleared_at'], 'coverage_gap_ack_key_cleared_idx');
            $table->index(['organization_id', 'site_id', 'window_starts_at'], 'coverage_gap_ack_org_site_window_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_gap_acknowledgements');
    }
};
