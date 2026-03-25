<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // shift_reminder/late_clock_in/shift_change/roster_published/timesheet_due/document_expiring/low_fund_balance
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable(); // link, shift_id, client_id, etc.
            $table->string('channel')->default('in_app'); // in_app/push/email/sms
            $table->boolean('is_read')->default(false);
            $table->dateTime('read_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_notifications');
    }
};
