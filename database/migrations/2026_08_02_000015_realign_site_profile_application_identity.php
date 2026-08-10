<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONTACT_IDENTITY_INDEX = 'site_contacts_site_type_name_uq';

    private const PRIMARY_CONTACT_INDEX = 'site_contacts_one_primary_uq';

    public function up(): void
    {
        $this->assertContactIdentitiesCanBeEnforced();
        $this->normalizeContactIdentityValues();
        $this->realignOperationalIndexes();
        $this->addContactIdentityConstraint();
        $this->addOnePrimaryContactConstraint();
        $this->installApplicationWideSitePermission();
    }

    public function down(): void
    {
        $this->dropOnePrimaryContactConstraint();
        $this->dropContactIdentityConstraint();

        foreach ([
            'site_contacts',
            'site_documents',
            'site_checklist_assignments',
            'site_checklist_runs',
        ] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, "{$table}_tenant_id_index")) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->index('tenant_id');
                });
            }
        }

        if (Schema::hasTable('site_documents')
            && Schema::hasIndex('site_documents', 'site_documents_site_expiry_idx')) {
            Schema::table('site_documents', function (Blueprint $table): void {
                $table->dropIndex('site_documents_site_expiry_idx');
            });
        }

        if (Schema::hasTable('site_checklist_assignments')
            && Schema::hasIndex('site_checklist_assignments', 'site_checklist_assignments_site_active_frequency_idx')) {
            Schema::table('site_checklist_assignments', function (Blueprint $table): void {
                $table->dropIndex('site_checklist_assignments_site_active_frequency_idx');
            });
        }

        // Permission rollback is deliberately non-destructive. Removing broad
        // operational access is an explicit RBAC decision, not a schema side effect.
    }

    private function assertContactIdentitiesCanBeEnforced(): void
    {
        if (! Schema::hasTable('site_contacts')) {
            return;
        }

        $blankName = DB::table('site_contacts')
            ->whereRaw("TRIM(COALESCE(name, '')) = ''")
            ->exists();
        if ($blankName) {
            throw new RuntimeException(
                'Cannot enforce canonical Site contact identity while blank contact names exist.',
            );
        }

        $typeExpression = "LOWER(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(type, ''), 'other')), ' ', '_'), '-', '_'))";
        $duplicate = DB::table('site_contacts')
            ->selectRaw("site_id, {$typeExpression} AS canonical_type, LOWER(TRIM(name)) AS canonical_name, COUNT(*) AS duplicate_count")
            ->groupByRaw("site_id, {$typeExpression}, LOWER(TRIM(name))")
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException(
                'Cannot enforce canonical Site contact identity while duplicate Site, type, and name rows exist.',
            );
        }

        $multiplePrimary = DB::table('site_contacts')
            ->select('site_id')
            ->where('is_primary', true)
            ->groupBy('site_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($multiplePrimary !== null) {
            throw new RuntimeException(
                'Cannot enforce one primary Site contact while a Site has multiple primary contacts.',
            );
        }
    }

    private function normalizeContactIdentityValues(): void
    {
        if (! Schema::hasTable('site_contacts')) {
            return;
        }

        DB::table('site_contacts')->update([
            'type' => DB::raw("LOWER(REPLACE(REPLACE(TRIM(COALESCE(NULLIF(type, ''), 'other')), ' ', '_'), '-', '_'))"),
            'name' => DB::raw('TRIM(name)'),
        ]);
    }

    private function realignOperationalIndexes(): void
    {
        foreach ([
            'site_contacts',
            'site_documents',
            'site_checklist_assignments',
            'site_checklist_runs',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, "{$table}_tenant_id_index")) {
                Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                    $blueprint->dropIndex("{$table}_tenant_id_index");
                });
            }
        }

        if (Schema::hasTable('site_documents')
            && ! Schema::hasIndex('site_documents', 'site_documents_site_expiry_idx')) {
            Schema::table('site_documents', function (Blueprint $table): void {
                $table->index(['site_id', 'expiry_date'], 'site_documents_site_expiry_idx');
            });
        }

        if (Schema::hasTable('site_checklist_assignments')
            && ! Schema::hasIndex('site_checklist_assignments', 'site_checklist_assignments_site_active_frequency_idx')) {
            Schema::table('site_checklist_assignments', function (Blueprint $table): void {
                $table->index(
                    ['site_id', 'is_active', 'frequency'],
                    'site_checklist_assignments_site_active_frequency_idx',
                );
            });
        }
    }

    private function addContactIdentityConstraint(): void
    {
        if (! Schema::hasTable('site_contacts')
            || Schema::hasIndex('site_contacts', self::CONTACT_IDENTITY_INDEX)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('site_contacts', function (Blueprint $table): void {
                $table->unique(['site_id', 'type', 'name'], self::CONTACT_IDENTITY_INDEX);
            });

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::CONTACT_IDENTITY_INDEX.
            ' ON site_contacts (site_id, type, LOWER(name))',
        );
    }

    private function dropContactIdentityConstraint(): void
    {
        if (! Schema::hasTable('site_contacts')
            || ! Schema::hasIndex('site_contacts', self::CONTACT_IDENTITY_INDEX)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('site_contacts', function (Blueprint $table): void {
                $table->dropUnique(self::CONTACT_IDENTITY_INDEX);
            });

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::CONTACT_IDENTITY_INDEX);
    }

    private function addOnePrimaryContactConstraint(): void
    {
        if (! Schema::hasTable('site_contacts')
            || Schema::hasIndex('site_contacts', self::PRIMARY_CONTACT_INDEX)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $hasPrimarySiteColumn = Schema::hasColumn('site_contacts', 'application_primary_site_id');

            Schema::table('site_contacts', function (Blueprint $table) use ($hasPrimarySiteColumn): void {
                if (! $hasPrimarySiteColumn) {
                    $table->unsignedBigInteger('application_primary_site_id')
                        ->nullable()
                        ->virtualAs('case when `is_primary` = 1 then `site_id` else null end');
                }
                $table->unique('application_primary_site_id', self::PRIMARY_CONTACT_INDEX);
            });

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::PRIMARY_CONTACT_INDEX.
            ' ON site_contacts (site_id) WHERE is_primary = 1',
        );
    }

    private function dropOnePrimaryContactConstraint(): void
    {
        if (! Schema::hasTable('site_contacts')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (Schema::hasIndex('site_contacts', self::PRIMARY_CONTACT_INDEX)) {
                Schema::table('site_contacts', function (Blueprint $table): void {
                    $table->dropUnique(self::PRIMARY_CONTACT_INDEX);
                });
            }
            if (Schema::hasColumn('site_contacts', 'application_primary_site_id')) {
                Schema::table('site_contacts', function (Blueprint $table): void {
                    $table->dropColumn('application_primary_site_id');
                });
            }

            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::PRIMARY_CONTACT_INDEX);
    }

    private function installApplicationWideSitePermission(): void
    {
        if (! Schema::hasTable('permissions')
            || ! Schema::hasTable('roles')
            || ! Schema::hasTable('role_permission')) {
            return;
        }

        $permission = Permission::query()->updateOrCreate(
            ['key' => 'sites.viewAll'],
            [
                'description' => 'View and manage Sites across the application',
                'group' => 'sites',
                'module' => 'Operations',
            ],
        );

        Role::query()
            ->whereIn('name', ['admin', 'provider_manager'])
            ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
    }
};
