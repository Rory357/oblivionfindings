<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationDashboardAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Controlled Drugs page resolves the active site's brand colour,
 * and the CD register now enforces balance integrity: for a directional movement
 * the new running balance must reconcile to prior ± the signed quantity (gap 1),
 * on top of the existing mandatory non-self witness.
 */
class ControlledDrugsTest extends TestCase
{
    use RefreshDatabase;

    private function setupCd(): array
    {
        $this->seed(RbacSeeder::class);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view', 'medications.controlled.record', 'medications.controlled.witness', 'clients.update']);
        $witness = $this->makeRoleUser('coordinator');
        $this->grantPermissions($witness, ['medications.controlled.witness']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Morphine sulfate', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        return compact('user', 'witness', 'site', 'client', 'med');
    }

    public function test_cd_entry_rejects_unreconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 9, // should be 8
                'witnessed_by' => $witness->id,
            ])
            ->assertSessionHasErrors('on_hand_after');

        $this->assertSame(0, ClientControlledDrugEntry::count());
    }

    public function test_cd_entry_accepts_reconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientControlledDrugEntry::count());
    }

    public function test_page_serves_brand_colour(): void
    {
        ['user' => $user, 'site' => $site] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledDrugs')
                ->where('site_brand_colour', '#5E35B1')
                ->has('medications', 1)
                ->has('recentEntries')
                ->has('staff')
            );
    }

    public function test_page_exposes_reconciliation_fields_filters_and_current_user(): void
    {
        ['user' => $user] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledDrugs')
                ->has('medications.0', fn (Assert $m) => $m
                    ->where('controlled_drug', true)
                    ->where('overdue_check', true)
                    ->has('last_balance_check_at')
                    ->has('days_since_check')
                    ->has('stock')
                    ->etc()
                )
                ->where('current_user.id', $user->id)
                ->has('date')
                ->has('today')
                ->where('is_today', true)
                ->has('client_id')
                ->has('q')
            );
    }

    public function test_client_filter_scopes_medications(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupCd();
        $other = Client::factory()->create(['site_id' => $client->site_id, 'status' => 'active']);

        $this->actingAs($user)
            ->get('/emar/controlled?client_id='.$other->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('medications', 0)
                ->where('client_id', $other->id)
            );
    }

    public function test_date_param_scopes_movements_window(): void
    {
        ['user' => $user] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled?date=2020-01-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('date', '2020-01-01')
                ->where('is_today', false)
                ->has('recentEntries', 0)
            );
    }

    public function test_loss_report_captures_accountable_officer_and_regulator(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/loss-reports', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'quantity_lost' => 2,
                'unit' => 'tablets',
                'circumstances' => 'Vial dropped and broke during the count.',
                'accountable_officer_name' => 'Jane CDAO',
                'reported_to_regulator' => true,
                'regulator_name' => 'Medsafe',
                'regulator_reference' => 'MS-123',
            ])
            ->assertSessionHasNoErrors();

        $report = ControlledDrugLossReport::first();
        $this->assertSame('Jane CDAO', $report->accountable_officer_name);
        $this->assertTrue((bool) $report->reported_to_regulator);
        $this->assertSame('Medsafe', $report->regulator_name);
        $this->assertSame('MS-123', $report->regulator_reference);
        $this->assertNotNull($report->regulator_notified_at);
    }

    public function test_balance_check_mismatch_links_incident_to_discrepancy(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'witnessed_by' => $witness->id,
                'discrepancy_notes' => 'Two tablets unaccounted for.',
            ])
            ->assertSessionHasNoErrors();

        $discrepancy = ClientControlledDrugDiscrepancy::first();
        $this->assertNotNull($discrepancy);
        $this->assertNotNull($discrepancy->incident_id, 'Balance-check discrepancy should link the auto-created incident.');
    }

    public function test_overdue_cd_check_command_raises_then_balance_check_resolves_alert(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        // No balance check on record → escalation command raises an overdue alert.
        $this->artisan('emar:escalate-overdue-cd-checks')->assertExitCode(0);

        $alert = MedicationDashboardAlert::query()
            ->where('alert_type', 'controlled_overdue_check')
            ->where('client_medication_id', $med->id)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($alert, 'Command should raise an overdue-check alert for an unchecked CD.');

        // Recording a balance check clears the standing alert.
        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 5,
                'actual_balance' => 5,
                'witnessed_by' => $witness->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('resolved', $alert->fresh()->status);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
