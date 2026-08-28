<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationMarAttachment;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationScheduledStockCount;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedicationControlledApiConcealmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_ordinary_medication_actor_cannot_discover_controlled_direct_objects_or_validation_paths(): void
    {
        Storage::fake((string) config('filesystems.default'));

        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $actor = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.administer.record',
            'medications.administer.correct',
            'medications.stock.update',
        ], $site);
        $client->supportWorkers()->attach($actor);

        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => 'Concealed controlled API medication',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'is_prn' => true,
        ]);
        $administration = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'given',
            'is_correction' => false,
        ]);
        $attachment = MedicationMarAttachment::query()->create([
            'client_medication_administration_id' => $administration->id,
            'client_id' => $client->id,
            'attachable_type' => ClientMedicationAdministration::class,
            'attachable_id' => $administration->id,
            'file_name' => 'controlled-evidence.pdf',
            'file_path' => 'medication_mar_attachments/controlled-evidence.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'uploaded_by' => $actor->id,
        ]);
        Storage::put($attachment->file_path, 'restricted');
        $count = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 4,
        ]);

        foreach ([
            route('api.medications.safety.check', [$client, $medication]),
            route('api.medications.prn.history', [$client, $medication]),
            route('api.medications.scan_code.show', [$client, $medication]),
            route('api.medications.scan_code.svg', [$client, $medication]),
            route('api.medications.versions.index', [$client, $medication]),
            route('api.medications.scheduled_counts.index', [$client, $medication]),
        ] as $url) {
            $this->actingAs($actor)->get($url)->assertNotFound();
        }

        $this->actingAs($actor)
            ->postJson(route('api.medications.scan.verify', [$client, $medication]), [])
            ->assertNotFound();
        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.record', [$client, $medication]), [])
            ->assertNotFound();
        $this->actingAs($actor)
            ->postJson(route('api.medications.scheduled_counts.store', [$client, $medication]), [])
            ->assertNotFound();
        $this->actingAs($actor)
            ->postJson(route('api.medications.scheduled_counts.complete', [$client, $count]), [])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('api.medications.attachments.upload', [$client, $administration]), [])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post(route('api.medications.supporting_attachments.upload', $client), [
                'target_type' => 'administration',
                'target_id' => $administration->id,
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->get(route('api.medications.attachments.download', [$client, $administration, $attachment]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->get(route('api.medications.supporting_attachments.download', [$client, $attachment]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->deleteJson(route('api.medications.attachments.delete', [$client, $administration, $attachment]))
            ->assertNotFound();
        $this->actingAs($actor)
            ->deleteJson(route('api.medications.supporting_attachments.delete', [$client, $attachment]))
            ->assertNotFound();

        $this->assertDatabaseCount('medication_mar_attachments', 1);
        $this->assertDatabaseHas('medication_mar_attachments', ['id' => $attachment->id]);
        $this->assertSame('pending', $count->refresh()->status);
        Storage::assertExists($attachment->file_path);
    }

    public function test_mar_omits_controlled_discrepancies_with_forged_foreign_medication_ownership(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $actor = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.controlled.view',
        ], $site);
        $client->supportWorkers()->attach($actor);
        $canonicalMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $foreignMedication = ClientMedication::factory()->create([
            'client_id' => $foreignClient->id,
            'name' => 'Foreign controlled name must stay hidden',
            'controlled_drug' => true,
        ]);
        $reporter = User::factory()->create();
        $canonical = ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $canonicalMedication->id,
            'difference' => -1,
            'reason' => 'Canonical local discrepancy',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
            'status' => 'open',
        ]);
        ClientControlledDrugDiscrepancy::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'difference' => -2,
            'reason' => 'Forged foreign discrepancy',
            'reported_at' => now(),
            'reported_by' => $reporter->id,
            'status' => 'open',
        ]);
        $canonicalCount = MedicationScheduledStockCount::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $canonicalMedication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 4,
            'notes' => 'Canonical local count',
        ]);
        MedicationScheduledStockCount::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $canonicalMedication->id,
            'scheduled_date' => today(),
            'status' => 'pending',
            'expected_quantity' => 7,
            'notes' => 'Forged foreign count',
        ]);

        $this->actingAs($actor)
            ->getJson(route('api.medications.mar.show', $client))
            ->assertOk()
            ->assertJsonCount(1, 'controlled_discrepancies')
            ->assertJsonPath('controlled_discrepancies.0.id', $canonical->id)
            ->assertJsonMissing(['reason' => 'Forged foreign discrepancy'])
            ->assertJsonMissing(['medication_name' => 'Foreign controlled name must stay hidden']);

        $this->actingAs($actor)
            ->getJson(route('api.medications.scheduled_counts.index', [$client, $canonicalMedication]))
            ->assertOk()
            ->assertJsonCount(1, 'counts')
            ->assertJsonPath('counts.0.id', $canonicalCount->id)
            ->assertJsonMissing(['notes' => 'Forged foreign count']);
    }

    public function test_controlled_corrections_cannot_cross_the_stock_affecting_given_boundary(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $stock = ClientMedicationStock::query()->create([
            'client_medication_id' => $medication->id,
            'on_hand' => 9.5,
            'unit' => 'tablets',
        ]);
        $given = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'dose_given' => '1 tablet',
            'administered_at' => now()->subMinute(),
            'status' => 'given',
            'is_correction' => false,
        ]);
        $refused = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'refused',
            'is_correction' => false,
        ]);
        $sameBoundary = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'refused',
            'is_correction' => false,
        ]);

        foreach ([[$given, 'refused'], [$refused, 'given']] as [$original, $status]) {
            $this->actingAs($actor)
                ->postJson(route('api.medications.administrations.correct', [$client, $original]), [
                    'status' => $status,
                    'correction_reason' => 'Attempted stock-affecting correction',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }
        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $given]), [
                'status' => 'given',
                'dose_given' => '2 tablets',
                'correction_reason' => 'Attempted controlled dose drift',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('dose_given');
        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $given]), [
                'status' => 'given',
                'administered_at' => $given->administered_at->copy()->addMinute()->toIso8601String(),
                'correction_reason' => 'Attempted controlled administration-time drift',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('administered_at');

        $this->assertDatabaseCount('client_medication_administrations', 3);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(9.5, (float) $stock->refresh()->on_hand);

        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $sameBoundary]), [
                'status' => 'withheld',
                'correction_reason' => 'Clarify the non-administration reason',
            ])
            ->assertOk()
            ->assertJsonPath('correction.status', 'withheld');

        $this->assertDatabaseCount('client_medication_administrations', 4);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertSame(9.5, (float) $stock->refresh()->on_hand);
    }

    public function test_api_correction_uses_the_effective_approved_winner_and_rejects_stale_children(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);
        $performer = User::factory()->create();
        $witness = User::factory()->create();
        $priorRequester = User::factory()->create();
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);
        $ordinaryMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        $root = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'administered_by' => $performer->id,
            'witnessed_by' => $witness->id,
            'status' => 'given',
            'reason' => 'Stale root reason',
            'dose_given' => '1 tablet',
            'notes' => 'Stale root notes',
            'administered_at' => now(),
            'is_correction' => false,
        ]);
        $approvedWinner = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'administered_by' => $performer->id,
            'witnessed_by' => $witness->id,
            'status' => 'refused',
            'reason' => 'Effective approved reason',
            'dose_given' => '0 tablets',
            'notes' => 'Effective approved notes',
            'insulin_units_given' => 6,
            'injection_site' => 'left abdomen',
            'administered_at' => now(),
            'is_correction' => true,
            'corrected_of_id' => $root->id,
            'correction_status' => 'approved',
            'correction_reason' => 'Previously corrected',
            'correction_requested_by' => $priorRequester->id,
            'correction_approved_by' => $actor->id,
            'correction_approved_at' => now(),
        ]);
        $staleChild = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $ordinaryMedication->id,
            'administered_by' => $performer->id,
            'witnessed_by' => $witness->id,
            'status' => 'missed',
            'administered_at' => now(),
            'is_correction' => true,
            'corrected_of_id' => $root->id,
            'correction_status' => 'rejected',
            'correction_reason' => 'Rejected stale child',
        ]);

        $response = $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $root]), [
                'status' => 'withheld',
                'correction_reason' => 'Correct the current effective administration',
            ])
            ->assertOk()
            ->assertJsonPath('correction.status', 'withheld');

        $pending = ClientMedicationAdministration::query()->findOrFail($response->json('correction.id'));
        $this->assertSame($root->id, (int) $pending->corrected_of_id);
        $this->assertSame('Effective approved reason', $pending->reason);
        $this->assertSame('0 tablets', $pending->dose_given);
        $this->assertSame('Effective approved notes', $pending->notes);
        $this->assertSame('pending', $pending->correction_status);
        $this->assertNull($pending->insulin_units_given);
        $this->assertNull($pending->injection_site);
        $this->assertSame($performer->id, (int) $pending->administered_by);
        $this->assertSame($witness->id, (int) $pending->witnessed_by);
        $this->assertSame($actor->id, (int) $pending->correction_requested_by);

        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $root]), [
                'status' => 'missed',
                'correction_reason' => 'A second pending correction is forbidden',
            ])
            ->assertConflict();
        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $staleChild]), [
                'status' => 'missed',
                'correction_reason' => 'A rejected child cannot be corrected directly',
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->post(route('emar.corrections.approve', $pending))
            ->assertSessionHas('error');
        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $pending))
            ->assertRedirect();
        $approvedApiCorrection = $pending->fresh();
        $this->assertSame('approved', $approvedApiCorrection->correction_status);
        $this->assertSame($performer->id, (int) $approvedApiCorrection->administered_by);
        $this->assertSame($witness->id, (int) $approvedApiCorrection->witnessed_by);
        $this->assertSame($actor->id, (int) $approvedApiCorrection->correction_requested_by);
        $this->assertSame($approver->id, (int) $approvedApiCorrection->correction_approved_by);
        $this->assertSame([$approvedApiCorrection->id], ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where(fn ($query) => $query
                ->whereKey($root->id)
                ->orWhere('corrected_of_id', $root->id))
            ->pluck('id')
            ->all());

        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $root]), [
                'status' => 'given',
                'correction_reason' => 'A correction route cannot create a given administration',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $controlledMedication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => true,
        ]);
        $controlledRoot = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'administered_by' => $actor->id,
            'status' => 'refused',
            'administered_at' => now(),
            'is_correction' => false,
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'administered_by' => $actor->id,
            'status' => 'given',
            'administered_at' => now(),
            'is_correction' => true,
            'corrected_of_id' => $controlledRoot->id,
            'correction_status' => 'approved',
            'correction_reason' => 'Legacy effective stock state',
            'correction_approved_by' => $actor->id,
            'correction_approved_at' => now(),
        ]);

        $this->actingAs($actor)
            ->postJson(route('api.medications.administrations.correct', [$client, $controlledRoot]), [
                'status' => 'withheld',
                'correction_reason' => 'Must compare against the effective given state',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseCount('client_medication_administrations', 6);
        $this->assertSame('rejected', $approvedWinner->fresh()->correction_status);
    }

    public function test_version_history_requires_canonical_client_ownership_and_historical_controlled_view(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'status' => 'active',
        ]);
        $ordinaryViewer = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
        ], $site);
        $controlledViewer = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
            'medications.controlled.view',
        ], $site);
        $client->supportWorkers()->attach([$ordinaryViewer->id, $controlledViewer->id]);

        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
        ]);
        MedicationOrderVersion::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'version_number' => 1,
            'name' => 'Canonical ordinary version',
            'controlled_drug' => false,
            'changed_by' => $ordinaryViewer->id,
            'changed_at' => now(),
        ]);
        MedicationOrderVersion::query()->create([
            'client_id' => $foreignClient->id,
            'client_medication_id' => $medication->id,
            'version_number' => 2,
            'name' => 'Forged foreign version',
            'controlled_drug' => false,
            'changed_by' => $ordinaryViewer->id,
            'changed_at' => now(),
        ]);
        MedicationOrderVersion::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'version_number' => 3,
            'name' => 'Historical controlled version',
            'controlled_drug' => true,
            'changed_by' => $ordinaryViewer->id,
            'changed_at' => now(),
        ]);

        $this->actingAs($ordinaryViewer)
            ->getJson(route('api.medications.versions.index', [$client, $medication]))
            ->assertOk()
            ->assertJsonCount(1, 'versions')
            ->assertJsonFragment(['name' => 'Canonical ordinary version'])
            ->assertJsonMissing(['name' => 'Forged foreign version'])
            ->assertJsonMissing(['name' => 'Historical controlled version']);

        $this->actingAs($controlledViewer)
            ->getJson(route('api.medications.versions.index', [$client, $medication]))
            ->assertOk()
            ->assertJsonCount(2, 'versions')
            ->assertJsonFragment(['name' => 'Canonical ordinary version'])
            ->assertJsonFragment(['name' => 'Historical controlled version'])
            ->assertJsonMissing(['name' => 'Forged foreign version']);
    }

    public function test_dashboard_and_report_filters_reject_invalid_or_broadening_input(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $actor = $this->userWithPermissions([
            'medications.view',
            'reports.viewAny',
        ], $site);

        foreach ([
            ['api.medications.dashboard.widgets', ['client_id' => 'not-an-id'], 'client_id'],
            ['api.medications.dashboard.widgets', ['client_id' => 0], 'client_id'],
            ['api.medications.reports', ['type' => 'unknown'], 'type'],
            ['api.medications.reports', ['client_id' => 'not-an-id'], 'client_id'],
            ['api.medications.reports', ['site_id' => 0], 'site_id'],
            ['api.medications.reports', ['date_from' => 'next Thursday'], 'date_from'],
            [
                'api.medications.reports',
                ['date_from' => '2026-08-28', 'date_to' => '2026-08-27'],
                'date_to',
            ],
            ['api.medications.reports.export', ['type' => 'unknown'], 'type'],
            ['api.medications.reports.export', ['date_to' => 'not-a-date'], 'date_to'],
        ] as [$routeName, $query, $field]) {
            $this->actingAs($actor)
                ->getJson(route($routeName, $query))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }

    public function test_prn_history_bounds_the_requested_window_before_service_execution(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = $this->userWithPermissions([
            'clients.viewAssigned',
            'medications.view',
        ], $site);
        $client->supportWorkers()->attach($actor);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'controlled_drug' => false,
            'is_prn' => true,
        ]);

        foreach (['not-a-number', 0, 721] as $hours) {
            $this->actingAs($actor)
                ->getJson(route('api.medications.prn.history', [
                    'client' => $client,
                    'medication' => $medication,
                    'hours' => $hours,
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('hours');
        }

        $this->actingAs($actor)
            ->getJson(route('api.medications.prn.history', [
                'client' => $client,
                'medication' => $medication,
                'hours' => 24,
            ]))
            ->assertOk();
    }

    /** @param list<string> $permissionKeys */
    private function userWithPermissions(array $permissionKeys, Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $overrides = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->sync($overrides);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);

        return $user;
    }
}
