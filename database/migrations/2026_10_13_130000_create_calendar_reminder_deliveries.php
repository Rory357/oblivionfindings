<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_calendar_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('hr_calendar_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->dateTime('starts_at');
            $table->dateTime('due_at');
            $table->dateTime('expires_at');
            $table->unsignedInteger('offset_minutes');
            $table->string('channel', 20);
            $table->string('state', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('retry_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->string('outcome', 60)->nullable();
            $table->uuid('receipt_id')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'user_id', 'occurrence_date', 'starts_at', 'offset_minutes', 'channel'], 'hr_cal_delivery_identity');
            $table->index(['state', 'retry_at'], 'hr_cal_delivery_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_calendar_reminder_deliveries');
    }
};
