<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_restart_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained('devices');
            $table->unsignedInteger('version')->default(0);
            $table->boolean('enabled')->default(false);
            $table->string('consent_mode', 20)->default('attended');
            $table->unsignedInteger('minimum_warning_seconds')->default(60);
            $table->foreignId('updated_by_user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::table('endpoint_agents', fn (Blueprint $table) => [$table->json('restart_actions')->nullable(), $table->json('restart_observations')->nullable()]);
        Schema::table('endpoint_deliveries', function (Blueprint $table): void {
            $table->json('restart_readback')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->foreignId('cancel_requested_by_user_id')->nullable()->constrained('users');
            $table->longText('encrypted_cancellation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('endpoint_deliveries', fn (Blueprint $table) => $table->dropConstrainedForeignId('cancel_requested_by_user_id'));
        Schema::table('endpoint_deliveries', fn (Blueprint $table) => $table->dropColumn(['restart_readback', 'cancel_requested_at', 'encrypted_cancellation']));
        Schema::table('endpoint_agents', fn (Blueprint $table) => $table->dropColumn(['restart_actions', 'restart_observations']));
        Schema::dropIfExists('endpoint_restart_policies');
    }
};
