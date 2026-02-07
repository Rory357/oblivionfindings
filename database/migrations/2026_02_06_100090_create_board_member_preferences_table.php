<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_member_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_member_id')->unique()->constrained()->onDelete('cascade');
            
            // Communication preferences
            $table->string('timezone')->default('Pacific/Auckland');
            $table->time('quiet_hours_start')->default('22:00');
            $table->time('quiet_hours_end')->default('07:00');
            
            // Digest settings
            $table->string('digest_day')->default('Monday');
            $table->time('digest_time')->default('08:00');
            $table->boolean('digest_enabled')->default(true);
            
            // Notification preferences
            $table->boolean('email_meeting_reminders')->default(true);
            $table->boolean('email_action_items')->default(true);
            $table->boolean('email_compliance_alerts')->default(true);
            $table->boolean('email_resolutions')->default(true);
            
            // Urgent contact
            $table->string('urgent_contact_method')->default('email'); // sms, phone, email
            $table->string('mobile_number')->nullable();
            
            // Accessibility
            $table->string('preferred_format')->default('pdf'); // pdf, html, large_print
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_member_preferences');
    }
};
