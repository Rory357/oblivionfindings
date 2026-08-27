<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationRecordOnlyApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_record_only_worker_with_covering_shift_can_verify_and_record_for_assigned_client(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $worker = $this->recordOnlyWorker($site);
        [$client, $medication] = $this->activePrnMedication($site, 'Assigned medication');
        $shift = $this->startShift($worker, $site, $client);

        $this->assertTrue($worker->canDo('medications.administer.record'));
        $this->assertFalse($worker->canDo('medications.view'));

        $this->actingAs($worker, 'sanctum')
            ->getJson(route('api.medications.scan_code.show', [$client, $medication]))
            ->assertForbidden();

        $this->actingAs($worker, 'sanctum')
            ->postJson(route('api.medications.scan.verify', [$client, $medication]), [
                'code' => $this->internalScanCode($client, $medication),
                'source' => 'manual',
            ])
            ->assertOk()
            ->assertJsonPath('matched', true);

        $this->actingAs($worker, 'sanctum')
            ->postJson(route('api.medications.administrations.record', [$client, $medication]), [
                'status' => 'refused',
                'reason_code' => 'refused',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $worker->id,
            'shift_id' => $shift->id,
            'status' => 'refused',
        ]);
    }

    public function test_record_only_worker_cannot_use_foreign_site_or_foreign_client_medication_targets(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $worker = $this->recordOnlyWorker($site);
        [$localClient] = $this->activePrnMedication($site, 'Local medication');
        [$foreignClient, $foreignMedication] = $this->activePrnMedication($foreignSite, 'Foreign medication');
        $this->startShift($worker, $site, $localClient);

        $this->actingAs($worker, 'sanctum')
            ->postJson(route('api.medications.scan.verify', [$foreignClient, $foreignMedication]), [
                'code' => $this->internalScanCode($foreignClient, $foreignMedication),
            ])
            ->assertNotFound();
        $this->actingAs($worker, 'sanctum')
            ->postJson(route('api.medications.scan.verify', [$localClient, $foreignMedication]), [
                'code' => $this->internalScanCode($foreignClient, $foreignMedication),
            ])
            ->assertNotFound();

        foreach ([
            [$foreignClient, $foreignMedication],
            [$localClient, $foreignMedication],
        ] as [$submittedClient, $submittedMedication]) {
            $this->actingAs($worker, 'sanctum')
                ->postJson(route('api.medications.administrations.record', [$submittedClient, $submittedMedication]), [
                    'status' => 'refused',
                    'reason_code' => 'refused',
                    'administered_at' => now()->toIso8601String(),
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'medications.scan.verify')
            ->where('auditable_id', $foreignMedication->id)
            ->count());
    }

    public function test_record_only_worker_without_target_assignment_cannot_verify_or_record(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $worker = $this->recordOnlyWorker($site);
        [$assignedClient] = $this->activePrnMedication($site, 'Assigned medication');
        [$unassignedClient, $unassignedMedication] = $this->activePrnMedication($site, 'Unassigned medication');
        $this->startShift($worker, $site, $assignedClient);

        $this->actingAs($worker, 'sanctum')
            ->postJson(route('api.medications.scan.verify', [$unassignedClient, $unassignedMedication]), [
                'code' => $this->internalScanCode($unassignedClient, $unassignedMedication),
            ])
            ->assertForbidden();
        $this->actingAs($worker, 'sanctum')
            ->postJson(route('api.medications.administrations.record', [$unassignedClient, $unassignedMedication]), [
                'status' => 'refused',
                'reason_code' => 'refused',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'medications.scan.verify')
            ->where('auditable_id', $unassignedMedication->id)
            ->count());
    }

    private function recordOnlyWorker(Site $site): User
    {
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionId = Permission::query()
            ->where('key', 'medications.administer.record')
            ->value('id');
        $worker->permissionOverrides()->sync([
            $permissionId => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subYear(),
            'end_date' => null,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $worker->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today(),
            'expiry_date' => today()->addYear(),
        ]);

        return $worker;
    }

    /** @return array{0: Client, 1: ClientMedication} */
    private function activePrnMedication(Site $site, string $name): array
    {
        $client = Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
        ]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'name' => $name,
            'frequency' => 'PRN',
            'is_prn' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'controlled_drug' => false,
        ]);

        return [$client, $medication];
    }

    private function startShift(User $worker, Site $site, Client $client): Shift
    {
        return Shift::factory()->forSite($site)->create([
            'client_id' => $client->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $worker->id,
            'created_by' => $worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $worker->id,
            'status' => 'in_progress',
        ]);
    }

    private function internalScanCode(Client $client, ClientMedication $medication): string
    {
        return app(MedicationScanVerificationService::class)
            ->internalCode($client, $medication);
    }
}
