<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationsScanVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_verification_matches_internal_emar_code_and_writes_audit_log(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $site = Site::factory()->create(['name' => 'Scan Verification Site']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Scan Verification',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
        $client->supportWorkers()->attach($actor->id);

        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);

        $internalCode = app(MedicationScanVerificationService::class)
            ->internalCode($client, $medication);

        $this->actingAs($actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$client->id}/medications/{$medication->id}/scan-verify",
                [
                    'code' => $internalCode,
                    'source' => 'manual',
                ],
            )
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('match_source', 'internal_emar');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.scan.verify',
            'client_id' => $client->id,
            'auditable_id' => $medication->id,
        ]);

        $this->assertSame(
            'internal_emar',
            AuditLog::query()
                ->where('action', 'medications.scan.verify')
                ->firstOrFail()
                ->meta['match_source'],
        );
    }

    public function test_scan_verification_rejects_mismatched_code_and_audits_attempt(): void
    {
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $site = Site::factory()->create(['name' => 'Rejected Scan Site']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $client = Client::factory()->create(['site_id' => $site->id]);
        $client->supportWorkers()->attach($actor->id);
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Omeprazole',
            'dosage' => '20mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$client->id}/medications/{$medication->id}/scan-verify",
                [
                    'code' => 'wrong-pack',
                    'source' => 'manual',
                ],
            )
            ->assertStatus(422)
            ->assertJsonPath('matched', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.scan.verify',
            'client_id' => $client->id,
            'auditable_id' => $medication->id,
        ]);
    }
}
