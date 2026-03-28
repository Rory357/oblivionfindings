<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lone_worker_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('expected_end_at');
            $table->dateTime('ended_at')->nullable();
            $table->text('location')->nullable();
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->text('activity_description')->nullable();
            $table->integer('check_in_interval_minutes')->default(60);
            $table->dateTime('last_check_in_at')->nullable();
            $table->string('status')->default('active');
            $table->dateTime('emergency_triggered_at')->nullable();
            $table->text('emergency_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('user_id');
            $table->index(['status', 'expected_end_at']);
        });

        Schema::create('lone_worker_check_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lone_worker_session_id');
            $table->dateTime('checked_in_at');
            $table->decimal('location_lat', 10, 7)->nullable();
            $table->decimal('location_lng', 10, 7)->nullable();
            $table->string('status')->default('ok');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('lone_worker_session_id')->references('id')->on('lone_worker_sessions')->cascadeOnDelete();
            $table->index('lone_worker_session_id');
        });

        Schema::create('lone_worker_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lone_worker_session_id');
            $table->string('alert_type');
            $table->dateTime('triggered_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->unsignedBigInteger('escalated_to')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('lone_worker_session_id')->references('id')->on('lone_worker_sessions')->cascadeOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('escalated_to')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('lone_worker_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lone_worker_alerts');
        Schema::dropIfExists('lone_worker_check_ins');
        Schema::dropIfExists('lone_worker_sessions');
    }
};
