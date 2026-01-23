<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeline_events', function (Blueprint $table) {
            $table->id();

            // Optional linkage to a source record (e.g. shifts.id, client_notes.id)
            $table->string('source_type')->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();

            $table->dateTime('occurred_at')->index();
            $table->string('type')->index();

            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();

            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('meta')->nullable();

            $table->string('visibility')->default('internal')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['type', 'source_type', 'source_id'], 'timeline_events_type_source_unique');

            $table->index(['type', 'occurred_at']);
            $table->index(['client_id', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['site_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
