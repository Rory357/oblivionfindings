<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_gl_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('fin_accounting_integrations')->cascadeOnDelete();
            $table->enum('direction', ['push', 'pull']);
            $table->string('entity_type')->comment('e.g. journal, invoice, account, contact');
            $table->unsignedInteger('entity_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['integration_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_gl_sync_logs');
    }
};
