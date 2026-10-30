<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_pharmacy_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('partner_key', 80);
            $table->json('site_ids');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('medication_pharmacy_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('connection_id')->constrained('medication_pharmacy_connections')->restrictOnDelete();
            $table->foreignId('pharmacy_order_id')->unique()->constrained('medication_pharmacy_orders')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('client_medication_id')->constrained('client_medications')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->unsignedInteger('connection_version');
            $table->char('partner_fingerprint', 64);
            $table->char('snapshot_fingerprint', 64);
            $table->longText('snapshot'); // Encrypted by the model; never a reader DTO.
            $table->string('state', 20)->default('queued');
            $table->string('result_code', 80)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('sending_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('supplier_reference', 100)->nullable();
            $table->string('acknowledgment_outcome', 20)->nullable();
            $table->boolean('acknowledgment_applied')->default(false);
            $table->string('acknowledgment_code', 80)->nullable();
            $table->timestamps();
            $table->index(['state', 'sending_at'], 'pharmacy_dispatch_recovery');
        });
        Schema::create('medication_pharmacy_acknowledgments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('medication_pharmacy_connections')->restrictOnDelete();
            $table->foreignId('dispatch_id')->constrained('medication_pharmacy_dispatches')->restrictOnDelete();
            $table->string('event_id', 100);
            $table->char('fingerprint', 64);
            $table->string('outcome', 20);
            $table->string('supplier_reference', 100);
            $table->boolean('applied')->default(false);
            $table->string('processing_code', 80);
            $table->timestamp('received_at');
            $table->unique(['connection_id', 'event_id'], 'pharmacy_ack_event_unique');
        });
        Schema::create('medication_pharmacy_dispatch_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_id')->constrained('medication_pharmacy_dispatches')->restrictOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->char('fingerprint', 64);
            $table->longText('result'); // Encrypted manual pharmacy-check evidence.
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['medication_pharmacy_dispatch_commands', 'medication_pharmacy_acknowledgments', 'medication_pharmacy_dispatches', 'medication_pharmacy_connections'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove connected pharmacy ordering while retained connection or delivery evidence exists.');
            }
        }
        Schema::dropIfExists('medication_pharmacy_dispatch_commands');
        Schema::dropIfExists('medication_pharmacy_acknowledgments');
        Schema::dropIfExists('medication_pharmacy_dispatches');
        Schema::dropIfExists('medication_pharmacy_connections');
    }
};
