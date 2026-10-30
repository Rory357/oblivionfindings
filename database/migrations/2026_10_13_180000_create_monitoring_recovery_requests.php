<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_recovery_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('dead_letter_id')->constrained('monitoring_dead_letters')->restrictOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->restrictOnDelete();
            $table->string('operation', 16);
            $table->char('request_hash', 64);
            $table->char('evidence_hash', 64);
            $table->string('status', 16);
            $table->json('saved_acknowledgement')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('committed_at', 6)->nullable();
            $table->index(['actor_user_id', 'id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('monitoring_recovery_requests') && DB::table('monitoring_recovery_requests')->exists()) {
            throw new RuntimeException('Retain saved monitoring recovery decisions before rollback.');
        }
        Schema::dropIfExists('monitoring_recovery_requests');
    }
};
