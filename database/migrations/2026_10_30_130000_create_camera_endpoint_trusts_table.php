<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase A, item A5 (docs/security-devices/GOAL.md): which camera or Protect console certificate a Site
 * trusts. A camera address with a self-signed certificate gets no password or API key until someone
 * has trusted its key ("Trust this camera?"); a changed key is refused until an IT admin replaces it.
 * One row per Site, address and port, so a Protect console is trusted once for all its cameras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camera_endpoint_trusts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('host', 64);
            $table->unsignedInteger('port');

            // What Oblivion trusts: a cURL public-key pin (sha256//…) and the certificate's SHA-256
            // fingerprint as people see it in a browser.
            $table->string('trusted_pin', 64)->nullable();
            $table->string('trusted_fingerprint', 100)->nullable();
            $table->timestamp('trusted_at')->nullable();
            $table->foreignId('trusted_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // The key the address last offered that isn't trusted (yet).
            $table->string('offered_pin', 64)->nullable();
            $table->string('offered_fingerprint', 100)->nullable();
            $table->string('offered_subject', 255)->nullable();
            $table->timestamp('offered_valid_to')->nullable();
            $table->timestamp('offered_at')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'host', 'port'], 'camera_endpoint_trusts_site_host_port_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_endpoint_trusts');
    }
};
