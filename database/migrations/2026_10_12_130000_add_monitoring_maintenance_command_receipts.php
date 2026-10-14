<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_maintenance_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 16);
            $table->char('request_hash', 64);
            $table->foreignId('target_window_id')->nullable()->constrained('monitoring_maintenance_windows')->restrictOnDelete();
            $table->foreignId('window_id')->nullable()->constrained('monitoring_maintenance_windows')->restrictOnDelete();
            $table->string('status', 16);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->json('before_scope')->nullable();
            $table->json('after_scope')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('committed_at')->nullable();
            $table->index(['actor_user_id', 'created_at'], 'monitoring_maintenance_receipts_actor_created');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('monitoring_maintenance_command_receipts') && DB::table('monitoring_maintenance_command_receipts')->exists()) {
            throw new RuntimeException('Retain original maintenance command bindings. Use a forward repair while a saved request can be recovered.');
        }
        Schema::dropIfExists('monitoring_maintenance_command_receipts');
    }
};
