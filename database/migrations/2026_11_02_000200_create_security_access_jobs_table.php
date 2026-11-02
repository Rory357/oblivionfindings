<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase H (H1): changes Oblivion asks a Site's hub to make in UniFi Access (people, cards,
 * visitor passes), the readers each door has (for "tap a new card"), and the console's
 * emergency state as last read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_access_jobs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('console_id')->constrained('security_access_consoles')->cascadeOnDelete();
            $table->string('kind', 24);
            // Names and card details: encrypted at rest.
            $table->text('params');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 191)->nullable();
            $table->string('state', 16)->default('queued'); // queued | sent | succeeded | failed | uncertain | expired
            $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
            $table->text('result')->nullable();
            $table->string('failure', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['console_id', 'state'], 'sd_access_jobs_console_state_idx');
            $table->index(['state', 'expires_at'], 'sd_access_jobs_state_expiry_idx');
        });

        Schema::table('security_access_doors', function (Blueprint $table): void {
            // [{id, name, type}] — the readers at this door, from UniFi.
            $table->json('readers')->nullable()->after('hub_bound');
        });

        Schema::table('security_access_consoles', function (Blueprint $table): void {
            $table->string('emergency_mode', 16)->default('normal')->after('events_until'); // normal | lockdown | evacuation
        });
    }

    public function down(): void
    {
        Schema::table('security_access_consoles', function (Blueprint $table): void {
            $table->dropColumn('emergency_mode');
        });
        Schema::table('security_access_doors', function (Blueprint $table): void {
            $table->dropColumn('readers');
        });
        Schema::dropIfExists('security_access_jobs');
    }
};
