<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\MedicationOrderVersion;
use App\Models\MedicationSyringeDriver;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\MedicationReportingService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MedicationReportCanonicalScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_medication_administration_report_uses_effective_clinical_evidence(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ModuleReportController.php'));

        $this->assertStringContainsString(
            "\$this->medicationGovernance(\$definition) === 'administration'",
            $source,
        );
        $this->assertStringContainsString('$query->effectiveClinicalEvidence()', $source);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_report_pages_json_csv_and_aggregates_exclude_forged_cross_client_medications(): void
    {
        $localSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $localClient = Client::factory()->create([
            'site_id' => $localSite->id,
            'first_name' => 'Local',
            'last_name' => 'Report Resident',
            'status' => 'active',
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'first_name' => 'Foreign',
            'last_name' => 'Report Resident',
            'status' => 'active',
        ]);
        $localMedication = $this->medication($localClient, 'LOCAL CANONICAL REPORT MEDICATION');
        $localControlledMedication = $this->medication(
            $localClient,
            'RESTRICTED CONTROLLED MEDICATION',
            true,
        );
        $foreignMedication = $this->medication($foreignClient, 'FOREIGN FORGED MEDICATION LEAK');
        $recorder = User::factory()->create();
        $formulaPayload = '=HYPERLINK("https://example.test","click")';

        $localAdministration = $this->administration(
            $localClient,
            $localMedication,
            $recorder,
            'given',
            $formulaPayload,
        );
        $controlledAdministration = $this->administration(
            $localClient,
            $localControlledMedication,
            $recorder,
            'given',
            'RESTRICTED CONTROLLED ADMINISTRATION',
        );
        $this->administration(
            $localClient,
            $foreignMedication,
            $recorder,
            'missed',
            'FORGED CROSS-CLIENT ADMINISTRATION LEAK',
        );
        $this->administration(
            $foreignClient,
            $foreignMedication,
            $recorder,
            'refused',
            'FOREIGN OUT-OF-SCOPE ADMINISTRATION',
        );
        MedicationError::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => null,
            'error_type' => 'documentation',
            'severity' => 'minor',
            'description' => 'LOCAL NULLABLE MEDICATION ERROR',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        MedicationError::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => $foreignMedication->id,
            'error_type' => 'documentation',
            'severity' => 'major',
            'description' => 'FORGED CROSS-CLIENT MEDICATION ERROR LEAK',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        MedicationError::query()->create([
            'client_id' => $localClient->id,
            'client_medication_id' => $localControlledMedication->id,
            'error_type' => 'wrong_medication',
            'severity' => 'major',
            'description' => 'RESTRICTED CONTROLLED MEDICATION ERROR',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        ClientIncident::factory()->create([
            'client_id' => $localClient->id,
            'type' => 'medication',
            'title' => 'LOCAL ORDINARY MEDICATION INCIDENT',
            'occurred_at' => now(),
            'metadata' => [
                'medication_id' => $localMedication->id,
                'medication_name' => $localMedication->name,
                'controlled_drug' => false,
            ],
        ]);
        ClientIncident::factory()->create([
            'client_id' => $localClient->id,
            'type' => 'controlled_drug',
            'title' => 'INCONSISTENT CONTROLLED INCIDENT',
            'occurred_at' => now(),
            'metadata' => [
                'medication_id' => $localMedication->id,
                'medication_name' => $localMedication->name,
                'controlled_drug' => false,
            ],
        ]);
        ClientIncident::factory()->create([
            'client_id' => $localClient->id,
            'type' => 'medication',
            'title' => 'RESTRICTED CONTROLLED INCIDENT',
            'occurred_at' => now(),
            'metadata' => [
                'medication_id' => $localControlledMedication->id,
                'medication_name' => 'SPOOFED ORDINARY MEDICATION NAME',
                'controlled_drug' => false,
            ],
        ]);
        ClientIncident::factory()->create([
            'client_id' => $localClient->id,
            'type' => 'medication',
            'title' => 'FORGED CROSS-CLIENT MEDICATION INCIDENT LEAK',
            'occurred_at' => now(),
            'metadata' => [
                'medication_id' => $foreignMedication->id,
                'medication_name' => $foreignMedication->name,
                'controlled_drug' => false,
            ],
        ]);
        ClientIncident::factory()->create([
            'client_id' => $localClient->id,
            'type' => 'medication',
            'title' => 'MISSING HISTORICAL MEDICATION INCIDENT LEAK',
            'occurred_at' => now(),
            'metadata' => [
                'medication_id' => 999999,
                'medication_name' => 'MISSING HISTORICAL MEDICATION NAME',
                'controlled_drug' => false,
            ],
        ]);
        $historicalControlledMedication = $this->medication(
            $localClient,
            'HISTORICAL CONTROLLED MEDICATION',
            true,
        );
        DB::table('client_medications')->where('id', $historicalControlledMedication->id)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        ClientIncident::factory()->create([
            'client_id' => $localClient->id,
            'type' => 'medication',
            'title' => 'HISTORICAL CONTROLLED MEDICATION INCIDENT',
            'occurred_at' => now(),
            'metadata' => [
                'medication_id' => $historicalControlledMedication->id,
                'medication_name' => 'SPOOFED HISTORICAL ORDINARY NAME',
                'controlled_drug' => false,
            ],
        ]);
        MedicationSyringeDriver::query()->create([
            'client_id' => $localClient->id,
            'site_id' => $localSite->id,
            'status' => 'completed',
            'commenced_at' => now()->subHour(),
            'commenced_by' => $recorder->id,
            'completed_at' => now(),
            'completed_by' => $recorder->id,
            'notes' => 'ORDINARY DRIVER NOTES',
            'contents' => [[
                'client_medication_id' => $localMedication->id,
                'name' => 'ORDINARY SYRINGE CONTENT',
            ]],
        ]);
        MedicationSyringeDriver::query()->create([
            'client_id' => $localClient->id,
            'site_id' => $localSite->id,
            'status' => 'completed',
            'commenced_at' => now()->subMinutes(50),
            'commenced_by' => $recorder->id,
            'completed_at' => now(),
            'completed_by' => $recorder->id,
            'notes' => 'RESTRICTED MIXED DRIVER NOTES',
            'contents' => [
                [
                    'client_medication_id' => $localMedication->id,
                    'name' => 'RESTRICTED MIXED ORDINARY SYRINGE CONTENT',
                ],
                [
                    'client_medication_id' => $localControlledMedication->id,
                    'name' => 'RESTRICTED CONTROLLED SYRINGE CONTENT',
                ],
            ],
        ]);
        foreach ([
            [$foreignMedication->id, 'FORGED FOREIGN SYRINGE CONTENT', 'FORGED FOREIGN DRIVER NOTES'],
            [null, 'UNLINKED SYRINGE CONTENT', 'UNLINKED DRIVER NOTES'],
        ] as [$medicationId, $name, $notes]) {
            MedicationSyringeDriver::query()->create([
                'client_id' => $localClient->id,
                'site_id' => $localSite->id,
                'status' => 'completed',
                'commenced_at' => now()->subMinutes(40),
                'commenced_by' => $recorder->id,
                'completed_at' => now(),
                'completed_by' => $recorder->id,
                'notes' => $notes,
                'contents' => [[
                    'client_medication_id' => $medicationId,
                    'name' => $name,
                ]],
            ]);
        }
        MedicationSyringeDriver::query()->create([
            'client_id' => $localClient->id,
            'site_id' => $localSite->id,
            'status' => 'completed',
            'commenced_at' => now()->subMinutes(30),
            'commenced_by' => $recorder->id,
            'completed_at' => now(),
            'completed_by' => $recorder->id,
            'notes' => 'RESTRICTED CONTROLLED-ONLY DRIVER NOTES',
            'contents' => [[
                'client_medication_id' => $localControlledMedication->id,
                'name' => 'RESTRICTED CONTROLLED-ONLY SYRINGE CONTENT',
            ]],
        ]);

        $reader = $this->reportReader($localSite);
        $this->assertTrue($reader->canDo('reports.viewAny'));
        $this->assertFalse($reader->canDo('medications.view'));

        $medicationsPage = $this->actingAs($reader)
            ->get(route('reports.medications'))
            ->assertOk();
        $this->assertSame(
            [$localAdministration->id],
            collect($medicationsPage->inertiaProps('administrations'))->pluck('id')->all(),
        );
        $this->assertNoForgedMedicationMarkers($medicationsPage->getContent());

        $emarPage = $this->actingAs($reader)
            ->get(route('emar.reports'))
            ->assertOk();
        $this->assertSame(1, (int) $emarPage->inertiaProps('adminSummary.total'));
        $this->assertSame(1, (int) $emarPage->inertiaProps('adminSummary.given'));
        $this->assertSame(0, (int) $emarPage->inertiaProps('adminSummary.missed'));
        $this->assertSame(1, (int) collect($emarPage->inertiaProps('dailyAdmin'))->sum('total'));
        $this->assertSame(1, (int) collect($emarPage->inertiaProps('clientBreakdown'))->sum('total'));
        $this->assertSame(
            [$localMedication->name],
            collect($emarPage->inertiaProps('topPrnMeds'))->pluck('medication')->all(),
        );
        $this->assertSame(1, (int) collect($emarPage->inertiaProps('topPrnMeds'))->sum('count'));
        $this->assertSame(1, (int) collect($emarPage->inertiaProps('prnByClient'))->sum('count'));
        $this->assertSame(1, (int) $emarPage->inertiaProps('errorSummary.total'));
        $this->assertSame(1, (int) $emarPage->inertiaProps('errorSummary.open'));
        $this->assertStringNotContainsString('RESTRICTED CONTROLLED MEDICATION ERROR', $emarPage->getContent());
        $this->assertNoForgedMedicationMarkers($emarPage->getContent());

        $apiReport = $this->actingAs($reader)
            ->getJson(route('api.medications.reports', ['type' => 'mar']))
            ->assertOk()
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('meta.total_records', 1)
            ->assertJsonPath('records.0.id', $localAdministration->id)
            ->assertJsonPath('records.0.medication', $localMedication->name);
        $this->assertNoForgedMedicationMarkers($apiReport->getContent());
        $this->assertStringNotContainsString($localControlledMedication->name, $apiReport->getContent());

        $this->actingAs($reader)
            ->getJson(route('api.medications.reports', ['type' => 'prn']))
            ->assertOk()
            ->assertJsonPath('meta.total_prn_administrations', 1)
            ->assertJsonMissing(['medication_name' => $localControlledMedication->name]);

        $auditReport = $this->actingAs($reader)
            ->getJson(route('api.medications.reports', ['type' => 'audit']))
            ->assertOk()
            ->assertJsonPath('mar_summary.total_administrations', 1)
            ->assertJsonPath('prn_summary.total_prn_administrations', 1);
        $this->assertNoForgedMedicationMarkers($auditReport->getContent());

        $ordinaryIncidents = $this->actingAs($reader)
            ->getJson(route('api.medications.reports', ['type' => 'incidents']))
            ->assertOk()
            ->assertJsonPath('meta.total_incidents', 1)
            ->assertJsonFragment(['title' => 'LOCAL ORDINARY MEDICATION INCIDENT'])
            ->assertJsonMissing(['title' => 'RESTRICTED CONTROLLED INCIDENT'])
            ->assertJsonMissing(['title' => 'INCONSISTENT CONTROLLED INCIDENT']);
        $this->assertStringNotContainsString('RESTRICTED CONTROLLED', $ordinaryIncidents->getContent());

        $ordinaryAdministrationExports = [
            $this->actingAs($reader)
                ->get(route('api.medications.reports.export', ['type' => 'mar']))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('reports.medications.export_mar'))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('emar.reports.export', ['report_type' => 'administration']))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('emar.reports.export', ['report_type' => 'prn']))
                ->assertOk()
                ->streamedContent(),
        ];
        foreach ($ordinaryAdministrationExports as $csv) {
            $this->assertStringContainsString($localMedication->name, $csv);
            $this->assertStringNotContainsString($localControlledMedication->name, $csv);
        }

        $ordinaryErrorCsv = $this->actingAs($reader)
            ->get(route('emar.reports.export', ['report_type' => 'errors']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('LOCAL NULLABLE MEDICATION ERROR', $ordinaryErrorCsv);
        $this->assertStringNotContainsString('RESTRICTED CONTROLLED MEDICATION ERROR', $ordinaryErrorCsv);
        $this->assertNoForgedMedicationMarkers($ordinaryErrorCsv);

        $ordinarySyringeCsv = $this->actingAs($reader)
            ->get(route('emar.reports.export', ['report_type' => 'syringe_drivers']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('ORDINARY SYRINGE CONTENT', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('ORDINARY DRIVER NOTES', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('RESTRICTED MIXED ORDINARY SYRINGE CONTENT', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('RESTRICTED CONTROLLED SYRINGE CONTENT', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('RESTRICTED MIXED DRIVER NOTES', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('RESTRICTED CONTROLLED-ONLY DRIVER NOTES', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('FORGED FOREIGN SYRINGE CONTENT', $ordinarySyringeCsv);
        $this->assertStringNotContainsString('UNLINKED SYRINGE CONTENT', $ordinarySyringeCsv);

        $controlledPermission = Permission::query()
            ->where('key', 'medications.controlled.view')
            ->firstOrFail();
        $reader->permissionOverrides()->syncWithoutDetaching([
            $controlledPermission->id => ['allowed' => true],
        ]);
        $reader->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($reader)
            ->get(route('emar.reports'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('adminSummary.total', 2)
                ->where('adminSummary.given', 2)
                ->where('errorSummary.total', 2)
                ->where('errorSummary.open', 2));
        $this->actingAs($reader)
            ->getJson(route('api.medications.reports', ['type' => 'mar']))
            ->assertOk()
            ->assertJsonPath('meta.total_records', 2)
            ->assertJsonFragment([
                'id' => $controlledAdministration->id,
                'medication' => $localControlledMedication->name,
            ]);
        $this->actingAs($reader)
            ->getJson(route('api.medications.reports', ['type' => 'incidents']))
            ->assertOk()
            ->assertJsonPath('meta.total_incidents', 4)
            ->assertJsonFragment(['title' => 'RESTRICTED CONTROLLED INCIDENT'])
            ->assertJsonFragment(['title' => 'INCONSISTENT CONTROLLED INCIDENT'])
            ->assertJsonFragment([
                'title' => 'HISTORICAL CONTROLLED MEDICATION INCIDENT',
                'medication_name' => 'HISTORICAL CONTROLLED MEDICATION (legacy removed order)',
                'controlled_drug' => true,
            ])
            ->assertJsonMissing(['title' => 'FORGED CROSS-CLIENT MEDICATION INCIDENT LEAK'])
            ->assertJsonMissing(['title' => 'MISSING HISTORICAL MEDICATION INCIDENT LEAK']);

        $controlledSyringeCsv = $this->actingAs($reader)
            ->get(route('emar.reports.export', ['report_type' => 'syringe_drivers']))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('ORDINARY SYRINGE CONTENT', $controlledSyringeCsv);
        $this->assertStringContainsString('RESTRICTED MIXED ORDINARY SYRINGE CONTENT', $controlledSyringeCsv);
        $this->assertStringContainsString('RESTRICTED CONTROLLED SYRINGE CONTENT', $controlledSyringeCsv);
        $this->assertStringNotContainsString('RESTRICTED MIXED DRIVER NOTES', $controlledSyringeCsv);
        $this->assertStringNotContainsString('RESTRICTED CONTROLLED-ONLY DRIVER NOTES', $controlledSyringeCsv);
        $this->assertStringNotContainsString('FORGED FOREIGN SYRINGE CONTENT', $controlledSyringeCsv);
        $this->assertStringNotContainsString('UNLINKED SYRINGE CONTENT', $controlledSyringeCsv);

        $csvPayloads = [
            $this->actingAs($reader)
                ->get(route('api.medications.reports.export', ['type' => 'mar']))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('reports.medications.export_mar'))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('emar.reports.export', ['report_type' => 'administration']))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('emar.reports.export', ['report_type' => 'prn']))
                ->assertOk()
                ->streamedContent(),
            $this->actingAs($reader)
                ->get(route('emar.reports.export', ['report_type' => 'errors']))
                ->assertOk()
                ->streamedContent(),
        ];

        foreach (array_slice($csvPayloads, 0, 4) as $csv) {
            $this->assertStringContainsString($localMedication->name, $csv);
            $this->assertStringContainsString($localControlledMedication->name, $csv);
            $this->assertNoForgedMedicationMarkers($csv);
        }
        $this->assertStringContainsString('LOCAL NULLABLE MEDICATION ERROR', $csvPayloads[4]);
        $this->assertStringContainsString('RESTRICTED CONTROLLED MEDICATION ERROR', $csvPayloads[4]);
        $this->assertNoForgedMedicationMarkers($csvPayloads[4]);
        $this->assertStringContainsString("'=HYPERLINK", $csvPayloads[0]);
    }

    public function test_audit_summary_counts_corrections_without_contaminating_status_groups(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $medication = $this->medication($client, 'Correction summary medication');
        $recorder = User::factory()->create();

        $original = $this->administration($client, $medication, $recorder, 'given', 'Original row');
        $correction = $this->administration($client, $medication, $recorder, 'given', 'Corrected row');
        $correction->forceFill([
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
        ])->save();
        $this->administration($client, $medication, $recorder, 'missed', 'Ordinary row');

        $report = app(MedicationReportingService::class)->generateAuditReport(
            clientId: $client->id,
            dateFrom: now()->subDay(),
            dateTo: now()->addDay(),
            siteIds: [$site->id],
        );

        $this->assertSame(2, (int) data_get($report, 'mar_summary.total_administrations'));
        $this->assertSame(1, (int) data_get($report, 'mar_summary.by_status.given.count'));
        $this->assertSame(1, (int) data_get($report, 'mar_summary.by_status.missed.count'));
        $this->assertSame(1, (int) data_get($report, 'mar_summary.corrections_count'));
    }

    public function test_real_medication_error_incident_links_report_canonically_without_metadata(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['status' => 'active']);
        $ordinaryMedication = $this->medication($client, 'Linked ordinary medication');
        $controlledMedication = $this->medication($client, 'Linked controlled medication', true);
        $foreignMedication = $this->medication($foreignClient, 'Forged linked foreign medication');
        $recorder = $this->medicationReader($site);
        $recordPermission = Permission::query()->where('key', 'medications.administer.record')->firstOrFail();
        $recorder->permissionOverrides()->syncWithoutDetaching([
            $recordPermission->id => ['allowed' => true],
        ]);
        $recorder->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $recorder->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(),
            'started_by' => $recorder->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($recorder)
            ->post(route('emar.errors.store'), [
                'client_id' => $client->id,
                'client_medication_id' => $ordinaryMedication->id,
                'error_type' => 'documentation',
                'severity' => 'minor',
                'description' => 'Real linked medication error journey.',
                'create_incident' => true,
            ])
            ->assertRedirect();

        $ordinaryError = MedicationError::query()
            ->where('client_medication_id', $ordinaryMedication->id)
            ->sole();
        $ordinaryIncident = ClientIncident::query()->findOrFail($ordinaryError->client_incident_id);
        $this->assertNull(data_get($ordinaryIncident->metadata, 'medication_id'));

        $controlledIncident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'medication_error',
            'title' => 'Linked controlled medication error',
            'occurred_at' => now(),
            'metadata' => null,
        ]);
        MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $controlledMedication->id,
            'client_incident_id' => $controlledIncident->id,
            'error_type' => 'wrong_dose',
            'severity' => 'major',
            'description' => 'Controlled linked error.',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);
        $forgedIncident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'type' => 'medication_error',
            'title' => 'Forged linked medication error',
            'occurred_at' => now(),
            'metadata' => null,
        ]);
        MedicationError::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $foreignMedication->id,
            'client_incident_id' => $forgedIncident->id,
            'error_type' => 'wrong_medication',
            'severity' => 'major',
            'description' => 'Forged cross-client linked error.',
            'reported_by' => $recorder->id,
            'reported_at' => now(),
            'status' => 'reported',
        ]);

        $service = app(MedicationReportingService::class);
        $ordinaryReport = $service->reportMedicationIncidents(siteIds: [$site->id]);
        $this->assertSame(1, $ordinaryReport['meta']['total_incidents']);
        $this->assertSame($ordinaryIncident->id, $ordinaryReport['records'][0]['id']);
        $this->assertSame($ordinaryMedication->id, $ordinaryReport['records'][0]['medication_id']);
        $this->assertSame($ordinaryMedication->name, $ordinaryReport['records'][0]['medication_name']);

        $controlledReport = $service->reportMedicationIncidents(
            siteIds: [$site->id],
            includeControlled: true,
        );
        $this->assertSame(2, $controlledReport['meta']['total_incidents']);
        $this->assertEqualsCanonicalizing(
            [$ordinaryIncident->id, $controlledIncident->id],
            collect($controlledReport['records'])->pluck('id')->all(),
        );
        $this->assertNotContains(
            $forgedIncident->id,
            collect($controlledReport['records'])->pluck('id')->all(),
        );
    }

    public function test_change_report_uses_immutable_version_classification_and_pause_resume_preserve_it(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $actor = User::factory()->create();
        $medication = $this->medication($client, 'Historically controlled medication', true);
        $historicalVersion = MedicationOrderVersion::query()->create([
            'client_medication_id' => $medication->id,
            'client_id' => $client->id,
            'version_number' => 1,
            'name' => 'Historically controlled medication',
            'dosage' => '1 tablet',
            'controlled_drug' => true,
            'state' => 'active',
            'active' => true,
            'change_reason' => 'Initial controlled order',
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
        $medication->forceFill(['controlled_drug' => false])->saveQuietly();

        $service = app(MedicationReportingService::class);
        $ordinaryReport = $service->reportMedicationChanges(siteIds: [$site->id]);
        $this->assertNotContains($historicalVersion->id, collect($ordinaryReport['records'])->pluck('id')->all());
        $controlledReport = $service->reportMedicationChanges(
            siteIds: [$site->id],
            includeControlled: true,
        );
        $this->assertContains($historicalVersion->id, collect($controlledReport['records'])->pluck('id')->all());

        $medication->forceFill([
            'controlled_drug' => true,
            'approval_status' => 'verified',
        ])->saveQuietly();
        $medication->pause('Temporary clinical hold', $actor->id);
        $medication->resume($actor->id);

        $lifecycleVersions = MedicationOrderVersion::query()
            ->where('client_medication_id', $medication->id)
            ->whereIn('state', ['paused', 'active'])
            ->where('id', '!=', $historicalVersion->id)
            ->get();
        $this->assertCount(2, $lifecycleVersions);
        $this->assertTrue($lifecycleVersions->every(fn (MedicationOrderVersion $version) => $version->controlled_drug === true));
    }

    public function test_mar_builders_filter_canonical_controlled_syringe_driver_contents_for_ordinary_readers(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $foreignClient = Client::factory()->create(['status' => 'active']);
        $ordinaryMedication = $this->medication($client, 'Ordinary syringe medication');
        $controlledMedication = $this->medication($client, 'Controlled syringe medication', true);
        $foreignMedication = $this->medication($foreignClient, 'Forged foreign syringe medication');
        $recorder = User::factory()->create();

        MedicationSyringeDriver::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'running',
            'commenced_at' => now()->subHour(),
            'commenced_by' => $recorder->id,
            'notes' => 'VISIBLE ORDINARY DRIVER NOTES',
            'contents' => [[
                'client_medication_id' => $ordinaryMedication->id,
                'name' => 'VISIBLE ORDINARY DRIVER CONTENT',
            ]],
        ]);
        MedicationSyringeDriver::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'status' => 'running',
            'commenced_at' => now()->subMinutes(50),
            'commenced_by' => $recorder->id,
            'notes' => 'HIDDEN MIXED DRIVER NOTES',
            'contents' => [
                ['client_medication_id' => $ordinaryMedication->id, 'name' => 'HIDDEN MIXED ORDINARY DRIVER CONTENT'],
                ['client_medication_id' => $controlledMedication->id, 'name' => 'HIDDEN CONTROLLED DRIVER CONTENT'],
            ],
        ]);
        foreach ([
            [$foreignMedication->id, 'HIDDEN FORGED DRIVER CONTENT', 'HIDDEN FORGED DRIVER NOTES'],
            [null, 'HIDDEN UNLINKED DRIVER CONTENT', 'HIDDEN UNLINKED DRIVER NOTES'],
        ] as [$medicationId, $name, $notes]) {
            MedicationSyringeDriver::query()->create([
                'client_id' => $client->id,
                'site_id' => $site->id,
                'status' => 'running',
                'commenced_at' => now()->subMinutes(40),
                'commenced_by' => $recorder->id,
                'notes' => $notes,
                'contents' => [[
                    'client_medication_id' => $medicationId,
                    'name' => $name,
                ]],
            ]);
        }

        $enhancedMar = app(EnhancedMarService::class);
        $ordinaryPayload = json_encode($enhancedMar->build($client, now(), includeControlled: false));
        $this->assertIsString($ordinaryPayload);
        $this->assertStringContainsString('VISIBLE ORDINARY DRIVER CONTENT', $ordinaryPayload);
        $this->assertStringNotContainsString('HIDDEN MIXED ORDINARY DRIVER CONTENT', $ordinaryPayload);
        $this->assertStringNotContainsString('HIDDEN CONTROLLED DRIVER CONTENT', $ordinaryPayload);
        $this->assertStringNotContainsString('HIDDEN MIXED DRIVER NOTES', $ordinaryPayload);
        $this->assertStringNotContainsString('HIDDEN FORGED DRIVER CONTENT', $ordinaryPayload);
        $this->assertStringNotContainsString('HIDDEN UNLINKED DRIVER CONTENT', $ordinaryPayload);

        $controlledPayload = json_encode($enhancedMar->build($client, now(), includeControlled: true));
        $this->assertIsString($controlledPayload);
        $this->assertStringContainsString('HIDDEN MIXED ORDINARY DRIVER CONTENT', $controlledPayload);
        $this->assertStringContainsString('HIDDEN CONTROLLED DRIVER CONTENT', $controlledPayload);
        $this->assertStringContainsString('HIDDEN MIXED DRIVER NOTES', $controlledPayload);
        $this->assertStringNotContainsString('HIDDEN FORGED DRIVER CONTENT', $controlledPayload);
        $this->assertStringNotContainsString('HIDDEN UNLINKED DRIVER CONTENT', $controlledPayload);

        $reader = $this->medicationReader($site);
        $ordinaryPage = $this->actingAs($reader)
            ->get(route('emar.mar', ['client_id' => $client->id]))
            ->assertOk();
        $this->assertStringContainsString('VISIBLE ORDINARY DRIVER CONTENT', $ordinaryPage->getContent());
        $this->assertStringNotContainsString('HIDDEN MIXED ORDINARY DRIVER CONTENT', $ordinaryPage->getContent());
        $this->assertStringNotContainsString('HIDDEN CONTROLLED DRIVER CONTENT', $ordinaryPage->getContent());
        $this->assertStringNotContainsString('HIDDEN MIXED DRIVER NOTES', $ordinaryPage->getContent());
        $this->assertStringNotContainsString('HIDDEN FORGED DRIVER CONTENT', $ordinaryPage->getContent());
        $this->assertStringNotContainsString('HIDDEN UNLINKED DRIVER CONTENT', $ordinaryPage->getContent());

        $controlledPermission = Permission::query()
            ->where('key', 'medications.controlled.view')
            ->firstOrFail();
        $reader->permissionOverrides()->syncWithoutDetaching([
            $controlledPermission->id => ['allowed' => true],
        ]);
        $reader->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $controlledPage = $this->actingAs($reader)
            ->get(route('emar.mar', ['client_id' => $client->id]))
            ->assertOk();
        $this->assertStringContainsString('HIDDEN MIXED ORDINARY DRIVER CONTENT', $controlledPage->getContent());
        $this->assertStringContainsString('HIDDEN CONTROLLED DRIVER CONTENT', $controlledPage->getContent());
        $this->assertStringContainsString('HIDDEN MIXED DRIVER NOTES', $controlledPage->getContent());
        $this->assertStringNotContainsString('HIDDEN FORGED DRIVER CONTENT', $controlledPage->getContent());
        $this->assertStringNotContainsString('HIDDEN UNLINKED DRIVER CONTENT', $controlledPage->getContent());
    }

    private function medication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'end_date' => null,
            'is_prn' => true,
            'dose_times' => ['09:00'],
        ]);
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        User $recorder,
        string $status,
        string $notes,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => $status,
            'dose_given' => '1 tablet',
            'notes' => $notes,
        ]);
    }

    private function reportReader(Site $site): User
    {
        $reader = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $reader->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'start_date' => today()->subDay(),
        ]);

        $permissionIds = Permission::query()
            ->whereIn('key', ['reports.viewAny', 'medications.view'])
            ->pluck('id', 'key');
        $this->assertCount(2, $permissionIds, 'Missing seeded permission in test setup.');
        $reader->permissionOverrides()->sync([
            (int) $permissionIds['reports.viewAny'] => ['allowed' => true],
            (int) $permissionIds['medications.view'] => ['allowed' => false],
        ]);

        return $reader->refresh();
    }

    private function medicationReader(Site $site): User
    {
        $reader = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $reader->id,
            'primary_site_id' => $site->id,
            'is_active' => true,
            'start_date' => today()->subDay(),
        ]);

        $permission = Permission::query()->where('key', 'medications.view')->firstOrFail();
        $reader->permissionOverrides()->sync([
            $permission->id => ['allowed' => true],
        ]);

        return $reader->refresh();
    }

    private function assertNoForgedMedicationMarkers(string $payload): void
    {
        $this->assertStringNotContainsString('FOREIGN FORGED MEDICATION LEAK', $payload);
        $this->assertStringNotContainsString('FORGED CROSS-CLIENT ADMINISTRATION LEAK', $payload);
        $this->assertStringNotContainsString('FOREIGN OUT-OF-SCOPE ADMINISTRATION', $payload);
        $this->assertStringNotContainsString('FORGED CROSS-CLIENT MEDICATION ERROR LEAK', $payload);
        $this->assertStringNotContainsString('FORGED CROSS-CLIENT MEDICATION INCIDENT LEAK', $payload);
        $this->assertStringNotContainsString('MISSING HISTORICAL MEDICATION INCIDENT LEAK', $payload);
        $this->assertStringNotContainsString('MISSING HISTORICAL MEDICATION NAME', $payload);
    }
}
