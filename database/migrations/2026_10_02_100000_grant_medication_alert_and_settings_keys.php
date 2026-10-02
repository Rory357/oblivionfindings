<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P11 B2 (Q3): house managers choose extra people for medication alerts at
 * their own houses — and later those houses' quiet hours and on-call contact
 * — without managing the rest of Medication Settings. Granted to team_lead
 * and coordinator, plus admin.
 *
 * `medications.settings.manage` predates the grant-migration rule: RbacSeeder
 * has seeded it since 2 June 2026 (79aac1a55) and no migration has granted it
 * before (B1 shipped none). So this is its first grant ever, and only where
 * the key doesn't exist yet — where the seeder already made it, its role
 * grants are left exactly as they are, so a revoked grant stays revoked
 * (B2 C1 review).
 *
 * Deploys don't run seeders, so this grants what RbacSeeder seeds.
 */
return new class extends Migration
{
    private const HOUSE_KEY = 'medications.alerts.manage_house';

    private const HOUSE_DESCRIPTION = 'Choose alert extras, quiet hours and the on-call contact for your own houses';

    private const SETTINGS_KEY = 'medications.settings.manage';

    private const SETTINGS_DESCRIPTION = 'Manage facility medication administration rules';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $this->grant(self::HOUSE_KEY, self::HOUSE_DESCRIPTION, ['admin', 'team_lead', 'coordinator']);
            $this->grant(self::SETTINGS_KEY, self::SETTINGS_DESCRIPTION, ['admin', 'provider_manager', 'coordinator', 'clinical_lead'], onlyIfNew: true);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // Settings management existed before this migration; only the new
        // house key is removed.
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('key', self::HOUSE_KEY)->value('id');
            if ($permissionId === null) {
                return;
            }
            if (Schema::hasTable('role_permission')) {
                DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            }
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('id', $permissionId)->delete();
        });
    }

    /**
     * @param  list<string>  $roles
     * @param  bool  $onlyIfNew  Grant to the roles only when this creates the key.
     */
    private function grant(string $key, string $description, array $roles, bool $onlyIfNew = false): void
    {
        $now = now();
        $created = DB::table('permissions')->insertOrIgnore([
            'key' => $key,
            'description' => $description,
            'group' => 'medications',
            'module' => 'Clinical',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($onlyIfNew && $created === 0) {
            return;
        }
        $permissionId = DB::table('permissions')->where('key', $key)->value('id');

        foreach (DB::table('roles')->whereIn('name', $roles)->pluck('id') as $roleId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
};
