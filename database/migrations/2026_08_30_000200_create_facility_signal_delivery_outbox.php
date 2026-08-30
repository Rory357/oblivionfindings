<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_signals', function (Blueprint $table): void {
            $table->id();
            // These scalar references are immutable provenance. Deliberately
            // retain their first-write values even if an operational parent is
            // later removed; ON DELETE SET NULL would silently rewrite the
            // append-only safety evidence outside the model guards.
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('inspection_schedule_id')->nullable();
            $table->unsignedBigInteger('inspection_record_id')->nullable();
            $table->string('signal_type');
            $table->string('severity_hint')->default('medium');
            $table->dateTime('occurred_at');
            $table->string('idempotency_key', 64)
                ->unique('facility_signals_idempotency_uq');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'signal_type'], 'facility_signals_site_type_idx');
            $table->index(
                ['inspection_schedule_id', 'signal_type'],
                'facility_signals_schedule_type_idx',
            );
        });

        Schema::create('facility_signal_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_signal_id')
                ->unique('facility_signal_outbox_signal_uq')
                ->constrained('facility_signals')
                ->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'last_attempt_at'],
                'facility_signal_outbox_recovery_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_signal_outbox');
        Schema::dropIfExists('facility_signals');
    }
};
