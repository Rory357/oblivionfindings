<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_support_trigger_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('assessment_id')->constrained('medication_self_admin_assessments')->restrictOnDelete();
            $table->string('source_key', 190)->unique();
            $table->string('kind', 32);
            $table->string('reason', 512);
            $table->timestamp('occurred_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->timestamps();
            $table->index(['delivered_at', 'id'], 'support_trigger_delivery_index');
        });
    }

    public function down(): void
    {
        if (DB::table('medication_support_trigger_outbox')->exists()) {
            throw new RuntimeException('Retained medication support trigger evidence cannot be discarded.');
        }
        Schema::dropIfExists('medication_support_trigger_outbox');
    }
};
