<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_offboarding_tasks', function (Blueprint $table) {
            $table->foreignId('exit_interview_id')
                ->nullable()
                ->unique('hr_offboarding_tasks_exit_interview_unique')
                ->after('offboarding_checklist_id')
                ->constrained('hr_exit_interviews')
                ->nullOnDelete();
        });

        // Historical repair only: old rows had no identity seam, so link a
        // legacy title match only when it resolves to exactly one candidate.
        DB::table('hr_exit_interviews')
            ->orderBy('id')
            ->chunkById(200, function ($interviews): void {
                foreach ($interviews as $interview) {
                    $candidateIds = DB::table('hr_offboarding_tasks as tasks')
                        ->join('hr_offboarding_checklists as checklists', 'checklists.id', '=', 'tasks.offboarding_checklist_id')
                        ->where('checklists.tenant_id', $interview->tenant_id)
                        ->where('checklists.employee_profile_id', $interview->employee_profile_id)
                        ->whereIn('checklists.status', ['pending', 'in_progress'])
                        ->whereNull('tasks.exit_interview_id')
                        ->where('tasks.status', '!=', 'completed')
                        ->where('tasks.category', 'hr')
                        ->where('tasks.title', 'like', '%exit interview%')
                        ->orderBy('tasks.id')
                        ->limit(2)
                        ->pluck('tasks.id');

                    if ($candidateIds->count() === 1) {
                        DB::table('hr_offboarding_tasks')
                            ->where('id', $candidateIds->first())
                            ->update(['exit_interview_id' => $interview->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('hr_offboarding_tasks', function (Blueprint $table) {
            $table->dropForeign(['exit_interview_id']);
            $table->dropUnique('hr_offboarding_tasks_exit_interview_unique');
            $table->dropColumn('exit_interview_id');
        });
    }
};
