<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_backup_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->restrictOnDelete();
            $table->string('timezone', 40)->default('Pacific/Auckland');
            $table->string('local_time', 5);
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('retention_days')->default(7);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('medication_backup_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('medication_backup_schedules')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('email_sha256', 64);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'user_id'], 'backup_recipient_unique');
        });
        Schema::create('medication_backup_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('medication_backup_schedules')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->date('nz_date');
            $table->string('state', 20)->default('preparing');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('schedule_version');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->longText('source_snapshot')->nullable(); // Encrypted model cast.
            $table->char('source_sha256', 64)->nullable();
            $table->string('artifact_path', 300)->nullable();
            $table->char('artifact_sha256', 64)->nullable();
            $table->text('password')->nullable(); // Encrypted model cast; never a DTO.
            $table->json('recipient_ids')->nullable();
            $table->char('recipient_sha256', 64)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->unique(['schedule_id', 'nz_date'], 'backup_day_unique');
            $table->index(['state', 'claimed_at'], 'backup_recovery');
        });
        Schema::create('medication_backup_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('medication_backup_deliveries')->restrictOnDelete();
            $table->uuid('token')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('state', 20)->default('sending');
            $table->string('result_code', 80)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
        });
        Schema::create('medication_backup_step_up_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('authenticator_window');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'authenticator_window'], 'backup_authenticator_use_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_backup_step_up_uses');
        Schema::dropIfExists('medication_backup_attempts');
        Schema::dropIfExists('medication_backup_deliveries');
        Schema::dropIfExists('medication_backup_recipients');
        Schema::dropIfExists('medication_backup_schedules');
    }
};
