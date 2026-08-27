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
        $this->grantPermissions($user, [
            'medications.view',
            'medications.controlled.view',
            'medications.controlled.record',
            'medications.controlled.witness',
        ]);
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
                'immediate_action_taken' => 'The area was isolated, remaining stock was secured, and the client was checked.',
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
        $this->assertSame(
            'The area was isolated, remaining stock was secured, and the client was checked.',
            $report->immediate_action_taken,
        );
    }

    public function test_loss_report_replay_is_bound_to_authority_target_and_report_semantics(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $requestUuid = '2e8b577c-c474-43ca-a533-8a1ed1cb65fa';
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 2,
            'unit' => 'tablets',
            'circumstances' => 'Count was short during handover.',
            'immediate_action_taken' => 'Remaining stock was secured.',
            'client_request_uuid' => $requestUuid,
        ];

        $first = $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $reportId = $first->json('report.id');

        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true)
            ->assertJsonPath('report.id', $reportId);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);

        $recordPermission = Permission::query()
            ->where('key', 'medications.controlled.record')
            ->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $recordPermission->id => ['allowed' => false],
        ]);
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');

        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertForbidden();

        $user->permissionOverrides()->syncWithoutDetaching([
            $recordPermission->id => ['allowed' => true],
        ]);
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');

        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), [
                ...$payload,
                'quantity_lost' => 3,
            ])
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $secondActor = $this->makeRoleUser('coordinator');
        $this->grantPermissions($secondActor, ['medications.controlled.record']);
        $this->actingAs($secondActor)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
    }

    public function test_balance_check_mismatch_links_incident_to_discrepancy(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client] = $this->setupCd();

        $response = $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'witnessed_by' => $witness->id,
                'discrepancy_notes' => 'Two tablets unaccounted for.',
                'immediate_action_taken' => 'Remaining stock was secured and the client was checked while a recount began.',
            ]);

        $response
            ->assertRedirect('/emar/controlled')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $discrepancy = ClientControlledDrugDiscrepancy::first();
        $this->assertNotNull($discrepancy);
        $this->assertNotNull($discrepancy->incident_id, 'Balance-check discrepancy should link the auto-created incident.');
        $this->assertDatabaseHas('client_incidents', [
            'id' => $discrepancy->incident_id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'status' => 'submitted',
            'immediate_action_taken' => 'Remaining stock was secured and the client was checked while a recount began.',
        ]);
        $this->assertSame(
            'Remaining stock was secured and the client was checked while a recount began.',
            $discrepancy->immediate_action_taken,
        );
    }

    public function test_balance_check_mismatch_requires_a_truthful_immediate_action_before_any_write(): void
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
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertDatabaseCount('client_incidents', 0);
    }

    public function test_controlled_loss_requires_a_truthful_immediate_action_before_any_write(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/loss-reports', [
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'quantity_lost' => 2,
                'unit' => 'tablets',
                'circumstances' => 'Two tablets were missing at handover.',
            ])
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('client_incidents', 0);
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

    public function test_cd_entry_classifies_schedule_on_medication(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

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
                'cd_schedule' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $med->fresh()->cd_schedule);
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
