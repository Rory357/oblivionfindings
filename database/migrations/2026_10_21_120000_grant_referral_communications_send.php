<?php

use App\Domain\Referrals\ReferralPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff messaging (Lane M, 21 Oct 2026): registers `referrals.communications.send` and grants it to
 * the roles whose referral preset holds it (ReferralPermissions::rolePresets(), which RbacSeeder also
 * applies): the service-manager roles (admin, CEO, COO, provider manager) and coordinators. Support
 * workers and portal roles get nothing. Deploys skip seeders, and environments that already ran
 * 2026_10_20_090000 would otherwise never receive the new key.
 *
 * Only adds rows: a second run changes nothing, missing roles are skipped, and other grants and
 * per-user overrides are left alone.
 */
return new class extends Migration
{
    private const KEY = 'referrals.communications.send';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $definition = collect(ReferralPermissions::definitions())->firstWhere('key', self::KEY);
            DB::table('permissions')->insertOrIgnore([...$definition, 'created_at' => now(), 'updated_at' => now()]);
            $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
            foreach (ReferralPermissions::rolePresets() as $role => $keys) {
                if (! in_array(self::KEY, $keys, true)) {
                    continue;
                }
                $roleId = DB::table('roles')->where('name', $role)->value('id');
                if ($roleId !== null) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep the grants: an administrator may have customised them, and role_permission has no provenance.
    }
};
