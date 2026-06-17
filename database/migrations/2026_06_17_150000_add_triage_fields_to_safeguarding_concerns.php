<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safeguarding redesign — Step 2 (W4).
 *
 * Persists the first-class triage decision so it is auditable and can drive the
 * lifecycle stage tracker + timeline: who triaged, the substantiation judgement,
 * the chosen path (investigate / refer / no further action), and any rationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safeguarding_concerns', function (Blueprint $table) {
            $table->timestamp('triaged_at')->nullable()->after('status');
            $table->foreignId('triaged_by_user_id')->nullable()->after('triaged_at')
                ->constrained('users')->nullOnDelete();
            // substantiated | needs_enquiry | not_substantiated
            $table->string('triage_substantiation')->nullable()->after('triaged_by_user_id');
            // investigate | refer | no_action
            $table->string('triage_decision')->nullable()->after('triage_substantiation');
            $table->text('triage_notes')->nullable()->after('triage_decision');
        });
    }

    public function down(): void
    {
        Schema::table('safeguarding_concerns', function (Blueprint $table) {
            if (Schema::hasColumn('safeguarding_concerns', 'triaged_by_user_id')) {
                $table->dropConstrainedForeignId('triaged_by_user_id');
            }
            $table->dropColumn([
                'triaged_at',
                'triage_substantiation',
                'triage_decision',
                'triage_notes',
            ]);
        });
    }
};
