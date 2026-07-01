<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Triage actions taken against a wellbeing flag — acknowledge, snooze, dismiss.
 * Closes the duty-of-care loop so a manager can mark a flag as "seen, I'm on it",
 * quieten it until a chosen date, or clear it with a reason. The latest action per
 * staff member is joined into getFlaggedStaff(); future-dated snoozes hide the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_wellbeing_flag_actions')) {
            return;
        }

        Schema::create('hr_wellbeing_flag_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('indicator_id')->nullable()->constrained('hr_wellbeing_indicators')->nullOnDelete();
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action'); // acknowledge | snooze | dismiss
            $table->text('reason')->nullable();
            $table->date('snooze_until')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'staff_user_id']);
            $table->index(['staff_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_wellbeing_flag_actions');
    }
};
