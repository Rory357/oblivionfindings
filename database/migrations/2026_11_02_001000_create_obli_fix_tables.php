<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase I: Obli fixes things (protocol "Records"). One help session per request, its steps,
 * the screen pictures the tray sent (7 days, D17), who opened them and why, one-off requests to IT
 * (pop-up 95), the fixes IT turned on for Obli per computer (15j, pop-up 93) and offline reports.
 *
 * Anything a person typed or Obli said is stored encrypted (the *_encrypted columns) and scrubbed
 * when the chat retention ends; the bare record (who, which computer, when, how it ended) stays
 * for 90 days so Setup can say how each fix has gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obli_fix_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('session_hash', 64);
            $table->uuid('request_uuid');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('context_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->uuid('agent_uuid')->nullable();
            $table->string('assignment_fingerprint', 64)->nullable();
            $table->string('state', 20);
            $table->string('outcome', 20)->nullable();
            $table->string('stop_reason', 20)->nullable();
            $table->string('problem_hash', 64);
            $table->text('problem_encrypted')->nullable();
            $table->text('checks_encrypted')->nullable();
            $table->text('plan_encrypted')->nullable();
            $table->text('outcome_encrypted')->nullable();
            $table->unsignedSmallInteger('screen_width');
            $table->unsignedSmallInteger('screen_height');
            $table->unsignedTinyInteger('plan_steps')->default(0);
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->unsignedSmallInteger('turns')->default(0);
            $table->unsignedSmallInteger('last_turn')->default(0);
            $table->unsignedTinyInteger('refusals_in_a_row')->default(0);
            $table->unsignedSmallInteger('pending_turn')->nullable();
            $table->mediumText('last_response_encrypted')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedInteger('configuration_version')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('charged_micros')->default(0);
            $table->unsignedBigInteger('pending_reserved_micros')->nullable();
            $table->json('pending_periods')->nullable();
            $table->unsignedBigInteger('input_rate')->default(0);
            $table->unsignedBigInteger('output_rate')->default(0);
            $table->timestamp('planning_started_at')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            // While IT decides a one-off request the tray sends no pictures; that time doesn't count towards the 15 minutes.
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('scrubbed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_uuid']);
            $table->index(['device_id', 'created_at']);
            $table->index(['state', 'expires_at']);
            $table->index('created_at');
        });

        Schema::create('obli_fix_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('obli_fix_sessions')->cascadeOnDelete();
            $table->unsignedSmallInteger('turn');
            $table->unsignedTinyInteger('step')->nullable();
            // action | fix | ask_it | refused | password | finish | hand_to_it | stop | note
            $table->string('kind', 20);
            $table->text('narration_encrypted')->nullable();
            // The action as sent, without any typed text (that is text_encrypted, never for a password field).
            $table->json('action')->nullable();
            $table->text('text_encrypted')->nullable();
            $table->string('refusal', 30)->nullable();
            $table->string('result', 20)->nullable();
            $table->text('result_encrypted')->nullable();
            $table->unsignedBigInteger('picture_id')->nullable()->index('obli_fix_steps_picture_idx');
            $table->string('fix_key', 260)->nullable();
            $table->timestamp('at');
            $table->index(['session_id', 'turn']);
        });

        Schema::create('obli_screen_pictures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('obli_fix_sessions')->cascadeOnDelete();
            $table->unsignedSmallInteger('turn');
            $table->string('path', 255);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');
            $table->string('sha256', 64);
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('delete_after');
            $table->timestamp('created_at')->nullable();
            $table->index('delete_after');
            $table->index(['session_id', 'turn']);
        });

        Schema::create('obli_picture_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('obli_fix_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('reason', 500);
            $table->unsignedSmallInteger('pictures');
            $table->timestamp('viewed_at');
            $table->timestamp('expires_at');
            $table->index(['session_id', 'viewed_at']);
        });

        Schema::create('obli_fix_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('obli_fix_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('user_id');
            // fix: a fix from the list (run through the existing governed form); commands: anything else (by hand)
            $table->string('kind', 20);
            $table->string('fix_key', 260)->nullable();
            $table->text('what_encrypted')->nullable();
            $table->text('commands_encrypted')->nullable();
            // requested | approved | declined | expired | cancelled
            $table->string('state', 20);
            // governed | by_hand, set when IT approves
            $table->string('route', 20)->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('note_encrypted')->nullable();
            $table->unsignedBigInteger('command_request_id')->nullable();
            $table->string('command_result', 20)->nullable();
            $table->timestamp('asked_at');
            $table->timestamps();
            $table->index(['state', 'site_id']);
            $table->index(['device_id', 'asked_at']);
        });

        Schema::create('obli_fix_allowances', function (Blueprint $table): void {
            $table->id();
            $table->string('fix_key', 260);
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('turned_on_by_user_id');
            $table->timestamp('turned_on_at');
            $table->string('reason', 500);
            $table->unsignedBigInteger('turned_off_by_user_id')->nullable();
            $table->timestamp('turned_off_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'fix_key']);
            $table->index(['fix_key', 'turned_off_at']);
        });

        Schema::create('obli_offline_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('dedupe_hash', 64)->unique();
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->json('checks');
            $table->timestamp('created_at')->nullable();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obli_offline_reports');
        Schema::dropIfExists('obli_fix_allowances');
        Schema::dropIfExists('obli_fix_requests');
        Schema::dropIfExists('obli_picture_views');
        Schema::dropIfExists('obli_screen_pictures');
        Schema::dropIfExists('obli_fix_steps');
        Schema::dropIfExists('obli_fix_sessions');
    }
};
