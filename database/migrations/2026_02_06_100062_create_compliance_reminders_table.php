<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_obligation_id')->constrained()->onDelete('cascade');
            
            // Reminder details
            $table->integer('days_before_due');
            $table->datetime('scheduled_at');
            $table->datetime('sent_at')->nullable();
            
            // Recipients
            $table->json('notified_users'); // Array of user IDs
            
            // Status: pending, sent, failed, acknowledged
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            
            // Escalation tracking
            $table->boolean('is_escalation')->default(false);
            $table->integer('escalation_level')->default(0);
            
            $table->timestamps();

            $table->index(['scheduled_at', 'status']);
            $table->index(['compliance_obligation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_reminders');
    }
};
