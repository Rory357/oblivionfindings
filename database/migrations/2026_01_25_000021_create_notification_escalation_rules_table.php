<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_escalation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->boolean('enabled')->default(false);
            $table->boolean('require_ack')->default(false);
            $table->boolean('must_ack_before_close')->default(false);
            $table->boolean('force_delivery')->default(false);
            $table->unsignedInteger('remind_after_minutes')->default(60);
            $table->unsignedInteger('repeat_every_minutes')->default(60);
            $table->unsignedInteger('max_reminders')->default(3);
            $table->json('escalate_to_role_groups')->nullable();
            $table->json('tiers')->nullable();
            $table->timestamps();

            $table->index(['enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_escalation_rules');
    }
};
