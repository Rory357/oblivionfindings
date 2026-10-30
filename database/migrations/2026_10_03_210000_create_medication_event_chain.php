<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_event_heads', function (Blueprint $table) {
            $table->foreignId('site_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->char('hash', 64);
        });
        Schema::create('medication_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('kind', 100);
            $table->string('subject_type', 100);
            $table->string('subject_id', 100);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('recorded_at', 6);
            $table->string('summary');
            $table->json('facts');
            $table->boolean('controlled')->default(false);
            $table->unsignedBigInteger('corrects_event_id')->nullable();
            $table->char('previous_hash', 64);
            $table->char('hash', 64);
            $table->unique(['site_id', 'sequence']);
            $table->index(['site_id', 'occurred_at', 'id']);
            $table->index(['site_id', 'kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        // Fail before Laravel removes the migration receipt. Silent success
        // would leave tables behind and break the next migrate's CREATE.
        throw new RuntimeException('Medication evidence cannot be rolled back. Use a reviewed forward migration.');
    }
};
