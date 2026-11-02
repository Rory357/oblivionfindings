<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase E (E5): encrypted sensor relay. Oblivion keeps one sensors certificate authority (its
 * private key encrypted with the app key) and signs each hub's own relay key, so a gateway that
 * trusts the sensors certificate can talk to its hub over TLS on port 8883.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_device_certificate_authorities', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 32)->unique(); // "sensors"
            $table->text('certificate_pem');
            $table->text('private_key_encrypted');
            $table->unsignedBigInteger('next_serial')->default(2);
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('security_device_hub_relay_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collector_id')->constrained('monitoring_collectors')->cascadeOnDelete();
            $table->text('certificate_pem');
            $table->string('serial', 40);
            $table->char('fingerprint_sha256', 64);
            $table->char('public_key_sha256', 64);
            $table->json('ip_addresses');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['collector_id', 'revoked_at'], 'sd_relay_certs_collector_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_device_hub_relay_certificates');
        Schema::dropIfExists('security_device_certificate_authorities');
    }
};
