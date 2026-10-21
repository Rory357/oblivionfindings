<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back-office roles could not read the records their roles are named for
 * (found building the Business support overview, 14 Oct; Stephan: "whatever
 * you think is best", 19 Oct):
 *
 * - cfo held no finance permission at all → the auditor's read-only set.
 * - compliance_lead could not open /compliance → compliance.view and
 *   governance.compliance.view.
 * - risk_lead could not open /governance/risks (its policy already allows
 *   the role) → governance.risks.view.
 *
 * All read-only: approving, posting, paying and managing stay unchanged.
 * Deploys run migrations and skip seeders, so this is the grant; RbacSeeder
 * (CFO_FINANCE_READ, compliance_lead) and GovernancePermissionsSeeder name
 * the same keys. It only adds missing rows, a second run changes nothing,
 * and a grant an admin later removes in Settings › Roles stays removed.
 */
return new class extends Migration
{
    private const GRANTS = [
        'cfo' => [
            'finance.dashboard', 'finance.insights.viewAllSites', 'finance.payments.viewAllSites', 'finance.ledger.view',
            'finance.ap.view', 'finance.ar.view', 'finance.bank.view', 'finance.tax.view', 'finance.assets.view',
            'finance.petty_cash.view', 'finance.reports.view',
        ],
        'compliance_lead' => ['compliance.view', 'governance.compliance.view'],
        'risk_lead' => ['governance.risks.view'],
    ];

    public function up(): void
    {
        $this->each(fn (int $roleId, int $permissionId) => DB::table('role_permission')
            ->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]));
    }

    /**
     * Removes these rows only. role_permission has no provenance, so a grant
     * made by hand before this ran can't be told apart; none of these roles
     * held these keys when it was written.
     */
    public function down(): void
    {
        $this->each(fn (int $roleId, int $permissionId) => DB::table('role_permission')
            ->where('role_id', $roleId)->where('permission_id', $permissionId)->delete());
    }

    private function each(callable $apply): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function () use ($apply): void {
            foreach (self::GRANTS as $role => $keys) {
                $roleId = DB::table('roles')->where('name', $role)->value('id');
                if ($roleId === null) {
                    continue;
                }
                foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                    $apply((int) $roleId, (int) $permissionId);
                }
            }
        });
    }
};
