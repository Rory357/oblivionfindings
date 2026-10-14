<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_monitor_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 16);
            $table->char('request_hash', 64);
            $table->foreignId('target_monitor_id')->nullable()->constrained('monitors')->restrictOnDelete();
            $table->foreignId('monitor_id')->nullable()->constrained('monitors')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('devices')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('status', 16);
            $table->json('saved_snapshot')->nullable();
            $table->char('definition_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('committed_at')->nullable();
            $table->index(['actor_user_id', 'created_at'], 'native_monitor_receipts_actor_created');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('native_monitor_command_receipts') && DB::table('native_monitor_command_receipts')->exists()) {
            throw new RuntimeException('Retain original native monitor request receipts. Use a forward repair while saves can be recovered.');
        }
        Schema::dropIfExists('native_monitor_command_receipts');
    }
};
