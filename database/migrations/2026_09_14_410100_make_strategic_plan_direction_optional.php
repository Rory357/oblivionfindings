<?php

use App\Domain\Governance\Models\GovernanceResolutionBinding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strategic plans plain-language pass (2026-09-14).
 *
 * A plan without a written vision or mission used to store the placeholder
 * "TBD", which the board then read as the plan's vision. The statements are
 * now optional (NULL = "not written yet").
 *
 * Existing "TBD" placeholders are cleared, except on plans a resolution has
 * been linked to and not yet used: those resolutions approve the plan's
 * exact saved wording, so clearing the placeholder would silently stop the
 * board's pending approval from applying. The page shows "TBD" there as not
 * written yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('strategic_plans', function (Blueprint $table) {
            $table->text('vision_statement')->nullable()->change();
            $table->text('mission_statement')->nullable()->change();
        });

        $pendingPlanIds = Schema::hasTable('governance_resolution_bindings')
            ? DB::table('governance_resolution_bindings')
                ->where('subject_type', GovernanceResolutionBinding::SUBJECT_STRATEGIC_PLAN)
                ->whereNull('consumed_at')
                ->pluck('subject_id')
                ->all()
            : [];

        foreach (['vision_statement', 'mission_statement'] as $column) {
            DB::table('strategic_plans')
                ->where($column, 'TBD')
                ->when($pendingPlanIds !== [], fn ($query) => $query->whereNotIn('id', $pendingPlanIds))
                ->update([$column => null]);
        }
    }

    public function down(): void
    {
        foreach (['vision_statement', 'mission_statement'] as $column) {
            DB::table('strategic_plans')->whereNull($column)->update([$column => 'TBD']);
        }

        Schema::table('strategic_plans', function (Blueprint $table) {
            $table->text('vision_statement')->nullable(false)->change();
            $table->text('mission_statement')->nullable(false)->change();
        });
    }
};
