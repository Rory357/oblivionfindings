<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_action_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices');
            $table->unsignedInteger('version')->default(0);
            $table->boolean('enabled')->default(false);
            $table->string('consent_mode', 20)->default('attended');
            $table->foreignId('updated_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::table('endpoint_agents', fn (Blueprint $table) => [$table->json('service_actions')->nullable(), $table->json('service_action_observations')->nullable()]);
        Schema::table('endpoint_deliveries', function (Blueprint $table): void {
            $table->longText('encrypted_claim')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->string('result_hash', 64)->nullable();
            $table->json('service_readback')->nullable();
            $table->unsignedBigInteger('readback_sequence')->nullable();
            $table->timestamp('readback_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('endpoint_deliveries', fn (Blueprint $table) => $table->dropColumn(['encrypted_claim', 'claimed_at', 'claim_expires_at', 'result_hash', 'service_readback', 'readback_sequence', 'readback_received_at']));
        Schema::table('endpoint_agents', fn (Blueprint $table) => $table->dropColumn(['service_actions', 'service_action_observations']));
        Schema::dropIfExists('endpoint_action_policies');
    }
};
