<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDestruction;
use App\Models\MedicationIdempotencyResult;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The redesigned Destructions page is an immutable disposal register (MoD Regs
 * 1977): erroneous records are voided — kept, struck through, with a reason —
 * never hard-deleted. Controlled-drug destructions require two distinct
 * witnesses plus authorisation, and the page resolves the active site's brand
 * colour.
 */
class DestructionsTest extends TestCase
{
    use RefreshDatabase;

    private function setupRegister(): array
    {
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#5E35B1']);
        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        $w1 = $this->makeRoleUser('coordinator');
        $w2 = $this->makeRoleUser('coordinator');
        $this->grantPermissions($w1, ['medications.controlled.witness']);
        $this->grantPermissions($w2, ['medications.controlled.witness']);
        foreach ([$user, $w1, $w2] as $staffMember) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $staffMember->id,
                'primary_site_id' => $site->id,
                'is_active' => true,
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => null,
            ]);
        }
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        foreach ([$w1, $w2] as $witness) {
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
            Shift::factory()->create([
                'client_id' => $client->id,
                'site_id' => $site->id,
                'service_context_id' => $client->service_context_id,
                'user_id' => $witness->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
                'actual_starts_at' => now()->subHour(),
                'status' => 'in_progress',
                'created_by' => $user->id,
            ]);
        }

        $med = ClientMedication::query()->create([
            'client_id' => $client->id, 'name' => 'Oxycodone', 'dosage' => '5mg', 'form' => 'tablet', 'frequency' => 'PRN',
            'controlled_drug' => true, 'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);

        return compact('user', 'w1', 'w2', 'site', 'client', 'med');
    }

    private function record(array $overrides = []): MedicationDestruction
    {
        return MedicationDestruction::create(array_merge([
            'client_id' => $overrides['client_id'] ?? Client::factory()->create()->id,
            'medication_name' => 'Oxycodone',
            'quantity' => 4,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'destroyed_by' => $overrides['destroyed_by'] ?? User::factory()->create()->id,
            'witness_1_id' => $overrides['witness_1_id'] ?? User::factory()->create()->id,
            'destroyed_at' => now(),
            'is_controlled_drug' => true,
        ], $overrides));
    }

    public function test_void_marks_record_voided_not_deleted(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupRegister();
        $rec = $this->record(['client_id' => $client->id]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post("/emar/destructions/{$rec->id}/void", ['void_reason' => 'Duplicate entry'])
            ->assertSessionHasNoErrors();

        // Retained (not deleted) and superseded.
        $this->assertSame(1, MedicationDestruction::count());
        $rec->refresh();
        $this->assertNotNull($rec->voided_at);
        $this->assertSame('Duplicate entry', $rec->void_reason);
        $this->assertSame($user->id, $rec->voided_by);
    }

    public function test_void_is_administrative_only_and_records_explicit_reconciliation_provenance(): void
    {
        ['user' => $user, 'w1' => $witness, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 8.5,
            'unit' => 'tablets',
        ]);
        $entry = ClientControlledDrugEntry::create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'entry_type' => 'destruction',
            'quantity' => 0.5,
            'unit' => 'tablets',
            'on_hand_before' => 9,
            'on_hand_after' => 8.5,
            'recorded_by' => $user->id,
            'witnessed_by' => $witness->id,
            'recorded_at' => now(),
        ]);
        $record = $this->record([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'site_id' => $client->site_id,
            'quantity' => 0.5,
        ]);

        $this->actingAs($user)
            ->post(route('emar.destructions.void', $record), ['void_reason' => 'Duplicate administrative record'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Destruction record voided. Stock and register balances were not changed; record any correction through witnessed reconciliation.');

        $this->assertSame(8.5, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseHas('client_controlled_drug_entries', ['id' => $entry->id]);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $audit = AuditLog::query()->where('action', 'medications.destruction.void')->latest('id')->firstOrFail();
        $this->assertSame(MedicationDestruction::VOID_STOCK_SEMANTICS, $audit->meta['void_stock_semantics'] ?? null);
        $this->assertFalse((bool) ($audit->meta['stock_effect_reversed'] ?? true));
        $this->assertTrue((bool) ($audit->meta['requires_governed_stock_reconciliation'] ?? false));
    }

    public function test_void_requires_a_reason(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupRegister();
        $rec = $this->record(['client_id' => $client->id]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post("/emar/destructions/{$rec->id}/void", ['void_reason' => ''])
            ->assertSessionHasErrors('void_reason');

        $this->assertNull($rec->refresh()->voided_at);
    }

    public function test_cd_destruction_rejects_duplicate_witnesses(): void
    {
        ['user' => $user, 'w1' => $w1, 'client' => $client, 'med' => $med] = $this->setupRegister();

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'client_medication_id' => $med->id,
                'medication_name' => 'Oxycodone',
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $w1->id,
                'witness_1_credential' => 'first-witness-secret',
                'witness_2_id' => $w1->id, // same person twice
                'witness_2_credential' => 'second-witness-secret',
                'authorised_by_name' => 'Pharmacist Pat',
                'denaturing_confirmed' => true,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('witness_2_id');

        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('witness_1_credential', $oldInput);
        $this->assertArrayNotHasKey('witness_2_credential', $oldInput);
        $this->assertStringNotContainsString(
            'first-witness-secret',
            json_encode($oldInput, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'second-witness-secret',
            json_encode($oldInput, JSON_THROW_ON_ERROR),
        );

        $this->assertSame(0, MedicationDestruction::count());
    }

    public function test_destruction_rejects_incomplete_or_contradictory_offline_provenance(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        $uuid = '4bb69346-d057-4082-9f91-e96dfd4197c1';
        $capturedAt = now()->subMinutes(5)->toIso8601String();
        $base = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
        ];
        $invalid = [
            [[...$base, 'queued_offline' => true], 'client_request_uuid'],
            [[
                ...$base,
                'client_request_uuid' => $uuid,
                'captured_offline_at' => '2026-04-30 09:25:00',
                'origin_device_id' => 'destruction-device',
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
                'origin_device_id' => 'destruction-device',
                'queued_offline' => false,
            ], 'captured_offline_at'],
        ];

        foreach ($invalid as [$payload, $field]) {
            $this->actingAs($user)
                ->postJson(route('emar.destructions.store'), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('medication_destructions', 0);
    }

    public function test_cd_destruction_records_with_two_distinct_witnesses(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client, 'med' => $med] = $this->setupRegister();
        ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post('/emar/destructions', [
                'client_id' => $client->id,
                'client_medication_id' => $med->id,
                'medication_name' => 'Oxycodone',
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'denaturing',
                'is_controlled_drug' => true,
                'witness_1_id' => $w1->id,
                'witness_1_credential' => 'password',
                'witness_2_id' => $w2->id,
                'witness_2_credential' => 'password',
                'authorised_by_name' => 'Pharmacist Pat',
                'denaturing_confirmed' => true,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MedicationDestruction::count());
    }

    public function test_destruction_replay_is_single_effect_and_changed_payload_conflicts(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client, 'med' => $med] = $this->setupRegister();
        $stock = ClientMedicationStock::create([
            'client_medication_id' => $med->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $uuid = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $med->id,
            'medication_name' => 'Ignored client label',
            'form' => 'forged liquid',
            'strength' => '999mg',
            'controlled_drug_class' => 'forged schedule',
            'quantity' => 2,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'witness_1_id' => $w1->id,
            'witness_1_credential' => 'password',
            'witness_2_id' => $w2->id,
            'witness_2_credential' => 'password',
            'authorised_by_name' => 'Pharmacist Pat',
            'denaturing_confirmed' => true,
            'client_request_uuid' => $uuid,
        ];

        $this->actingAs($user)->post(route('emar.destructions.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('emar.destructions.store'), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertDatabaseHas('medication_destructions', [
            'client_medication_id' => $med->id,
            'medication_name' => 'Oxycodone',
            'form' => 'tablet',
            'strength' => '5mg',
            'controlled_drug_class' => null,
        ]);
        $this->assertSame(8.0, (float) $stock->refresh()->on_hand);

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [...$payload, 'quantity' => 3])
            ->assertSessionHasErrors('client_request_uuid');
        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(8.0, (float) $stock->refresh()->on_hand);
    }

    public function test_destruction_replay_rechecks_current_witness_authority_before_returning_success(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_name' => $medication->name,
            'quantity' => 2,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'witness_1_id' => $w1->id,
            'witness_1_credential' => 'password',
            'witness_2_id' => $w2->id,
            'witness_2_credential' => 'password',
            'authorised_by_name' => 'Pharmacist Pat',
            'denaturing_confirmed' => true,
            'client_request_uuid' => (string) Str::uuid(),
        ];

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->setPermission($w1, 'medications.controlled.witness', false);

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), $payload)
            ->assertNotFound();

        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(8.0, (float) $stock->refresh()->on_hand);
    }

    public function test_destruction_replay_key_is_durable_and_conflicts_when_the_target_changes(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client, 'med' => $firstMedication] = $this->setupRegister();
        $secondMedication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Morphine target two',
            'dosage' => '5mg',
            'frequency' => 'PRN',
            'controlled_drug' => true,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        $firstStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $firstMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $secondStock = ClientMedicationStock::query()->create([
            'client_medication_id' => $secondMedication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $uuid = (string) Str::uuid();
        $payload = [
            'client_id' => $client->id,
            'client_medication_id' => $firstMedication->id,
            'medication_name' => 'Ignored label',
            'quantity' => 2,
            'unit' => 'tablets',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
            'is_controlled_drug' => true,
            'witness_1_id' => $w1->id,
            'witness_1_credential' => 'password',
            'witness_2_id' => $w2->id,
            'witness_2_credential' => 'password',
            'authorised_by_name' => 'Pharmacist Pat',
            'denaturing_confirmed' => true,
            'client_request_uuid' => $uuid,
        ];

        $this->actingAs($user)->post(route('emar.destructions.store'), $payload)->assertSessionHasNoErrors();
        $binding = MedicationIdempotencyResult::query()->sole();
        $this->assertSame('emar-destruction', $binding->scope);
        $this->assertNull($binding->expires_at);
        $binding->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($user)->post(route('emar.destructions.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [
                ...$payload,
                'client_medication_id' => $secondMedication->id,
                'medication_name' => $secondMedication->name,
            ])
            ->assertSessionHasErrors('client_request_uuid');

        $secondActor = $this->makeRoleUser('admin');
        $this->grantPermissions($secondActor, [
            'medications.controlled.view',
            'medications.controlled.record',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $secondActor->id,
            'primary_site_id' => $client->site_id,
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
        ]);
        $this->actingAs($secondActor)
            ->postJson(route('emar.destructions.store'), $payload)
            ->assertConflict()
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        $this->assertSame(8.0, (float) $firstStock->refresh()->on_hand);
        $this->assertSame(10.0, (float) $secondStock->refresh()->on_hand);
    }

    public function test_new_destruction_requires_a_canonical_link_and_conceals_site_mismatch_before_clinical_validation(): void
    {
        ['user' => $user, 'w1' => $witness, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [
                'client_id' => $client->id,
                'medication_name' => 'Unlinked label',
                'quantity' => 1,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'pharmacy_return',
                'witness_1_id' => $witness->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('client_medication_id');

        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'site_id' => $foreignSite->id,
                'client_request_uuid' => (string) Str::uuid(),
                'quantity' => 'invalid',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('medication_destructions', 0);
        $this->assertSame(10.0, (float) $medication->stock()->firstOrFail()->on_hand);
    }

    public function test_ordinary_destruction_locks_current_site_witness_and_records_truthful_method(): void
    {
        ['user' => $user, 'w1' => $witness, 'client' => $client] = $this->setupRegister();
        $ordinary = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol disposal',
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationStock::query()->create([
            'client_medication_id' => $ordinary->id,
            'on_hand' => 5,
            'unit' => 'tablets',
        ]);

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $ordinary->id,
                'medication_name' => 'Client supplied label ignored',
                'quantity' => 1,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'pharmacy_return',
                'witness_1_id' => $witness->id,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();

        $audit = AuditLog::query()->where('action', 'medications.destruction.record')->sole();
        $this->assertSame('site_staff_record', $audit->meta['witness_method'] ?? null);
        $this->assertSame($witness->id, $audit->meta['witness_1_id'] ?? null);
        $this->assertDatabaseHas('medication_destructions', [
            'client_medication_id' => $ordinary->id,
            'medication_name' => $ordinary->name,
        ]);
        $this->assertSame(4.0, (float) $ordinary->stock()->firstOrFail()->on_hand);
    }

    public function test_void_uses_immutable_classification_and_retains_soft_deleted_medication_link(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        $record = $this->record([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'site_id' => $client->site_id,
            'is_controlled_drug' => false,
        ]);
        $medication->forceFill([
            'controlled_drug' => true,
            'deleted_at' => now(),
        ])->saveQuietly();
        $this->setPermission($user, 'medications.controlled.view', false);

        $this->actingAs($user)
            ->post(route('emar.destructions.void', $record), ['void_reason' => 'Historical ordinary entry correction'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($record->refresh()->voided_at);
        $audit = AuditLog::query()->where('action', 'medications.destruction.void')->sole();
        $this->assertFalse((bool) ($audit->meta['requires_governed_stock_reconciliation'] ?? true));
        $this->assertSame($medication->id, $audit->meta['client_medication_id'] ?? null);
    }

    public function test_record_only_actor_cannot_probe_store_or_void_a_controlled_destruction(): void
    {
        ['user' => $user, 'client' => $client, 'med' => $medication] = $this->setupRegister();
        $this->setPermission($user, 'medications.controlled.view', false);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $existing = $this->record([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'site_id' => $client->site_id,
            'is_controlled_drug' => true,
        ]);

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'client_request_uuid' => (string) Str::uuid(),
                'quantity' => 'not-a-number',
            ])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.destructions.void', $existing), ['void_reason' => ''])
            ->assertNotFound();
        $this->actingAs($user)
            ->post(route('emar.destructions.void', PHP_INT_MAX), ['void_reason' => ''])
            ->assertNotFound();

        $this->assertDatabaseCount('medication_destructions', 1);
        $this->assertNull($existing->refresh()->voided_at);
        $this->assertSame(10.0, (float) $stock->refresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.destruction.void']);
    }

    public function test_non_controlled_destruction_conceals_noncanonical_witness_before_insert(): void
    {
        ['user' => $user, 'client' => $client] = $this->setupRegister();
        $ordinary = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'PRN',
            'controlled_drug' => false,
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
        ]);
        ClientMedicationStock::create([
            'client_medication_id' => $ordinary->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);

        $this->actingAs($user)
            ->post(route('emar.destructions.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $ordinary->id,
                'medication_name' => $ordinary->name,
                'quantity' => 2,
                'unit' => 'tablets',
                'reason' => 'expired',
                'disposal_method' => 'pharmacy_return',
                'is_controlled_drug' => false,
                'witness_1_id' => PHP_INT_MAX,
                'client_request_uuid' => (string) Str::uuid(),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('medication_destructions', 0);
        $this->assertSame(10.0, (float) $ordinary->stock()->firstOrFail()->on_hand);
    }

    public function test_page_serves_brand_colour_and_payload(): void
    {
        ['user' => $user, 'site' => $site] = $this->setupRegister();

        $this->actingAs($user)
            ->get('/emar/destructions?site_id='.$site->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Destructions')
                ->where('site_brand_colour', '#5E35B1')
                ->has('medications', 1)
                ->has('destructions')
                ->has('sites')
                ->has('staff')
                // The hero footer Client EntityFilter (Gap A) is driven by `clients`.
                ->has('clients')
            );
    }

    /**
     * The read-only detail modal (Gap B) and the immutable-register UI render
     * entirely from the page payload: it must carry the witnesses, authoriser,
     * controlled-drug flags, human labels and the full void trail so a record can
     * be inspected without an extra round-trip. (Backend gap BK1.)
     */
    public function test_payload_carries_detail_fields_for_voided_cd_record(): void
    {
        ['user' => $user, 'w1' => $w1, 'w2' => $w2, 'client' => $client] = $this->setupRegister();
        $rec = $this->record([
            'client_id' => $client->id,
            'witness_1_id' => $w1->id,
            'witness_2_id' => $w2->id,
            'authorised_by_name' => 'Pharmacist Pat',
            'reason' => 'expired',
            'disposal_method' => 'denaturing',
        ]);

        // Void it so the payload also exercises the void trail (immutable: retained).
        $this->actingAs($user)
            ->from('/emar/destructions')
            ->post("/emar/destructions/{$rec->id}/void", ['void_reason' => 'Wrong quantity recorded'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get('/emar/destructions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/Destructions')
                ->where('destructions.0.witness_1_name', $w1->name)
                ->where('destructions.0.witness_2_name', $w2->name)
                ->where('destructions.0.authorised_by_name', 'Pharmacist Pat')
                ->where('destructions.0.is_controlled_drug', true)
                ->where('destructions.0.reason_label', 'Expired')
                ->where('destructions.0.disposal_method_label', 'Denaturing')
                ->where('destructions.0.is_voided', true)
                ->where('destructions.0.void_stock_semantics', MedicationDestruction::VOID_STOCK_SEMANTICS)
                ->where('destructions.0.requires_governed_stock_reconciliation', true)
                ->where('destructions.0.void_reason', 'Wrong quantity recorded')
                ->where('destructions.0.voided_by_name', $user->name)
            );
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

    private function setPermission(User $user, string $permissionKey, bool $allowed): void
    {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => $allowed],
        ]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
