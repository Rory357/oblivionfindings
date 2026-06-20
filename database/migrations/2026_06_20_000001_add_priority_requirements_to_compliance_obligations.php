<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist `priority` and `requirements` for compliance obligations.
 *
 * StoreComplianceObligationRequest already validates both fields and the
 * redesigned /compliance "Log obligation" wizard captures them, but the
 * original table had no columns for either — so they were silently dropped.
 * Additive + nullable: safe on existing rows.
 *
 * Each column/index is added in its own guarded statement: on this Laravel
 * build every `->addColumn` compiles to a separate `ALTER TABLE … ADD` (MySQL
 * auto-commits each), so a single shared closure could leave a half-applied
 * state if a later statement threw. Independent guards make the migration
 * idempotent and safe to re-run on a partially-migrated database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compliance_obligations', 'priority')) {
            Schema::table('compliance_obligations', function (Blueprint $table) {
                // low / medium / high / critical — mirrors the request enum + obligation drivers.
                $table->string('priority')->default('medium')->after('status');
            });
        }

        if (! Schema::hasColumn('compliance_obligations', 'requirements')) {
            Schema::table('compliance_obligations', function (Blueprint $table) {
                // What the obligation actually requires to be satisfied (free text).
                $table->text('requirements')->nullable()->after('description');
            });
        }

        if (! $this->hasIndex('compliance_obligations', 'compliance_obligations_priority_status_index')) {
            Schema::table('compliance_obligations', function (Blueprint $table) {
                $table->index(['priority', 'status']);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('compliance_obligations', 'compliance_obligations_priority_status_index')) {
            Schema::table('compliance_obligations', function (Blueprint $table) {
                $table->dropIndex(['priority', 'status']);
            });
        }

        foreach (['priority', 'requirements'] as $column) {
            if (Schema::hasColumn('compliance_obligations', $column)) {
                Schema::table('compliance_obligations', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $row) {
            if (($row->Key_name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
