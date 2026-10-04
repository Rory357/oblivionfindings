<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationEvent;
use App\Models\MedicationIdempotencyResult;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\Medication\Controlled\ControlledRegisterService;
use App\Services\Medication\WitnessPinService;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
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
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.controlled.view',
            'medications.controlled.record',
            'medications.controlled.witness',
        ]);
        $witness = $this->makeRoleUser('coordinator');
        $this->grantPermissions($witness, ['medications.controlled.witness']);
        foreach ([$user, $witness] as $staffMember) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $staffMember->id,
                'primary_site_id' => $site->id,
                'is_active' => true,
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => null,
            ]);
        }
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $witness->id,
            'assessor_id' => $user->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_witness_controlled' => true,
            'controlled_drugs' => true,
            'restricted' => false,
            'not_seen_areas' => [],
        ]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => null,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);

        // The recorder also needs current house presence for the witnessed cupboard command.
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour()->utc(),
            'ends_at' => now()->addHours(3)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(),
            'actual_ends_at' => null,
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);

        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Morphine sulfate', 'dosage' => '10mg', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        return compact('user', 'witness', 'site', 'client', 'med');
    }

    public function test_cd_entry_rejects_unreconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'movement_type' => 'going_out',
                'expected_balance' => 10,
                'actual_balance' => 9, // same unreconciled observation as on_hand_after
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 9, // should be 8
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertSessionHasErrors('actual_balance');

        $this->assertSame(0, ClientControlledDrugEntry::count());
    }

    public function test_cd_entry_accepts_reconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'movement_type' => 'going_out',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientControlledDrugEntry::count());
    }

    /**
     * PIN-1: the witness confirms with their own witness PIN, never their
     * login password. Wrong PINs are counted even though the request's
     * transaction rolls back, lock the PIN at the limit, and are audited.
     */
    public function test_cd_witness_confirms_with_their_witness_pin_not_their_login_password(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $entry = fn (string $credential, int $before) => $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'movement_type' => 'going_out',
                'expected_balance' => $before,
                'actual_balance' => $before - 1,
                'quantity' => 1,
                'on_hand_before' => $before,
                'on_hand_after' => $before - 1,
                'witnessed_by' => $witness->id,
                'witness_credential' => $credential,
            ]);

        // Their login password is no longer accepted.
        $entry('password', 10)->assertSessionHasErrors('witness_credential');
        $this->assertSame(1, (int) UserWitnessPin::query()->where('user_id', $witness->id)->value('failed_attempts'));
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.witness_pin.failed')->count());
        $this->assertSame(0, ClientControlledDrugEntry::count());

        // The right PIN records the entry, stamps the method and clears the count.
        $entry(UserFactory::TEST_WITNESS_PIN, 10)->assertSessionHasNoErrors();
        $this->assertSame(1, ClientControlledDrugEntry::count());
        $this->assertSame(WitnessPinService::METHOD, AuditLog::query()
            ->where('action', 'medications.controlled.entry.record')
            ->sole()
            ->meta['witness_method']);
        $this->assertSame(0, (int) UserWitnessPin::query()->where('user_id', $witness->id)->value('failed_attempts'));

        // Five wrong PINs lock it; even the right PIN is refused until it unlocks.
        foreach (range(1, 5) as $attempt) {
            $entry('000001', 9)->assertSessionHasErrors('witness_credential');
        }
        $this->assertTrue(UserWitnessPin::query()->where('user_id', $witness->id)->value('locked_until') !== null);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.witness_pin.locked')->count());
        $entry(UserFactory::TEST_WITNESS_PIN, 9)->assertSessionHasErrors('witness_credential');
        $this->assertStringContainsString(
            'witness PIN is locked after too many wrong attempts',
            session('errors')->first('witness_credential'),
        );
        $this->travel(16)->minutes();
        $entry(UserFactory::TEST_WITNESS_PIN, 9)->assertSessionHasNoErrors();
        $this->assertSame(2, ClientControlledDrugEntry::count());

        // A colleague who hasn't set a PIN can't witness yet.
        UserWitnessPin::query()->where('user_id', $witness->id)->delete();
        $entry(UserFactory::TEST_WITNESS_PIN, 8)->assertSessionHasErrors([
            'witness_credential' => $witness->name.' hasn’t set a witness PIN yet. They can set one in Settings › Witness PIN, or choose someone else.',
        ]);
        $this->assertSame(2, ClientControlledDrugEntry::count());
    }

    public function test_manual_controlled_entry_rejects_queued_and_stale_offline_evidence(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        $this->actingAs($user);
        $this->assertOnlineOnly('/emar/controlled/entries', $this->commandPayload($med, $witness, 'movement'));
    }

    public function test_manual_controlled_balance_check_rejects_queued_and_stale_offline_evidence(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        $this->actingAs($user);
        $this->assertOnlineOnly('/emar/controlled/balance-check', $this->commandPayload($med, $witness, 'count'));
    }

    public function test_manual_controlled_entry_and_balance_accept_online_idempotency_uuids_without_provenance(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        foreach (['movement' => '/emar/controlled/entries', 'count' => '/emar/controlled/balance-check'] as $action => $endpoint) {
            $payload = $this->commandPayload($med, $witness, $action);
            $saved = $this->actingAs($user)->postJson($endpoint, $payload)->assertOk()
                ->assertJsonPath('sync.status', 'saved')->assertJsonPath('sync.duplicate', false)
                ->assertJsonMissingPath('sync.captured_offline_at')->assertJsonMissingPath('sync.origin_device_id');
            $this->actingAs($user)->postJson($endpoint, $payload)->assertOk()
                ->assertJsonPath('sync.status', 'duplicate')->assertJsonPath('sync.duplicate', true)
                ->assertJsonPath('entry.id', $saved->json('entry.id'));
            $audit = AuditLog::query()->where('action', 'medications.controlled.entry.record')
                ->where('auditable_id', $saved->json('entry.id'))->sole();
            $this->assertSame(WitnessPinService::METHOD, $audit->meta['witness_method']);
            $this->assertSame('P07', $audit->meta['source']);
            $this->assertArrayNotHasKey('captured_offline_at', $audit->meta);
            $this->assertArrayNotHasKey('origin_device_id', $audit->meta);
        }
        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->assertDatabaseCount('controlled_product_requests', 2);
        $this->assertSame(2, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame('8.00', $med->stock()->sole()->on_hand);
    }

    public function test_controlled_inertia_forms_redirect_while_json_replays_return_sync_payloads(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        foreach (['movement' => '/emar/controlled/entries', 'count' => '/emar/controlled/balance-check', 'loss' => route('emar.cd_loss.store')] as $action => $endpoint) {
            $payload = $this->commandPayload($med, $witness, $action);
            $this->actingAs($user)->withHeaders(['Accept' => 'text/html, application/xhtml+xml', 'X-Inertia' => 'true'])
                ->from('/emar/controlled')->post($endpoint, $payload)->assertRedirect('/emar/controlled');
            $this->actingAs($user)->postJson($endpoint, $payload)->assertOk()
                ->assertJsonPath('sync.status', 'duplicate')->assertJsonPath('sync.duplicate', true);
        }
        $this->assertDatabaseCount('client_controlled_drug_entries', 3);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseCount('controlled_product_requests', 3);
        $this->assertSame('7.00', $med->stock()->sole()->on_hand);
    }

    public function test_controlled_entry_and_balance_check_replays_remain_durable_after_pruning(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $payloads = [];
        foreach (['movement' => '/emar/controlled/entries', 'count' => '/emar/controlled/balance-check'] as $action => $endpoint) {
            $payload = $this->commandPayload($med, $witness, $action);
            $saved = $this->actingAs($user)->postJson($endpoint, $payload)->assertOk()->assertJsonPath('sync.duplicate', false);
            $payloads[$endpoint] = [$payload, $saved->json('entry.id')];
            $this->assertDatabaseHas('controlled_product_requests', ['actor_id' => $user->id, 'request_uuid' => $payload['client_request_uuid']]);
        }
        MedicationIdempotencyResult::query()->create(['scope' => 'synthetic-prunable', 'request_uuid' => (string) Str::uuid(), 'response_payload' => [], 'expires_at' => now()->addDay()]);
        $this->travel(8)->days();
        $this->currentPresence($user, $client);
        $this->currentPresence($witness, $client);
        $this->assertSame(1, (new MedicationIdempotencyResult)->prunable()->delete());
        foreach ($payloads as $endpoint => [$payload, $entryId]) {
            $this->actingAs($user)->postJson($endpoint, $payload)->assertOk()
                ->assertJsonPath('sync.duplicate', true)->assertJsonPath('entry.id', $entryId);
            $this->actingAs($user)->postJson($endpoint, [...$payload, 'notes' => 'Changed material command evidence'])
                ->assertConflict()->assertJsonPath('sync.status', 'conflict')->assertJsonPath('sync.duplicate', false);
        }
        // Durable receipts never waive the witness's current eligibility.
        $witness->permissionOverrides()->syncWithoutDetaching([Permission::query()->where('key', 'medications.controlled.witness')->sole()->id => ['allowed' => false]]);
        $witness->unsetRelation('permissionOverrides')->unsetRelation('roles');
        [$payload] = $payloads['/emar/controlled/entries'];
        $this->actingAs($user)->postJson('/emar/controlled/entries', $payload)->assertNotFound();
        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->assertDatabaseCount('controlled_product_requests', 2);
        $this->assertSame(2, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame('8.00', $med->stock()->sole()->on_hand);
    }

    public function test_controlled_entry_and_balance_audit_failures_roll_back_receipts_stock_and_replay_bindings(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.controlled.entry.record') {
                throw new RuntimeException('Injected controlled register audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            foreach (['movement' => '/emar/controlled/entries', 'count' => '/emar/controlled/balance-check'] as $action => $endpoint) {
                try {
                    $this->actingAs($user)->postJson($endpoint, $this->commandPayload($med, $witness, $action));
                    $this->fail('The controlled audit failure must escape.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Injected controlled register audit failure.', $exception->getMessage());
                }
                $this->assertDatabaseCount('client_controlled_drug_entries', 0);
                $this->assertDatabaseCount('controlled_product_requests', 0);
                $this->assertSame('10.00', $med->stock()->sole()->on_hand);
                $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.controlled.entry.record']);
            }
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }
    }

    public function test_page_serves_brand_colour(): void
    {
        ['user' => $user, 'site' => $site] = $this->setupCd();

        $this->actingAs($user)
            ->get('/emar/controlled?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                // The product reader still has no equivalent site-brand projection.
                ->where('site_brand_colour', '#5E35B1')
                ->has('product.medicines', 1)
                ->has('product.entries')
                ->has('product.witnesses_by_site.'.$site->id)
            );
    }

    public function test_page_exposes_reconciliation_fields_filters_and_current_user(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $med] = $this->setupCd();
        ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Ordinary medicine sentinel', 'dosage' => '500mg', 'frequency' => 'PRN',
            'controlled_drug' => false, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        $this->actingAs($user)
            ->get('/emar/controlled?q=Morphine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                // ControlledRegister hydrates its search from the page URL.
                ->url('/emar/controlled?q=Morphine')
                ->has('product.medicines', 1)
                ->where('product.medicines.0.id', $med->id)
                ->where('product.medicines.0.client_id', $client->id)
                ->where('product.medicines.0.balance', 10)
                ->where('product.medicines.0.entry_version', null)
                ->where('product.medicines.0.count.last_at', null)
                ->where('product.medicines.0.count.last_entry_id', null)
                // No configured cadence must never become an invented overdue count.
                ->where('product.cadence.configured', false)
                ->where('product.medicines.0.count.state', 'not_configured')
                ->where('product.medicines.0.count.due_at', null)
                ->where('product.medicines.0.count.overdue_at', null)
                ->where('product.current_user_id', $user->id)
                ->where('product.current_user_name', $user->name)
                ->where('product.filters.date', null)
                ->where('product.filters.client_id', null)
                ->where('product.as_at', fn (string $at): bool => Carbon::parse($at)
                    ->setTimezone('Pacific/Auckland')->toDateString() === now('Pacific/Auckland')->toDateString())
            );
    }

    public function test_med_cd_scope_reconciliation_ignores_noncanonical_client_medication_balance_checks(): void
    {
        ['user' => $user, 'witness' => $witness, 'site' => $site, 'client' => $client, 'med' => $med] = $this->setupCd();
        $legitimateAt = now()->subDays(8)->startOfMinute();
        $noncanonicalAt = now()->subDay()->startOfMinute();
        $otherClient = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        $legitimate = ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'unit' => 'tablets',
            'on_hand_before' => '10.00',
            'on_hand_after' => '10.00',
            'recorded_at' => $legitimateAt,
            'recorded_by' => $user->id,
            'witnessed_by' => $witness->id,
        ]);
        $noncanonical = ClientControlledDrugEntry::query()->create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'unit' => 'tablets',
            'on_hand_before' => '10.00',
            'on_hand_after' => '10.00',
            'recorded_at' => $noncanonicalAt,
            'recorded_by' => $user->id,
            'witnessed_by' => $witness->id,
        ]);

        $this->actingAs($user)
            ->get('/emar/controlled?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->has('product.medicines', 1)
                ->where('product.medicines.0.id', $med->id)
                ->where('product.medicines.0.count.last_at', $legitimateAt->toIso8601String())
                ->where('product.medicines.0.count.last_entry_id', $legitimate->id)
                ->where('product.medicines.0.entry_version', $legitimate->id)
                ->where('product.cadence.configured', false)
                ->where('product.medicines.0.count.state', 'not_configured')
                ->has('product.entries', 1)
                ->where('product.entries.0.id', $legitimate->id)
                ->whereNot('product.entries.0.id', $noncanonical->id)
            );
    }

    public function test_client_filter_scopes_medications(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $med] = $this->setupCd();
        $other = Client::factory()->create(['site_id' => $client->site_id, 'status' => 'active']);

        $this->actingAs($user)
            ->get('/emar/controlled')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->has('product.medicines', 1)
                ->where('product.medicines.0.id', $med->id)
                ->has('product.people', 2)
                ->where('product.filters.client_id', null)
            );

        $this->actingAs($user)
            ->get('/emar/controlled?client_id='.$other->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->has('product.medicines', 0)
                ->has('product.entries', 0)
                ->where('product.filters.client_id', $other->id)
            );

        $this->actingAs($user)
            ->get('/emar/controlled?client_id='.$client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->has('product.medicines', 1)
                ->where('product.medicines.0.id', $med->id)
                ->where('product.medicines.0.client_id', $client->id)
                ->where('product.filters.client_id', $client->id)
            );
    }

    public function test_date_param_scopes_movements_window(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $start = Carbon::parse('2020-01-01', 'Pacific/Auckland')->startOfDay();
        $end = $start->copy()->addDay();
        $entryAt = fn (Carbon $at, int $before, int $after) => ClientControlledDrugEntry::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'receipt',
            'quantity' => $after - $before,
            'unit' => 'tablets',
            'on_hand_before' => $before,
            'on_hand_after' => $after,
            'recorded_at' => $at->copy()->utc(),
            'recorded_by' => $user->id,
            'witnessed_by' => $witness->id,
        ]);
        $before = $entryAt($start->copy()->subSecond(), 6, 7);
        $first = $entryAt($start, 7, 8);
        $last = $entryAt($end->copy()->subSecond(), 8, 9);
        $after = $entryAt($end, 9, 10);

        $this->actingAs($user)
            ->get('/emar/controlled')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->has('product.entries', 4)
                ->where('product.entries.0.id', $after->id)
                ->where('product.entries.1.id', $last->id)
                ->where('product.entries.2.id', $first->id)
                ->where('product.entries.3.id', $before->id)
            );

        $this->actingAs($user)
            ->get('/emar/controlled?date=2020-01-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->where('product.filters.date', '2020-01-01')
                ->has('product.entries', 2)
                ->where('product.entries.0.id', $last->id)
                ->where('product.entries.1.id', $first->id)
                ->where('product.medicines.0.balance', 10)
                ->where('product.medicines.0.entry_version', $after->id)
                ->where('product.as_at', fn (string $at): bool => Carbon::parse($at)
                    ->setTimezone('Pacific/Auckland')->toDateString() === now('Pacific/Auckland')->toDateString())
            );

        $this->actingAs($user)
            ->get('/emar/controlled?date=2020-01-03')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/ControlledRegister')
                ->where('product.filters.date', '2020-01-03')
                ->has('product.entries', 0)
            );
    }

    public function test_loss_report_captures_accountable_officer_and_regulator(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/loss-reports', [
                ...$this->controlledCommandHead($med),
                'expected_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'quantity_lost' => 2,
                'quantity' => 2,
                'unit' => 'tablets',
                'circumstances' => 'Vial dropped and broke during the count.',
                'notes' => 'Vial dropped and broke during the count.',
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

    public function test_loss_report_rejects_queued_and_stale_offline_evidence(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        $this->actingAs($user);
        $this->assertOnlineOnly(route('emar.cd_loss.store'), $this->commandPayload($med, $witness, 'loss'));
    }

    public function test_loss_report_replay_is_bound_to_authority_target_and_report_semantics(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $payload = $this->commandPayload($med, $witness, 'loss', ['quantity' => 2]);
        $first = $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload)->assertOk()->assertJsonPath('sync.duplicate', false);
        $reportId = $first->json('target_id');
        $this->assertNotNull($reportId);
        $this->assertSame($first->json('entry_id'), ControlledDrugLossReport::query()->sole()->register_entry_id);
        $event = MedicationEvent::query()->where('kind', 'controlled.loss_report')->sole();
        $this->assertSame($payload['client_request_uuid'], $event->facts['request_uuid']);
        $this->assertFalse(isset($event->facts['captured_offline_at']));
        $this->assertTrue($event->hasValidFingerprint());
        $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload)->assertOk()
            ->assertJsonPath('sync.duplicate', true)->assertJsonPath('target_id', $reportId);
        $this->travel(8)->days();
        $this->currentPresence($user, $client);
        $this->currentPresence($witness, $client);
        (new MedicationIdempotencyResult)->prunable()->delete();
        $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload)->assertOk()
            ->assertJsonPath('sync.duplicate', true)->assertJsonPath('target_id', $reportId);
        $permission = Permission::query()->where('key', 'medications.controlled.record')->sole();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload)->assertForbidden();
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        foreach ([['quantity' => 3], ['notes' => 'Different circumstances'], ['immediate_action_taken' => 'Different immediate action']] as $change) {
            $this->actingAs($user)->postJson(route('emar.cd_loss.store'), [...$payload, ...$change])
                ->assertConflict()->assertJsonPath('sync.status', 'conflict');
        }
        $otherClient = Client::factory()->create(['site_id' => $client->site_id, 'status' => 'active']);
        $otherMed = ClientMedication::factory()->create(['client_id' => $otherClient->id, 'controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $this->actingAs($user)->postJson(route('emar.cd_loss.store'), [...$payload, 'client_id' => $otherClient->id, 'client_medication_id' => $otherMed->id, 'medication_name' => $otherMed->name])
            ->assertConflict()->assertJsonPath('sync.status', 'conflict');
        // A receipt belongs to its actor; another actor cannot bypass current presence using it.
        $otherActor = $this->makeRoleUser('coordinator');
        $this->grantPermissions($otherActor, ['medications.controlled.record']);
        HrEmployeeProfile::factory()->create(['user_id' => $otherActor->id, 'primary_site_id' => $client->site_id, 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
        $this->actingAs($otherActor)->postJson(route('emar.cd_loss.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('presence');
        $this->currentPresence($otherActor, $client);
        $this->actingAs($otherActor)->postJson(route('emar.cd_loss.store'), $payload)->assertConflict()->assertJsonPath('sync.status', 'conflict');
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseCount('controlled_product_requests', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('8.00', $med->stock()->sole()->on_hand);
    }

    public function test_loss_report_and_durable_replay_result_commit_atomically(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        $payload = $this->commandPayload($med, $witness, 'loss');
        $injectFailure = true;
        // The event is appended after the durable receipt inside the same transaction.
        MedicationEvent::creating(function (MedicationEvent $event) use (&$injectFailure, $payload): void {
            if ($injectFailure && ($event->facts['request_uuid'] ?? null) === $payload['client_request_uuid']) {
                throw new RuntimeException('Injected final evidence write failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload);
            $this->fail('The final evidence failure must abort the governing transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected final evidence write failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }
        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('controlled_product_requests', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame('10.00', $med->stock()->sole()->on_hand);
        $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload)->assertOk()->assertJsonPath('sync.duplicate', false);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseCount('controlled_product_requests', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame('9.00', $med->stock()->sole()->on_hand);
    }

    public function test_loss_report_audit_failure_rolls_back_report_incident_and_replay_binding(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();
        $payload = $this->commandPayload($med, $witness, 'loss');
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.controlled.reported') {
                throw new RuntimeException('Injected controlled loss audit failure.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload);
            $this->fail('The controlled loss audit failure must escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected controlled loss audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }
        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('controlled_product_requests', ['actor_id' => $user->id, 'request_uuid' => $payload['client_request_uuid']]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.controlled.reported']);
        $this->assertSame('10.00', $med->stock()->sole()->on_hand);
    }

    public function test_controlled_loss_mutations_require_canonical_local_ownership(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $siteBypassDenials = Permission::query()
            ->whereIn('key', ['clinical.accessAllSites', 'sites.viewAll'])
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => false]])
            ->all();
        $this->assertCount(2, $siteBypassDenials);
        $user->permissionOverrides()->syncWithoutDetaching($siteBypassDenials);
        $user->unsetRelation('permissionOverrides');
        $this->assertFalse($user->canDo('clinical.accessAllSites'));
        $this->assertFalse($user->canDo('sites.viewAll'));

        $foreignSite = Site::factory()->create(['is_active' => true]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $payload = [
            ...$this->controlledCommandHead($medication),
            'medication_name' => 'Controlled loss target',
            'quantity_lost' => 1,
            'unit' => 'tablet',
            'circumstances' => 'Count was short during handover.',
            'immediate_action_taken' => 'Remaining stock was secured and recounted.',
        ];

        $this->actingAs($user)
            ->post(route('emar.cd_loss.store'), [
                ...$payload,
                'client_id' => $foreignClient->id,
                'client_medication_id' => $foreignMedication->id,
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.cd_loss.store'), [
                ...$payload,
                'client_id' => $client->id,
                'client_medication_id' => $foreignMedication->id,
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.cd_loss.store'), [
                ...$payload,
                'client_id' => $foreignClient->id,
                'client_medication_id' => null,
            ])
            ->assertSessionHasErrors('client_medication_id');
        $this->actingAs($user)
            ->post(route('emar.cd_loss.store'), [...$payload, 'client_medication_id' => null])
            ->assertSessionHasErrors('client_medication_id');
        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);

        $localReport = $this->lossReport($client, $medication, $user);
        $foreignReport = $this->lossReport($foreignClient, $foreignMedication, $user);
        $forgedReport = $this->lossReport($client, $foreignMedication, $user);
        // Reach canonical ownership denial with the close command's exact capability.
        $this->grantPermissions($user, ['medications.controlled.manage']);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->assertTrue($user->canDo('medications.controlled.manage'));

        $this->actingAs($user)
            ->post(route('emar.cd_loss.investigate', $foreignReport), [
                ...$this->controlledCommandHead($foreignMedication),
                'notes' => 'Must remain hidden.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.cd_loss.resolve', $forgedReport), [
                ...$this->controlledCommandHead($foreignMedication),
                'resolution_outcome' => 'unexplained', 'notifications_checked' => true,
                'notes' => 'Must remain hidden.',
            ])
            ->assertNotFound();
        $this->assertSame('reported', $foreignReport->fresh()->investigation_status);
        $this->assertSame('reported', $forgedReport->fresh()->investigation_status);

        $this->actingAs($user)
            ->post(route('emar.cd_loss.investigate', $localReport), [
                ...$this->controlledCommandHead($medication),
                'notes' => 'Local investigation opened.',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->grantPermissions($user, ['medications.controlled.manage']);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'provider_manager')->sole()->id]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($user)
            ->post(route('emar.cd_loss.resolve', $localReport), [
                ...$this->controlledCommandHead($medication),
                'resolution_outcome' => 'accidental', 'notifications_checked' => true,
                'notes' => 'Local stock reconciled.',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('resolved', $localReport->fresh()->investigation_status);
        $this->assertSame('10.00', $medication->stock()->sole()->on_hand);
    }

    public function test_balance_check_mismatch_links_incident_to_discrepancy(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $response = $this->actingAs($user)
            ->from('/emar/controlled')
            ->postJson('/emar/controlled/balance-check', [
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'recount_balance' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'discrepancy_notes' => 'Two tablets unaccounted for.',
                'notes' => 'Two tablets unaccounted for.',
                'immediate_action_taken' => 'Remaining stock was secured and the client was checked while a recount began.',
            ]);

        $response->assertOk()->assertJsonStructure(['entry_id', 'counted_entry_id', 'discrepancy_id']);

        $discrepancy = ClientControlledDrugDiscrepancy::first();
        $this->assertNotNull($discrepancy);
        $response->assertJsonPath('discrepancy_id', $discrepancy->id);
        $this->assertSame($response->json('entry_id'), $response->json('counted_entry_id'));
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

    public function test_balance_check_actual_balance_obeys_decimal_10_2_register_limit_without_writes(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();

        // Although stock and discrepancy balances use DECIMAL(12,2), the same
        // actual balance is persisted to client_controlled_drug_entries.quantity,
        // making that DECIMAL(10,2) register column the most restrictive sink.
        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'expected_balance' => '10.00',
                'actual_balance' => '100000000.00',
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertSessionHasErrors('actual_balance');

        $this->assertSame(
            '10.00',
            (string) ClientMedicationStock::query()
                ->where('client_medication_id', $med->id)
                ->sole()
                ->on_hand,
        );
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.controlled.entry.record',
        ]);
        $this->assertSame(
            '99999999.99',
            MedicationStockQuantity::DECIMAL_10_2_MAX,
        );
    }

    private function lossReport(Client $client, ClientMedication $medication, User $reporter): ControlledDrugLossReport
    {
        return ControlledDrugLossReport::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 1,
            'unit' => 'tablet',
            'circumstances' => 'Count was short during handover.',
            'immediate_action_taken' => 'Remaining stock was secured and recounted.',
            'discovered_by' => $reporter->id,
            'discovered_at' => now(),
            'investigation_status' => 'reported',
        ]);
    }

    public function test_balance_check_mismatch_requires_a_truthful_immediate_action_before_any_write(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'recount_balance' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'discrepancy_notes' => 'Two tablets unaccounted for.',
                'notes' => 'Two tablets unaccounted for.',
            ])
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('client_controlled_drug_discrepancies', 0);
        $this->assertDatabaseCount('client_incidents', 0);
    }

    public function test_controlled_loss_requires_a_truthful_immediate_action_before_any_write(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/loss-reports', [
                ...$this->controlledCommandHead($med),
                'expected_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'quantity_lost' => 2,
                'quantity' => 2,
                'unit' => 'tablets',
                'circumstances' => 'Two tablets were missing at handover.',
                'notes' => 'Two tablets were missing at handover.',
            ])
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('client_incidents', 0);
    }

    public function test_overdue_cd_check_command_raises_then_balance_check_resolves_alert(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->configureOverdueCount($med);
        // A deliberately configured real roster boundary is overdue without a count.
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
                ...$this->controlledCommandHead($med),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_med_cd_scope_overdue_command_ignores_noncanonical_recent_balance_checks(): void
    {
        ['user' => $user, 'witness' => $witness, 'site' => $site, 'med' => $med] = $this->setupCd();
        $this->configureOverdueCount($med);
        $otherClient = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        ClientControlledDrugEntry::query()->create([
            'client_id' => $otherClient->id,
            'client_medication_id' => $med->id,
            'entry_type' => 'balance_check',
            'unit' => 'tablets',
            'on_hand_before' => '10.00',
            'on_hand_after' => '10.00',
            'recorded_at' => now(),
            'recorded_by' => $user->id,
            'witnessed_by' => $witness->id,
        ]);

        $this->artisan('emar:escalate-overdue-cd-checks')->assertExitCode(0);

        $this->assertDatabaseHas('medication_dashboard_alerts', [
            'client_medication_id' => $med->id,
            'alert_type' => 'controlled_overdue_check',
            'status' => 'active',
        ]);
    }

    public function test_legacy_entry_preserves_schedule_and_only_explicit_review_sets_nz_class(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $med->forceFill(['cd_schedule' => 5])->save();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                ...$this->commandPayload($med, $witness, 'movement'),
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => UserFactory::TEST_WITNESS_PIN,
                'cd_schedule' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(5, $med->fresh()->cd_schedule);
        $this->assertNull($med->nz_controlled_class);
        $this->grantPermissions($user, ['medications.controlled.manage']);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        app(ControlledRegisterService::class)->perform($user, 'class_review', [
            ...$this->controlledCommandHead($med), 'nz_class' => 'B', 'source' => 'Synthetic reviewed medicines source',
        ]);
        $this->assertSame(5, $med->fresh()->cd_schedule);
        $this->assertSame('B', $med->refresh()->nz_controlled_class);
        $this->assertSame($user->id, $med->controlled_class_reviewed_by);
        $this->assertSame('Synthetic reviewed medicines source', $med->controlled_class_source);
    }

    /** Required canonical command fields for the synthetic register fixture; PINs stay outside replay identity. */
    private function controlledCommandHead(ClientMedication $medication): array
    {
        return [
            'client_medication_id' => $medication->id,
            'client_request_uuid' => (string) Str::uuid(),
            'expected_entry_id' => ClientControlledDrugEntry::query()
                ->where('client_medication_id', $medication->id)
                ->where('client_id', $medication->client_id)
                ->latest('id')
                ->value('id'),
        ];
    }

    /** A current, witnessed typed command; each following command reads a fresh snapshot. */
    private function commandPayload(ClientMedication $medication, User $witness, string $action, array $overrides = []): array
    {
        $balance = $medication->stock()->sole()->on_hand;
        $specific = match ($action) {
            'movement' => ['movement_type' => 'going_out', 'quantity' => 2,
                'actual_balance' => MedicationStockQuantity::subtract($balance, 2),
                'on_hand_before' => $balance, 'on_hand_after' => MedicationStockQuantity::subtract($balance, 2)],
            'count' => ['actual_balance' => $balance],
            'loss' => ['quantity' => 1, 'notes' => 'One tablet could not be reconciled during handover.',
                'immediate_action_taken' => 'Remaining stock was secured and recounted.'],
        };

        return [...$this->controlledCommandHead($medication), 'client_id' => $medication->client_id,
            'medication_name' => $medication->name, 'expected_balance' => $balance,
            'witnessed_by' => $witness->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            ...$specific, ...$overrides];
    }

    private function assertOnlineOnly(string $endpoint, array $payload): void
    {
        foreach ([['queued_offline' => true], ['queued_offline' => true, 'captured_offline_at' => now()->subMinute()->toIso8601String(), 'origin_device_id' => 'synthetic-offline-cupboard']] as $queued) {
            $this->postJson($endpoint, [...$payload, ...$queued])->assertUnprocessable()
                ->assertJsonPath('message', 'Controlled checks need a connection. Your entered values have been kept.');
        }
        foreach (['captured_offline_at' => now()->subMinute()->toIso8601String(), 'origin_device_id' => 'synthetic-offline-cupboard'] as $field => $value) {
            $this->postJson($endpoint, [...$payload, 'queued_offline' => false, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('controlled_product_requests', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertSame('10.00', ClientMedicationStock::query()->where('client_medication_id', $payload['client_medication_id'])->sole()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.controlled.entry.record']);
    }

    private function currentPresence(User $user, Client $client): void
    {
        Shift::factory()->create(['client_id' => $client->id, 'site_id' => $client->site_id,
            'service_context_id' => $client->service_context_id, 'user_id' => $user->id,
            'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(3)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null,
            'status' => 'in_progress', 'is_on_call' => false, 'created_by' => $user->id]);
    }

    private function configureOverdueCount(ClientMedication $medication): void
    {
        AppSetting::query()->updateOrCreate(['key' => ControlledPolicy::COUNT_CADENCE], ['value' => 'shift']);
        $medication->forceFill(['created_at' => now()->subHours(3)->utc()])->save();
        Shift::query()->where('site_id', $medication->client->site_id)->update([
            'starts_at' => now()->subHours(2)->utc(), 'ends_at' => now()->addHours(3)->utc(), 'is_on_call' => false,
        ]);
        $this->assertSame('overdue', app(ControlledPolicy::class)->countStatus($medication->refresh(), now())['status']);
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
