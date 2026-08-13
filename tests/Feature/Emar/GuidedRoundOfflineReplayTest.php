<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
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

class GuidedRoundOfflineReplayTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected ClientMedication $medication;

    protected MedicationRound $round;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->first());
        $site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today(),
            'expiry_date' => today()->addYear(),
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Guided Round',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Loratadine',
            'dosage' => '10mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);

        $this->round = MedicationRound::query()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
            'name' => 'Morning round',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
            'status' => 'in_progress',
            'assigned_to' => $this->worker->id,
            'started_by' => $this->worker->id,
            'started_at' => now(),
            'total_medications' => 1,
        ]);
    }

    public function test_duplicate_round_admin_uuid_is_idempotent(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
        $payload = [
            'status' => 'given',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => '41a75a8a-aa69-4d74-b094-cfbf2925ac6d',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'round-device',
            'queued_offline' => false,
        ];

        $url = "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}";

        $this->actingAs($this->worker)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'processed');

        $this->actingAs($this->worker)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'duplicate');

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $this->medication->id,
            'medication_round_id' => $this->round->id,
            'status' => 'given',
        ]);
    }

    public function test_queued_round_admin_conflicts_when_round_dose_already_exists(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'medication_round_id' => $this->round->id,
            'administered_by' => $this->worker->id,
            'scheduled_for' => $scheduledFor->copy()->utc(),
            'administered_at' => $scheduledFor->copy()->utc(),
            'status' => 'given',
        ]);

        $this->actingAs($this->worker)
            ->postJson(
                "/emar/rounds/{$this->round->id}/guided/items/{$this->medication->id}",
                [
                    'status' => 'given',
                    'scheduled_for' => $scheduledFor->copy()->addSeconds(20)->toIso8601String(),
                    'client_request_uuid' => 'bb1f2ab9-b92c-446d-9189-6feb25b83cce',
                    'captured_offline_at' => now()->subMinutes(10)->toIso8601String(),
                    'origin_device_id' => 'round-device',
                    'queued_offline' => true,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }
}
