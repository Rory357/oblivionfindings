<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrnQuickRecordTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected ClientMedication $prn;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($this->worker, ['medications.administer.record']);

        // A current Site worker with a valid, independently assessed
        // competency — the administration path fails closed without both.
        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'PRN Quick',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);

        $this->prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_prn_quick_record_creates_administration_with_shift_context(): void
    {
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', [
                'client_medication_id' => $this->prn->id,
                'reason' => 'Pain',
                'dose_given' => '500mg',
                'notes' => 'Settled after lunch.',
                'client_request_uuid' => '3f6c2a1e-9b4d-4e7a-8c5f-1d2e3f4a5b6c',
                'queued_offline' => false,
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_id' => $this->client->id,
            'client_medication_id' => $this->prn->id,
            'administered_by' => $this->worker->id,
            'status' => 'given',
            'reason' => 'Pain',
            'dose_given' => '500mg',
        ]);
    }

    public function test_prn_quick_record_is_idempotent_for_replayed_uuid(): void
    {
        $payload = [
            'client_medication_id' => $this->prn->id,
            'reason' => 'Pain',
            'dose_given' => '500mg',
            'client_request_uuid' => '6a1b2c3d-4e5f-4a6b-9c7d-8e9f0a1b2c3d',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'test-device',
            'queued_offline' => true,
        ];

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today');

        Cache::forget('offline:idempotency:prn:6a1b2c3d-4e5f-4a6b-9c7d-8e9f0a1b2c3d');

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success', 'Already saved — no changes needed.');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_request_uuid' => '6a1b2c3d-4e5f-4a6b-9c7d-8e9f0a1b2c3d',
        ]);
    }

    public function test_stale_outer_replay_marker_cannot_suppress_a_prn_over_limit_incident(): void
    {
        $attemptId = '8d7c6b5a-4f3e-4d2c-b1a0-9f8e7d6c5b4a';
        // A verified order whose limit is already 1 — a model update would
        // (correctly) send the changed order back for re-verification.
        ClientMedication::query()->whereKey($this->prn->id)->update(['max_per_day' => 1]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->prn->id,
            'administered_by' => $this->worker->id,
            'status' => 'given',
            'administered_at' => now()->subHour(),
        ]);
        Cache::put("offline:idempotency:prn:{$attemptId}", [
            'processed_at' => now()->subMinute()->toIso8601String(),
            'device' => 'test-device',
            'queued_offline' => true,
        ], now()->addDays(7));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn', [
                'client_medication_id' => $this->prn->id,
                'reason' => 'Breakthrough pain',
                'dose_given' => '500mg',
                'client_request_uuid' => $attemptId,
                'captured_offline_at' => now()->toIso8601String(),
                'origin_device_id' => 'test-device',
                'queued_offline' => true,
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('client_medication_id');

        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertSame(
            $attemptId,
            data_get(ClientIncident::query()->sole()->metadata, 'medication_prn_attempt.id'),
        );
        $this->assertSame(
            $attemptId,
            data_get(Signal::query()->sole()->normalized_data, 'prn_attempt_id'),
        );
        $this->assertSame(
            ClientIncident::query()->sole()->id,
            data_get(ControlRoomAlert::query()->sole()->context, 'incident_id'),
        );
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

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
