<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_lifecycle_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_incident_id')
                ->constrained('client_incidents')
                ->restrictOnDelete();
            $table->foreignId('actor_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();
            $table->foreignId('client_id')
                ->constrained('clients')
                ->restrictOnDelete();
            $table->foreignId('hs_event_id')
                ->constrained('hs_events')
                ->restrictOnDelete();
            $table->foreignId('control_room_alert_id')
                ->nullable()
                ->constrained('control_room_alerts')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('signal_type', 32);
            $table->string('incident_source', 64);
            $table->string('from_status', 32);
            $table->string('target_status', 32);
            $table->dateTime('effective_at');
            $table->string('idempotency_key', 64)->unique('incident_lifecycle_signals_idempotency_uq');
            $table->json('payload');
            $table->timestamps();

            $table->unique(
                ['client_incident_id', 'sequence'],
                'incident_lifecycle_signals_sequence_uq',
            );
            $table->index(
                ['client_incident_id', 'signal_type', 'effective_at'],
                'incident_lifecycle_signals_source_transition_idx',
            );
        });

        Schema::create('incident_lifecycle_signal_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_lifecycle_signal_id')
                ->unique('incident_lifecycle_signal_outbox_signal_uq');
            $table->foreign(
                'incident_lifecycle_signal_id',
                'incident_lifecycle_outbox_signal_fk',
            )
                ->references('id')
                ->on('incident_lifecycle_signals')
                ->restrictOnDelete();
            $table->foreignId('resulting_alert_id')
                ->nullable()
                ->constrained('control_room_alerts')
                ->restrictOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'last_attempt_at'],
                'incident_lifecycle_signal_outbox_recovery_idx',
            );
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER incident_lifecycle_signals_immutable_update
BEFORE UPDATE ON incident_lifecycle_signals
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incident lifecycle signal provenance is append-only and immutable'
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER incident_lifecycle_signals_immutable_delete
BEFORE DELETE ON incident_lifecycle_signals
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Incident lifecycle signal provenance is append-only and immutable'
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_lifecycle_signal_outbox');

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS incident_lifecycle_signals_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS incident_lifecycle_signals_immutable_delete');
        }

        Schema::dropIfExists('incident_lifecycle_signals');
    }
};
