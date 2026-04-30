<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Role;
use App\Models\ServiceContext;
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

        $admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->first());

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Scan Verification',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
        ]);

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

        $this->actingAs($admin, 'sanctum')
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

        $admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $admin->roles()->attach(Role::query()->where('name', 'admin')->first());

        $client = Client::factory()->create();
        $medication = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Omeprazole',
            'dosage' => '20mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($admin, 'sanctum')
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
