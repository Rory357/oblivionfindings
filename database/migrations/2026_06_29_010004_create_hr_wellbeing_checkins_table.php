<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A logged wellbeing check-in between a manager and a staff member — part of the
 * duty-of-care record. Private check-ins are never shown back to the staff member
 * on My HR; non-private ones surface so the person can acknowledge them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_wellbeing_checkins')) {
            return;
        }

        Schema::create('hr_wellbeing_checkins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('staff_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('welfare'); // 1on1 | welfare | return_to_work
            $table->text('notes')->nullable();
            $table->string('mood')->nullable(); // good | mixed | low
            $table->date('follow_up_date')->nullable();
            $table->boolean('is_private')->default(true);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'staff_user_id']);
            $table->index(['staff_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_wellbeing_checkins');
    }
};
