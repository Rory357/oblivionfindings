<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_UNIQUE = 'hr_onboarding_templates_tenant_id_role_site_type_unique';

    private const GLOBAL_UNIQUE = 'hr_onboarding_templates_role_site_uq';

    public function up(): void
    {
        $collision = DB::table('hr_onboarding_templates')
            ->select('role', 'site_type', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('role', 'site_type')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('role')
            ->orderBy('site_type')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(sprintf(
                'Cannot enforce application onboarding-template identity: role %s and Site type %s have %d rows.',
                $collision->role,
                $collision->site_type,
                $collision->duplicate_count,
            ));
        }

        // Install the application identity before removing the compatibility
        // identity so concurrent writes are never left without a constraint.
        if (! Schema::hasIndex('hr_onboarding_templates', self::GLOBAL_UNIQUE)) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->unique(['role', 'site_type'], self::GLOBAL_UNIQUE);
            });
        }
        if (! Schema::hasIndex('hr_onboarding_templates', 'hr_onboarding_templates_active_role_idx')) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->index(['is_active', 'role'], 'hr_onboarding_templates_active_role_idx');
            });
        }

        if (Schema::hasIndex('hr_onboarding_templates', self::LEGACY_UNIQUE)) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_UNIQUE);
            });
        }
        if (Schema::hasIndex('hr_onboarding_templates', 'hr_onboarding_templates_tenant_id_index')) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->dropIndex('hr_onboarding_templates_tenant_id_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('hr_onboarding_templates', self::LEGACY_UNIQUE)) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'role', 'site_type'], self::LEGACY_UNIQUE);
            });
        }
        if (! Schema::hasIndex('hr_onboarding_templates', 'hr_onboarding_templates_tenant_id_index')) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->index('tenant_id', 'hr_onboarding_templates_tenant_id_index');
            });
        }

        if (Schema::hasIndex('hr_onboarding_templates', 'hr_onboarding_templates_active_role_idx')) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->dropIndex('hr_onboarding_templates_active_role_idx');
            });
        }
        if (Schema::hasIndex('hr_onboarding_templates', self::GLOBAL_UNIQUE)) {
            Schema::table('hr_onboarding_templates', function (Blueprint $table): void {
                $table->dropUnique(self::GLOBAL_UNIQUE);
            });
        }
    }
};
