<?php

use App\Domain\Referrals\Services\ReferralServiceAgreements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance may open (read only) the service agreements it is notified about when a referral creates a
 * draft (Stephan, 23 Oct 2026). Grants ReferralServiceAgreements::READ_ONLY_ROLE_GRANTS; RbacSeeder
 * mirrors it, because deploys run migrations and skip seeders. Agreements stay limited to the Sites the
 * reader can access. Only adds rows: a second run changes nothing and a missing role is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }
        $columns = Schema::getColumnListing('permissions');
        DB::transaction(function () use ($columns): void {
            foreach (ReferralServiceAgreements::PERMISSION_DEFINITIONS as $definition) {
                $row = [...$definition, 'group' => 'service_agreements', 'module' => 'Operations', 'created_at' => now(), 'updated_at' => now()];
                DB::table('permissions')->insertOrIgnore(array_intersect_key($row, array_flip($columns)));
            }
            foreach (ReferralServiceAgreements::READ_ONLY_ROLE_GRANTS as $role => $keys) {
                $roleId = DB::table('roles')->where('name', $role)->value('id');
                if ($roleId === null) {
                    continue;
                }
                foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        });
    }

    public function down(): void
    {
        // Keep role grants on rollback: an administrator may have customised them, and
        // role_permission has no provenance to tell this migration's rows apart.
    }
};
