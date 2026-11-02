<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase G (pop-up 61): a device's own web page, opened through the Site's hub. Nothing is
 * opened to the internet. Each session has an unguessable link (only its hash is stored); each
 * page request waits here for the hub to fetch it, and the answer is kept only until it is served.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_web_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('collector_id')->constrained('monitoring_collectors')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scheme', 5); // http | https
            $table->string('address', 45);
            $table->unsignedSmallInteger('port');
            $table->string('trusted_pin', 64)->nullable();
            $table->string('state', 16)->default('open'); // open | closed
            $table->timestamp('last_used_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 24)->nullable();
            $table->timestamps();

            $table->index(['collector_id', 'state'], 'sd_web_sessions_collector_idx');
        });

        Schema::create('security_device_web_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_id')->constrained('security_device_web_sessions')->cascadeOnDelete();
            $table->string('method', 7);
            $table->string('path', 2048);
            $table->json('headers');
            $table->longText('body_base64')->nullable();
            $table->string('state', 8)->default('queued'); // queued | sent | done | failed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->longText('response_body_base64')->nullable();
            $table->string('failure', 32)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'state'], 'sd_web_requests_session_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_web_requests');
        Schema::dropIfExists('security_device_web_sessions');
    }
};
