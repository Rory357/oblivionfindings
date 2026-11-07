<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider managers take on and act on H&S items at their own Sites: H&S items only, no restraint access (Stephan, Q4,
 * 31 Oct 2026). One key: hazards.manage, limited to their own Sites by the existing H&S Site scope (provider managers
 * hold no healthSafety.viewAllSites). No restraint keys and no other role changes. RbacSeeder grants the same key and
 * leaves provider managers out of its restraint copy rule, so fresh installs match live.
 *
 * Additive and idempotent: an existing grant is left as it is. down() removes only provider_manager's hazards.manage,
 * the one row up() adds; on live the key was absent before this migration (checked 31 Oct 2026).
 */
return new class extends Migration
{
    private const ROLE = 'provider_manager';

    private const PERMISSION = 'hazards.manage';

    public function up(): void
    {
        if (! Schema::hasTable('role_permission')) {
            return;
        }
        [$role, $permission] = $this->ids();
        if ($role === null || $permission === null) {
            return;
        }
        DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permission')) {
            return;
        }
        [$role, $permission] = $this->ids();
        if ($role === null || $permission === null) {
            return;
        }
        DB::table('role_permission')->where('role_id', $role)->where('permission_id', $permission)->delete();
    }

    /** @return array{0: int|null, 1: int|null} */
    private function ids(): array
    {
        return [
            DB::table('roles')->where('name', self::ROLE)->value('id'),
            DB::table('permissions')->where('key', self::PERMISSION)->value('id'),
        ];
    }
};
