<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase G (D5): a device's Terminal tab, opened through the Site's hub by an IT admin. Every
 * line typed is kept (the terminal's history); what the device sends back is kept only while the
 * session is open and an hour after, because it can show settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_terminal_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('collector_id')->constrained('monitoring_collectors')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('state', 16)->default('requested'); // requested | opening | open | closed
            $table->string('address', 45);
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('login_name', 64)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_input_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // closed | idle | too_long | hub_offline | host_key_changed | host_key_untrusted | login_failed | unreachable | ended | hub_restarted
            $table->string('close_reason', 24)->nullable();
            $table->timestamps();

            $table->index(['collector_id', 'state'], 'sd_terminal_collector_state_idx');
            $table->index(['device_id', 'state'], 'sd_terminal_device_state_idx');
        });

        Schema::create('security_device_terminal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('security_device_terminal_sessions')->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->unsignedInteger('seq');
            $table->text('text');
            $table->timestamp('at');

            $table->unique(['session_id', 'direction', 'seq'], 'sd_terminal_lines_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_terminal_lines');
        Schema::dropIfExists('security_device_terminal_sessions');
    }
};
