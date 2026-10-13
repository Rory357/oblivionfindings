<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR Lane C4 (EA-076), the approved P06 stock grants, as Stephan decided
 * on 10 Oct: team leads receive and update stock; coordinators and finance
 * keep stock.update as today; support workers do NOT receive deliveries.
 *
 * - a new key, medications.stock.receive: receive ordinary deliveries,
 *   photograph the pack, count, and record medicines going out with the
 *   person and coming back — for house leads (team_lead);
 * - medications.stock.update for house leads (team_lead): orders,
 *   adjustments and removals, count sign-off and controlled receipts with a
 *   witness.
 *
 * Both act only at the houses the person can open: every P06 command runs
 * through MedicationGovernanceScopeService::forMedication (Site scope) and
 * the per-person record gate. stock.receive is not a medication-operations
 * key in ClientPolicy::viewMedications, so it never widens what anyone reads.
 *
 * admin gets the new key too, matching RbacSeeder's rule that admin holds
 * every key outside the restricted list.
 *
 * It only adds rows and runs once, so a grant an admin later removes in
 * Settings › Roles stays removed. Reseed note: RbacSeeder syncs team_lead
 * to its own list, so a reseed drops these grants until the seeder names
 * them (reported to Main; collision file).
 */
return new class extends Migration
{
    private const RECEIVE = 'medications.stock.receive';

    private const UPDATE = 'medications.stock.update';

    private const RECEIVE_ROLES = ['admin', 'team_lead'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore([
                'key' => self::RECEIVE,
                'description' => 'Receive medicine deliveries, photograph packs, count stock and record medicines going out or coming back at their own houses',
                'group' => 'medications',
                'module' => 'Clinical',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $receiveId = DB::table('permissions')->where('key', self::RECEIVE)->value('id');
            foreach (DB::table('roles')->whereIn('name', self::RECEIVE_ROLES)->pluck('id') as $roleId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $receiveId]);
            }

            $teamLeadId = DB::table('roles')->where('name', 'team_lead')->value('id');
            $updateId = DB::table('permissions')->where('key', self::UPDATE)->value('id');
            if ($teamLeadId !== null && $updateId !== null) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $teamLeadId, 'permission_id' => $updateId]);
            }
        });
    }

    /**
     * Removes the new key (its role and per-user grants cascade) and
     * team_lead's stock.update. The seeded team_lead never held it.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('permissions')->where('key', self::RECEIVE)->delete();

            $teamLeadId = DB::table('roles')->where('name', 'team_lead')->value('id');
            $updateId = DB::table('permissions')->where('key', self::UPDATE)->value('id');
            if ($teamLeadId !== null && $updateId !== null) {
                DB::table('role_permission')->where('role_id', $teamLeadId)->where('permission_id', $updateId)->delete();
            }
        });
    }
};
