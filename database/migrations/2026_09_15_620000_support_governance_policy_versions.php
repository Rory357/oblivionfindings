<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Start new version" for Governance policies.
 *
 * - A new version is a copy of the approved policy with the next version
 *   number, so every version shares the policy's reference code: the code
 *   is unique per (policy_code, version_number) instead of on its own
 *   (the single-column unique index made every new version fail to save).
 * - `change_summary` keeps the required "What changed" note for the version.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governance_policies')) {
            return;
        }

        if (! Schema::hasColumn('governance_policies', 'change_summary')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                $table->text('change_summary')->nullable()->after('purpose');
            });
        }

        if ($this->hasIndex('governance_policies_policy_code_unique')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                $table->dropUnique('governance_policies_policy_code_unique');
            });
        }

        if (! $this->hasIndex('governance_policies_code_version_unique')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                $table->unique(['policy_code', 'version_number'], 'governance_policies_code_version_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('governance_policies')) {
            return;
        }

        if ($this->hasIndex('governance_policies_code_version_unique')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                $table->dropUnique('governance_policies_code_version_unique');
            });
        }

        // Older versions share their code; give them a distinct code so the
        // single-column unique index can come back.
        $shared = DB::table('governance_policies')
            ->select('policy_code')
            ->groupBy('policy_code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('policy_code');

        foreach ($shared as $code) {
            $rows = DB::table('governance_policies')
                ->where('policy_code', $code)
                ->orderByDesc('version_number')
                ->orderByDesc('id')
                ->get(['id', 'version_number']);

            foreach ($rows->slice(1) as $row) {
                DB::table('governance_policies')
                    ->where('id', $row->id)
                    ->update(['policy_code' => $code.'-V'.$row->version_number.'-'.$row->id]);
            }
        }

        if (! $this->hasIndex('governance_policies_policy_code_unique')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                $table->unique('policy_code', 'governance_policies_policy_code_unique');
            });
        }

        if (Schema::hasColumn('governance_policies', 'change_summary')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                $table->dropColumn('change_summary');
            });
        }
    }

    private function hasIndex(string $index): bool
    {
        return collect(Schema::getIndexes('governance_policies'))
            ->contains(fn (array $definition) => ($definition['name'] ?? null) === $index);
    }
};
