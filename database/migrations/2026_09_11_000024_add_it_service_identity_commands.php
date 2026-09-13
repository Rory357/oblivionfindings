<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_service_identities', function (Blueprint $table): void {
            // Authentication writes last_used_at; configuration has its own version.
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->timestamp('last_rotated_at')->nullable();
        });
        Schema::create('it_service_identity_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_uuid');
            $table->string('operation', 16)->nullable();
            $table->foreignId('service_identity_id')->nullable()->constrained('it_service_identities')->restrictOnDelete();
            $table->char('payload_hash', 64)->nullable();
            $table->string('state', 16);
            $table->unsignedBigInteger('result_version')->nullable();
            $table->timestamps();
            $table->unique(['actor_user_id', 'request_uuid'], 'it_identity_command_actor_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_service_identity_command_receipts');
        Schema::table('it_service_identities', function (Blueprint $table): void {
            $table->dropColumn(['configuration_version', 'last_rotated_at']);
        });
    }
};
