<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_provider_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('site_id')->constrained('sites');
            $table->string('direction', 20);
            $table->string('provider_name');
            $table->string('recipient_name');
            $table->text('purpose');
            $table->text('disclosure_basis');
            $table->text('identity_evidence');
            $table->text('source_reference')->nullable();
            $table->longText('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->boolean('controlled')->default(false);
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->string('request_key', 100);
            $table->char('payload_sha256', 64);
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('receipt_reference')->nullable();
            $table->foreignId('reconciliation_id')->nullable()->constrained('medication_reconciliations');
            $table->timestamps();
            $table->unique(['created_by', 'request_key'], 'mpt_request_unique');
        });
        Schema::create('medication_provider_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('medication_provider_transfers', indexName: 'mpte_transfer_fk');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 40);
            $table->string('request_key', 100);
            $table->char('payload_sha256', 64);
            $table->text('evidence');
            $table->timestamp('created_at');
            $table->unique(['transfer_id', 'request_key'], 'mpte_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_provider_transfer_events');
        Schema::dropIfExists('medication_provider_transfers');
    }
};
