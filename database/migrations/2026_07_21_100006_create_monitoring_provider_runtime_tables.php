<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_provider_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->string('provider', 64);
            $table->string('capability', 255);
            $table->string('cursor', 2048)->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('retry_not_before')->nullable();
            $table->unsignedInteger('exception_count')->default(0);
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_provider_cursor_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->unique(
                ['site_id', 'provider', 'capability'],
                'monitoring_provider_cursor_scope_uq',
            );
            $table->index(['provider', 'retry_not_before'], 'monitoring_provider_cursor_retry_idx');
        });

        Schema::create('monitoring_provider_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id');
            $table->string('provider', 64);
            $table->string('capability', 255);
            $table->string('code', 64);
            $table->string('item_reference', 128)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('site_id', 'monitoring_provider_exception_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->index(
                ['site_id', 'provider', 'capability', 'occurred_at'],
                'monitoring_provider_exception_scope_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_provider_exceptions');
        Schema::dropIfExists('monitoring_provider_cursors');
    }
};
