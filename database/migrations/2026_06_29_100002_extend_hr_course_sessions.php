<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 3 of the Training Hub handover: the Session wizard collects an online
 * link, a trainer (user FK), a waitlist toggle and notes, and the cancel flow
 * needs to record a soft cancellation + reason (so enrolled staff can be
 * notified) without deleting the row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_course_sessions', function (Blueprint $table) {
            $table->string('online_link')->nullable()->after('location');
            $table->foreignId('trainer_id')->nullable()->after('facilitator')->constrained('users')->nullOnDelete();
            $table->boolean('waitlist_enabled')->default(false)->after('max_participants');
            $table->text('notes')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('notes');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_course_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trainer_id');
            $table->dropColumn([
                'online_link',
                'waitlist_enabled',
                'notes',
                'cancelled_at',
                'cancellation_reason',
            ]);
        });
    }
};
