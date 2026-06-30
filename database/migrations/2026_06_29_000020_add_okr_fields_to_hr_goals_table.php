<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_goals', function (Blueprint $table) {
            // RAG confidence — stop deriving "on track" purely from due_date.
            $table->string('confidence')->default('on_track')->after('status'); // on_track, at_risk, off_track
            // Cycle / period spine.
            $table->foreignId('cycle_id')->nullable()->after('parent_goal_id')->constrained('hr_goal_cycles')->nullOnDelete();
            // Check-in cadence powering the "needs you" feed.
            $table->string('checkin_frequency')->default('fortnightly')->after('confidence'); // weekly, fortnightly, monthly, quarterly
            $table->timestamp('last_checkin_at')->nullable()->after('checkin_frequency');

            $table->index(['tenant_id', 'cycle_id']);
            $table->index(['tenant_id', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_goals', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'cycle_id']);
            $table->dropIndex(['tenant_id', 'confidence']);
            $table->dropConstrainedForeignId('cycle_id');
            $table->dropColumn(['confidence', 'checkin_frequency', 'last_checkin_at']);
        });
    }
};
