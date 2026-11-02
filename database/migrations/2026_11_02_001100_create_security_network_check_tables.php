<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase I (pop-up 97): "Obli checks <Site>'s network". IT asks a question; Obli plans a few
 * read-only checks, the Site's hub runs them, and Obli explains what it found. Each session is the
 * Site's record of who asked what, when, through which hub, and what was found.
 *
 * One running session per Site: `open_site_id` holds the Site while a session runs and is cleared
 * when it ends, under a unique index. Sessions are kept for a year (NetworkChecks::KEEP_DAYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_network_check_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('open_site_id')->nullable()->unique('sd_netcheck_open_site_unique');
            $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
            $table->string('hub_label', 191)->nullable();
            // The Wi-Fi point the question was asked from, when it came from a device page.
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('asked_by', 191);
            // What was typed and what Obli said: encrypted at rest.
            $table->text('question');
            $table->text('plan')->nullable();
            $table->text('answer')->nullable();
            $table->foreignId('focus_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->boolean('suggest_unifi')->default(false);
            $table->boolean('unchecked_numbers')->default(false);
            $table->string('state', 16)->default('planning'); // planning | checking | answering | answered | failed
            $table->string('failure', 191)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained('it_tickets')->nullOnDelete();
            $table->unsignedBigInteger('charged_micros')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['site_id', 'created_at'], 'sd_netcheck_site_created_idx');
            $table->index(['state', 'updated_at'], 'sd_netcheck_state_updated_idx');
            $table->index('expires_at', 'sd_netcheck_expires_idx');
        });

        Schema::create('security_network_check_steps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_id')->constrained('security_network_check_sessions')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('kind', 24);
            $table->string('source', 8); // hub | stored
            $table->foreignId('target_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('title', 191);
            // What the hub is sent (address, port, MAC): no logins, ever.
            $table->json('params')->nullable();
            $table->string('state', 16)->default('queued'); // queued | sent | done | not_available
            $table->foreignId('collector_id')->nullable()->constrained('monitoring_collectors')->nullOnDelete();
            // What was found, already cleaned to the kind's fields.
            $table->json('result')->nullable();
            $table->string('finding', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['session_id', 'position'], 'sd_netcheck_steps_session_idx');
            $table->index(['state', 'expires_at'], 'sd_netcheck_steps_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_network_check_steps');
        Schema::dropIfExists('security_network_check_sessions');
    }
};
