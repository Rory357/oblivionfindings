<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationIdempotencyResult;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Support\Medication\MedicationStockQuantity;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 9, // should be 8
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
            ])
            ->assertSessionHasErrors('on_hand_after');

        $this->assertSame(0, ClientControlledDrugEntry::count());
    }

    public function test_cd_entry_accepts_reconciled_balance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/entries', [
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientControlledDrugEntry::count());
    }

    public function test_manual_controlled_entry_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $basePayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'entry_type' => 'administration',
            'quantity' => 2,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
        ];
        $validUuid = 'f5904d4e-74ca-4719-b922-3a16a09bd04b';
        $validCapturedAt = now()->subMinutes(5)->toIso8601String();
        $invalidSubmissions = [
            [[
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => 'cd-trolley',
                'queued_offline' => true,
            ], 'client_request_uuid'],
            [[
                'client_request_uuid' => $validUuid,
                'origin_device_id' => 'cd-trolley',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => '2026-04-30 09:25:00',
                'origin_device_id' => 'cd-trolley',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => ' ',
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'queued_offline' => false,
            ], 'captured_offline_at'],
        ];

        foreach ($invalidSubmissions as [$submission, $errorField]) {
            $this->actingAs($user)
                ->postJson('/emar/controlled/entries', [
                    ...$basePayload,
                    ...$submission,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.controlled.entry.record',
        ]);
        $this->assertSame('10.00', (string) ClientMedicationStock::query()
            ->where('client_medication_id', $med->id)
            ->sole()
            ->on_hand);
    }

    public function test_manual_controlled_balance_check_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $basePayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'expected_balance' => 10,
            'actual_balance' => 10,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
        ];
        $validUuid = 'ace1032d-7954-45ca-8235-bbd0c6c721c3';
        $validCapturedAt = now()->subMinutes(5)->toIso8601String();
        $invalidSubmissions = [
            [[
                'client_request_uuid' => 'not-a-uuid',
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => 'cd-trolley',
                'queued_offline' => true,
            ], 'client_request_uuid'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                'client_request_uuid' => $validUuid,
                'captured_offline_at' => $validCapturedAt,
                'origin_device_id' => str_repeat('d', 129),
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                'client_request_uuid' => $validUuid,
                'origin_device_id' => 'cd-trolley',
            ], 'origin_device_id'],
        ];

        foreach ($invalidSubmissions as [$submission, $errorField]) {
            $this->actingAs($user)
                ->postJson('/emar/controlled/balance-check', [
                    ...$basePayload,
                    ...$submission,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($errorField);
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.controlled.balance_check.record',
        ]);
        $this->assertSame('10.00', (string) ClientMedicationStock::query()
            ->where('client_medication_id', $med->id)
            ->sole()
            ->on_hand);
    }

    public function test_manual_controlled_entry_and_balance_accept_online_idempotency_uuids_without_provenance(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $entryPayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'entry_type' => 'administration',
            'quantity' => 2,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => '203a73cc-306a-4648-9390-e11e611bf4b0',
        ];
        $balancePayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'expected_balance' => 8,
            'actual_balance' => 8,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => 'e2c696cb-70f1-42cb-a3d1-5e6f419cb1c1',
        ];

        foreach ([
            '/emar/controlled/entries' => $entryPayload,
            '/emar/controlled/balance-check' => $balancePayload,
        ] as $endpoint => $payload) {
            $this->actingAs($user)
                ->postJson($endpoint, $payload)
                ->assertOk()
                ->assertJsonPath('sync.status', 'processed')
                ->assertJsonPath('sync.queued_offline', false)
                ->assertJsonMissingPath('sync.captured_offline_at')
                ->assertJsonMissingPath('sync.origin_device_id');
            $this->actingAs($user)
                ->postJson($endpoint, $payload)
                ->assertOk()
                ->assertJsonPath('sync.status', 'duplicate')
                ->assertJsonPath('sync.duplicate', true);
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.balance_check.record')->count());
        foreach (AuditLog::query()
            ->whereIn('action', [
                'medications.controlled.entry.record',
                'medications.controlled.balance_check.record',
            ])
            ->get() as $audit) {
            $this->assertFalse($audit->meta['queued_offline'] ?? true);
            $this->assertNull($audit->meta['captured_offline_at'] ?? null);
            $this->assertNull($audit->meta['origin_device_id'] ?? null);
        }
    }

    public function test_controlled_inertia_forms_redirect_while_json_replays_return_sync_payloads(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $entryPayload = [
            'client_medication_id' => $medication->id,
            'client_id' => $client->id,
            'medication_name' => $medication->name,
            'entry_type' => 'administration',
            'quantity' => 2,
            'on_hand_before' => 10,
            'on_hand_after' => 8,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => '674fc9fa-b1ca-4272-9441-80a02382ee0f',
        ];
        $balancePayload = [
            'client_medication_id' => $medication->id,
            'client_id' => $client->id,
            'medication_name' => $medication->name,
            'expected_balance' => 8,
            'actual_balance' => 8,
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => '9b5ee527-524e-4e5b-9a67-da8e4e6a9e04',
        ];
        $lossPayload = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 1,
            'circumstances' => 'One tablet could not be reconciled during handover.',
            'immediate_action_taken' => 'Remaining stock was secured and recounted.',
            'client_request_uuid' => 'b9854cfe-a52b-4826-a335-cf43b94548bd',
        ];
        $headers = [
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Inertia' => 'true',
        ];

        foreach ([
            '/emar/controlled/entries' => $entryPayload,
            '/emar/controlled/balance-check' => $balancePayload,
            route('emar.cd_loss.store') => $lossPayload,
        ] as $endpoint => $payload) {
            $this->actingAs($user)
                ->withHeaders($headers)
                ->from('/emar/controlled')
                ->post($endpoint, $payload)
                ->assertRedirect('/emar/controlled');

            $this->actingAs($user)
                ->postJson($endpoint, $payload)
                ->assertOk()
                ->assertJsonPath('sync.status', 'duplicate')
                ->assertJsonPath('sync.duplicate', true);
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
    }

    public function test_controlled_entry_and_balance_check_replays_remain_durable_after_pruning(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $entryRequestUuid = '6f9c91a3-10e6-43cd-86e4-86863d39fb8a';
        $balanceRequestUuid = 'af890a74-801f-49ac-9a12-2c7f3203243c';
        // Browser queue timestamps use the RFC 3339 extended `.sssZ` shape.
        $entryCapturedAt = now()->subMinutes(20)->utc()->format('Y-m-d\TH:i:s.v\Z');
        $balanceCapturedAt = now()->subMinutes(10)->toIso8601String();
        $entryPayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'entry_type' => 'administration',
            'quantity' => '2.00',
            'on_hand_before' => '10.00',
            'on_hand_after' => '8.00',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => $entryRequestUuid,
            'captured_offline_at' => $entryCapturedAt,
            'origin_device_id' => 'cd-trolley-01',
            'queued_offline' => true,
        ];
        $balancePayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'expected_balance' => '8.00',
            'actual_balance' => '8.00',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => $balanceRequestUuid,
            'captured_offline_at' => $balanceCapturedAt,
            'origin_device_id' => 'cd-trolley-02',
            'queued_offline' => true,
        ];

        $entry = $this->actingAs($user)
            ->postJson('/emar/controlled/entries', $entryPayload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $balance = $this->actingAs($user)
            ->postJson('/emar/controlled/balance-check', $balancePayload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);

        $entryId = $entry->json('entry.id');
        $balanceEntryId = $balance->json('entry.id');
        $this->assertOfflineProvenance(
            AuditLog::query()
                ->where('action', 'medications.controlled.entry.record')
                ->where('auditable_id', $entryId)
                ->sole()
                ->meta,
            $entryRequestUuid,
            $entryCapturedAt,
            'cd-trolley-01',
        );
        $this->assertOfflineProvenance(
            AuditLog::query()
                ->where('action', 'medications.controlled.balance_check.record')
                ->where('auditable_id', $balanceEntryId)
                ->sole()
                ->meta,
            $balanceRequestUuid,
            $balanceCapturedAt,
            'cd-trolley-02',
        );
        foreach ([
            $entryId => $entryCapturedAt,
            $balanceEntryId => $balanceCapturedAt,
        ] as $receiptId => $capturedAt) {
            $receipt = ClientControlledDrugEntry::query()->findOrFail($receiptId);
            $captured = Carbon::parse($capturedAt);
            $this->assertTrue($receipt->recorded_at->greaterThan($captured));
            $this->assertTrue($receipt->created_at->greaterThan($captured));
        }
        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => 'emar-controlled-entry',
            'request_uuid' => $entryRequestUuid,
            'expires_at' => null,
        ]);
        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => 'emar-controlled-balance-check',
            'request_uuid' => $balanceRequestUuid,
            'expires_at' => null,
        ]);

        $this->travel(8)->days();
        // Preserve the captured-time Shift evidence bound to the offline
        // submission. A later current Shift is separate evidence and must not
        // rewrite the historical presence record used by an exact replay.
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => null,
            'user_id' => $witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);
        $this->assertSame(0, (new MedicationIdempotencyResult)->prunable()->delete());

        $this->actingAs($user)
            ->postJson('/emar/controlled/entries', $entryPayload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true)
            ->assertJsonPath('entry.id', $entryId);
        $this->actingAs($user)
            ->postJson('/emar/controlled/balance-check', $balancePayload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true)
            ->assertJsonPath('entry.id', $balanceEntryId);

        $this->actingAs($user)
            ->postJson('/emar/controlled/entries', [
                ...$entryPayload,
                'origin_device_id' => 'cd-trolley-changed',
            ])
            ->assertStatus(409)
            ->assertJsonPath('sync.status', 'conflict')
            ->assertJsonPath('sync.duplicate', false);
        $this->actingAs($user)
            ->postJson('/emar/controlled/balance-check', [
                ...$balancePayload,
                'captured_offline_at' => now()->subMinute()->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonPath('sync.status', 'conflict')
            ->assertJsonPath('sync.duplicate', false);

        $this->assertDatabaseCount('client_controlled_drug_entries', 2);
        $this->assertDatabaseCount('medication_idempotency_results', 2);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.entry.record')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.controlled.balance_check.record')->count());
        $this->assertSame(
            '8.00',
            (string) ClientMedicationStock::query()
                ->where('client_medication_id', $med->id)
                ->sole()
                ->on_hand,
        );
    }

    public function test_controlled_offline_entry_and_balance_audit_failures_roll_back_receipts_stock_and_replay_bindings(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();
        $stock = ClientMedicationStock::query()
            ->where('client_medication_id', $med->id)
            ->sole();
        $failAction = 'medications.controlled.entry.record';
        AuditLog::creating(function (AuditLog $audit) use (&$failAction): void {
            if ($audit->action === $failAction) {
                throw new RuntimeException('Injected '.$failAction.' failure.');
            }
        });
        $entryPayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'entry_type' => 'administration',
            'quantity' => '2.00',
            'on_hand_before' => '10.00',
            'on_hand_after' => '8.00',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => '3fe2f570-9174-413f-b7f6-fefee4b45d98',
            'captured_offline_at' => now()->subMinutes(20)->toIso8601String(),
            'origin_device_id' => 'cd-trolley-audit-failure',
            'queued_offline' => true,
        ];

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJson('/emar/controlled/entries', $entryPayload);
            $this->fail('The controlled-entry audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medications.controlled.entry.record failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertSame('10.00', (string) $stock->refresh()->on_hand);

        $failAction = 'medications.controlled.balance_check.record';
        $balancePayload = [
            'client_medication_id' => $med->id,
            'client_id' => $client->id,
            'medication_name' => $med->name,
            'expected_balance' => '10.00',
            'actual_balance' => '10.00',
            'witnessed_by' => $witness->id,
            'witness_credential' => 'password',
            'client_request_uuid' => '69fcebe9-0c00-456d-9eef-a9771a621616',
            'captured_offline_at' => now()->subMinutes(10)->toIso8601String(),
            'origin_device_id' => 'cd-trolley-audit-failure',
            'queued_offline' => true,
        ];

        try {
            $this->actingAs($user)->postJson('/emar/controlled/balance-check', $balancePayload);
            $this->fail('The controlled balance-check audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medications.controlled.balance_check.record failure.', $exception->getMessage());
        } finally {
            $failAction = '';
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertSame('10.00', (string) $stock->refresh()->on_hand);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.controlled.entry.record']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.controlled.balance_check.record']);
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

    public function test_loss_report_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $uuid = '69c6e9e6-d855-4257-9419-b310e958d8de';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $base = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 1,
            'circumstances' => 'Count was short during handover.',
            'immediate_action_taken' => 'Remaining stock was secured and recounted.',
        ];
        $invalid = [
            [[...$base, 'queued_offline' => true], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => '2026-04-30 09:25:00',
                'origin_device_id' => 'loss-device',
                'queued_offline' => true,
            ], 'captured_offline_at'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'queued_offline' => true,
            ], 'origin_device_id'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => $capturedAt,
                'origin_device_id' => 'loss-device',
                'queued_offline' => false,
            ], 'captured_offline_at'],
        ];

        foreach ($invalid as [$payload, $field]) {
            $this->actingAs($user)
                ->postJson(route('emar.cd_loss.store'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
    }

    public function test_loss_report_replay_is_bound_to_authority_target_and_report_semantics(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $requestUuid = '2e8b577c-c474-43ca-a533-8a1ed1cb65fa';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 2,
            'unit' => 'tablets',
            'circumstances' => 'Count was short during handover.',
            'immediate_action_taken' => 'Remaining stock was secured.',
            'client_request_uuid' => $requestUuid,
            'captured_offline_at' => $capturedAt,
            'origin_device_id' => 'cd-loss-device-01',
            'queued_offline' => true,
        ];

        $first = $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $reportId = $first->json('report.id');
        $lossAudit = AuditLog::query()
            ->where('action', 'medications.controlled.loss.report')
            ->where('auditable_id', $reportId)
            ->sole();
        $this->assertSame($requestUuid, $lossAudit->meta['client_request_uuid'] ?? null);
        $this->assertSame($capturedAt, $lossAudit->meta['captured_offline_at'] ?? null);
        $this->assertSame('cd-loss-device-01', $lossAudit->meta['origin_device_id'] ?? null);
        $this->assertTrue($lossAudit->meta['queued_offline'] ?? false);
        $this->assertNull($first->json('idempotency_binding'));

        $retry = $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true)
            ->assertJsonPath('report.id', $reportId);
        $this->assertNull($retry->json('idempotency_binding'));
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseHas('medication_idempotency_results', [
            'scope' => 'emar-controlled-loss-report',
            'request_uuid' => $requestUuid,
        ]);
        $this->assertNull(MedicationIdempotencyResult::query()->sole()->expires_at);

        $this->travel(8)->days();
        $this->assertSame(0, (new MedicationIdempotencyResult)->prunable()->delete());
        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', true)
            ->assertJsonPath('report.id', $reportId);

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

        foreach ([
            ['captured_offline_at' => now()->subMinute()->toIso8601String()],
            ['origin_device_id' => 'cd-loss-device-02'],
        ] as $provenanceChange) {
            $this->actingAs($user)
                ->postJson(route('emar.cd_loss.store'), [
                    ...$payload,
                    ...$provenanceChange,
                ])
                ->assertConflict()
                ->assertJsonPath('sync.status', 'conflict');
        }
        $onlineReplay = $payload;
        unset($onlineReplay['captured_offline_at'], $onlineReplay['origin_device_id']);
        $onlineReplay['queued_offline'] = false;
        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $onlineReplay)
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $secondClient = Client::factory()->create([
            'site_id' => $client->site_id,
            'status' => 'active',
        ]);
        $secondMedication = ClientMedication::query()->create([
            'client_id' => $secondClient->id,
            'name' => 'Oxycodone controlled tablets',
            'dosage' => '5mg',
            'frequency' => 'PRN',
            'controlled_drug' => true,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), [
                ...$payload,
                'client_id' => $secondClient->id,
                'client_medication_id' => $secondMedication->id,
                'medication_name' => $secondMedication->name,
            ])
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);

        $secondActor = $this->makeRoleUser('coordinator');
        $this->grantPermissions($secondActor, ['medications.controlled.record']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $secondActor->id,
            'primary_site_id' => $client->site_id,
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
        ]);
        $this->actingAs($secondActor)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
    }

    public function test_loss_report_and_durable_replay_result_commit_atomically(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $requestUuid = '378ef4bc-eebc-4a63-bf75-96d885216120';
        $injectFailure = true;
        MedicationIdempotencyResult::creating(
            function (MedicationIdempotencyResult $result) use (&$injectFailure, $requestUuid): void {
                if ($injectFailure && $result->request_uuid === $requestUuid) {
                    throw new RuntimeException('Injected durable replay write failure.');
                }
            },
        );
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity_lost' => 1,
            'unit' => 'tablets',
            'circumstances' => 'One tablet could not be reconciled during handover.',
            'immediate_action_taken' => 'Remaining stock was secured and recounted.',
            'client_request_uuid' => $requestUuid,
        ];

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJson(route('emar.cd_loss.store'), $payload);
            $this->fail('The injected durable replay failure should abort the governing transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected durable replay write failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertDatabaseCount('client_incidents', 0);

        $this->actingAs($user)
            ->postJson(route('emar.cd_loss.store'), $payload)
            ->assertOk()
            ->assertJsonPath('sync.duplicate', false);
        $this->assertDatabaseCount('controlled_drug_loss_reports', 1);
        $this->assertDatabaseCount('medication_idempotency_results', 1);
        $this->assertDatabaseCount('client_incidents', 1);
    }

    public function test_loss_report_audit_failure_rolls_back_report_incident_and_replay_binding(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupCd();
        $requestUuid = 'f7d26b1d-4814-4cf0-95eb-e76c49802fe1';
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.controlled.loss.report') {
                throw new RuntimeException('Injected controlled loss audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->postJson(route('emar.cd_loss.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'medication_name' => $medication->name,
                'quantity_lost' => 1,
                'unit' => 'tablets',
                'circumstances' => 'One tablet could not be reconciled during handover.',
                'immediate_action_taken' => 'Remaining stock was secured and recounted.',
                'client_request_uuid' => $requestUuid,
            ]);
            $this->fail('The controlled loss audit failure should escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected controlled loss audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
            $this->withExceptionHandling();
        }

        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseMissing('medication_idempotency_results', [
            'scope' => 'emar-controlled-loss-report',
            'request_uuid' => $requestUuid,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.controlled.loss.report',
        ]);
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
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.cd_loss.store'), $payload)
            ->assertSessionHasErrors('client_id');
        $this->assertDatabaseCount('controlled_drug_loss_reports', 0);

        $localReport = $this->lossReport($client, $medication, $user);
        $foreignReport = $this->lossReport($foreignClient, $foreignMedication, $user);
        $forgedReport = $this->lossReport($client, $foreignMedication, $user);

        $this->actingAs($user)
            ->post(route('emar.cd_loss.investigate', $foreignReport), [
                'investigation_notes' => 'Must remain hidden.',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.cd_loss.resolve', $forgedReport), [
                'resolution_outcome' => 'Must remain hidden.',
            ])
            ->assertNotFound();
        $this->assertSame('reported', $foreignReport->fresh()->investigation_status);
        $this->assertSame('reported', $forgedReport->fresh()->investigation_status);

        $this->actingAs($user)
            ->post(route('emar.cd_loss.investigate', $localReport), [
                'investigation_notes' => 'Local investigation opened.',
            ])
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('emar.cd_loss.resolve', $localReport), [
                'resolution_outcome' => 'Local stock reconciled.',
            ])
            ->assertRedirect();
        $this->assertSame('resolved', $localReport->fresh()->investigation_status);
    }

    public function test_balance_check_mismatch_links_incident_to_discrepancy(): void
    {
        ['user' => $user, 'witness' => $witness, 'client' => $client, 'med' => $med] = $this->setupCd();

        $response = $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
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

    public function test_balance_check_actual_balance_obeys_decimal_10_2_register_limit_without_writes(): void
    {
        ['user' => $user, 'witness' => $witness, 'med' => $med] = $this->setupCd();

        // Although stock and discrepancy balances use DECIMAL(12,2), the same
        // actual balance is persisted to client_controlled_drug_entries.quantity,
        // making that DECIMAL(10,2) register column the most restrictive sink.
        $this->actingAs($user)
            ->from('/emar/controlled')
            ->post('/emar/controlled/balance-check', [
                'client_medication_id' => $med->id,
                'expected_balance' => '10.00',
                'actual_balance' => '100000000.00',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
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
            'action' => 'medications.controlled.balance_check.record',
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
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
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
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'expected_balance' => 10,
                'actual_balance' => 10,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
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
                'client_medication_id' => $med->id,
                'client_id' => $client->id,
                'medication_name' => 'Morphine sulfate',
                'entry_type' => 'administration',
                'quantity' => 2,
                'on_hand_before' => 10,
                'on_hand_after' => 8,
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'cd_schedule' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $med->fresh()->cd_schedule);
    }

    /** @param  array<string, mixed>  $meta */
    private function assertOfflineProvenance(
        array $meta,
        string $requestUuid,
        string $capturedAt,
        string $deviceId,
    ): void {
        $this->assertSame($requestUuid, $meta['client_request_uuid'] ?? null);
        $this->assertSame($capturedAt, $meta['captured_offline_at'] ?? null);
        $this->assertSame($deviceId, $meta['origin_device_id'] ?? null);
        $this->assertTrue($meta['queued_offline'] ?? false);
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
