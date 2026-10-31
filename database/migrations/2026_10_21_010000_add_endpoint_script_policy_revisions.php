<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_script_policy_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices');
            $table->unsignedInteger('version');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->char('assignment_fingerprint', 64);
            $table->text('receipt_json');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['device_id', 'version'], 'endpoint_script_policy_version');
            $table->unique(['actor_user_id', 'request_key'], 'endpoint_script_policy_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_script_policy_revisions');
    }
};
