<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REQUIREMENT_LEGACY_UNIQUE = 'hr_compliance_requirements_tenant_id_code_unique';

    private const REQUIREMENT_GLOBAL_UNIQUE = 'hr_compliance_requirements_code_uq';

    private const MATRIX_LEGACY_UNIQUE = 'hr_comp_matrix_tenant_req_role_site_unique';

    private const MATRIX_GLOBAL_UNIQUE = 'hr_comp_matrix_req_role_site_uq';

    private const STATUS_GLOBAL_UNIQUE = 'hr_staff_comp_user_req_uq';

    public function up(): void
    {
        $this->assertNoCanonicalCollisions();

        DB::table('hr_compliance_matrix')
            ->whereNull('site_type')
            ->orWhereRaw("TRIM(site_type) = ''")
            ->update(['site_type' => 'all']);

        Schema::table('hr_compliance_matrix', function (Blueprint $table): void {
            $table->string('site_type')->default('all')->nullable(false)->change();
        });

        // Install the application identities before removing compatibility
        // indexes, so writes are never left without a race-safe constraint.
        if (! Schema::hasIndex('hr_compliance_requirements', self::REQUIREMENT_GLOBAL_UNIQUE)) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->unique('code', self::REQUIREMENT_GLOBAL_UNIQUE);
            });
        }
        if (! Schema::hasIndex('hr_compliance_matrix', self::MATRIX_GLOBAL_UNIQUE)) {
            Schema::table('hr_compliance_matrix', function (Blueprint $table): void {
                $table->unique(
                    ['requirement_id', 'role', 'site_type'],
                    self::MATRIX_GLOBAL_UNIQUE,
                );
            });
        }
        if (! Schema::hasIndex('hr_staff_compliance_status', self::STATUS_GLOBAL_UNIQUE)) {
            Schema::table('hr_staff_compliance_status', function (Blueprint $table): void {
                $table->unique(['user_id', 'requirement_id'], self::STATUS_GLOBAL_UNIQUE);
            });
        }
        if (! Schema::hasIndex('hr_compliance_requirements', 'hr_comp_req_category_active_idx')) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->index(['category', 'is_active'], 'hr_comp_req_category_active_idx');
            });
        }
        if (! Schema::hasIndex('hr_staff_compliance_status', 'hr_staff_comp_status_expiry_idx')) {
            Schema::table('hr_staff_compliance_status', function (Blueprint $table): void {
                $table->index(['status', 'expires_at'], 'hr_staff_comp_status_expiry_idx');
            });
        }

        $this->dropLegacyTenantIndexes();
    }

    public function down(): void
    {
        $this->restoreLegacyTenantIndexes();

        if (Schema::hasIndex('hr_staff_compliance_status', 'hr_staff_comp_status_expiry_idx')) {
            Schema::table('hr_staff_compliance_status', function (Blueprint $table): void {
                $table->dropIndex('hr_staff_comp_status_expiry_idx');
            });
        }
        if (Schema::hasIndex('hr_compliance_requirements', 'hr_comp_req_category_active_idx')) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->dropIndex('hr_comp_req_category_active_idx');
            });
        }
        if (Schema::hasIndex('hr_staff_compliance_status', self::STATUS_GLOBAL_UNIQUE)) {
            Schema::table('hr_staff_compliance_status', function (Blueprint $table): void {
                $table->dropUnique(self::STATUS_GLOBAL_UNIQUE);
            });
        }
        if (Schema::hasIndex('hr_compliance_matrix', self::MATRIX_GLOBAL_UNIQUE)) {
            Schema::table('hr_compliance_matrix', function (Blueprint $table): void {
                $table->dropUnique(self::MATRIX_GLOBAL_UNIQUE);
            });
        }
        if (Schema::hasIndex('hr_compliance_requirements', self::REQUIREMENT_GLOBAL_UNIQUE)) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->dropUnique(self::REQUIREMENT_GLOBAL_UNIQUE);
            });
        }

        Schema::table('hr_compliance_matrix', function (Blueprint $table): void {
            $table->string('site_type')->nullable()->default(null)->change();
        });
        DB::table('hr_compliance_matrix')
            ->whereRaw("LOWER(TRIM(site_type)) = 'all'")
            ->update(['site_type' => null]);
    }

    private function assertNoCanonicalCollisions(): void
    {
        $requirement = DB::table('hr_compliance_requirements')
            ->select('code', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('code')
            ->first();
        if ($requirement !== null) {
            throw new RuntimeException(sprintf(
                'Cannot enforce application compliance requirement identity: code %s has %d rows.',
                $requirement->code,
                $requirement->duplicate_count,
            ));
        }

        $matrix = DB::table('hr_compliance_matrix')
            ->select('requirement_id', 'role', DB::raw("COALESCE(NULLIF(TRIM(site_type), ''), 'all') AS canonical_site_type"), DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('requirement_id', 'role', DB::raw("COALESCE(NULLIF(TRIM(site_type), ''), 'all')"))
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('requirement_id')
            ->first();
        if ($matrix !== null) {
            throw new RuntimeException(sprintf(
                'Cannot enforce application compliance matrix identity: requirement %d, role %s, Site type %s has %d rows.',
                $matrix->requirement_id,
                $matrix->role,
                $matrix->canonical_site_type,
                $matrix->duplicate_count,
            ));
        }

        $status = DB::table('hr_staff_compliance_status')
            ->select('user_id', 'requirement_id', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('user_id', 'requirement_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->first();
        if ($status !== null) {
            throw new RuntimeException(sprintf(
                'Cannot enforce application staff compliance identity: user %d and requirement %d have %d rows.',
                $status->user_id,
                $status->requirement_id,
                $status->duplicate_count,
            ));
        }
    }

    private function dropLegacyTenantIndexes(): void
    {
        $indexes = [
            'hr_compliance_requirements' => [
                self::REQUIREMENT_LEGACY_UNIQUE => 'unique',
                'hr_compliance_requirements_tenant_id_index' => 'index',
                'hr_compliance_requirements_tenant_id_category_is_active_index' => 'index',
            ],
            'hr_compliance_matrix' => [
                self::MATRIX_LEGACY_UNIQUE => 'unique',
                'hr_compliance_matrix_tenant_id_index' => 'index',
            ],
            'hr_staff_compliance_status' => [
                'hr_staff_compliance_status_tenant_id_index' => 'index',
                'hr_staff_compliance_status_tenant_id_status_expires_at_index' => 'index',
            ],
        ];

        foreach ($indexes as $tableName => $tableIndexes) {
            foreach ($tableIndexes as $index => $kind) {
                if (! Schema::hasIndex($tableName, $index)) {
                    continue;
                }
                Schema::table($tableName, function (Blueprint $table) use ($index, $kind): void {
                    $kind === 'unique' ? $table->dropUnique($index) : $table->dropIndex($index);
                });
            }
        }
    }

    private function restoreLegacyTenantIndexes(): void
    {
        if (! Schema::hasIndex('hr_compliance_requirements', self::REQUIREMENT_LEGACY_UNIQUE)) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'code'], self::REQUIREMENT_LEGACY_UNIQUE);
            });
        }
        if (! Schema::hasIndex('hr_compliance_requirements', 'hr_compliance_requirements_tenant_id_index')) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->index('tenant_id', 'hr_compliance_requirements_tenant_id_index');
            });
        }
        if (! Schema::hasIndex('hr_compliance_requirements', 'hr_compliance_requirements_tenant_id_category_is_active_index')) {
            Schema::table('hr_compliance_requirements', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'category', 'is_active'],
                    'hr_compliance_requirements_tenant_id_category_is_active_index',
                );
            });
        }
        if (! Schema::hasIndex('hr_compliance_matrix', self::MATRIX_LEGACY_UNIQUE)) {
            Schema::table('hr_compliance_matrix', function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'requirement_id', 'role', 'site_type'],
                    self::MATRIX_LEGACY_UNIQUE,
                );
            });
        }
        if (! Schema::hasIndex('hr_compliance_matrix', 'hr_compliance_matrix_tenant_id_index')) {
            Schema::table('hr_compliance_matrix', function (Blueprint $table): void {
                $table->index('tenant_id', 'hr_compliance_matrix_tenant_id_index');
            });
        }
        if (! Schema::hasIndex('hr_staff_compliance_status', 'hr_staff_compliance_status_tenant_id_index')) {
            Schema::table('hr_staff_compliance_status', function (Blueprint $table): void {
                $table->index('tenant_id', 'hr_staff_compliance_status_tenant_id_index');
            });
        }
        if (! Schema::hasIndex('hr_staff_compliance_status', 'hr_staff_compliance_status_tenant_id_status_expires_at_index')) {
            Schema::table('hr_staff_compliance_status', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'status', 'expires_at'],
                    'hr_staff_compliance_status_tenant_id_status_expires_at_index',
                );
            });
        }
    }
};
