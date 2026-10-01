<?php

namespace Tests\Feature;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MedicationsApiControllerIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $actor;

    protected Client $client;

    protected ClientMedication $medication;

    /** The medication's dose slot: the current worker-time minute, in UTC. */
    protected CarbonInterface $slot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->actor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->actor->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

        $site = Site::factory()->create(['name' => 'API Idempotency Site']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'API Idempotency',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $site->id,
        ]);
        $this->client->supportWorkers()->attach($this->actor->id);

        // Since 0cb4a4190 the API record path needs a genuinely qualified
        // actor: a signed-off competency and a clocked-in Shift covering this
        // client at its Site. Without them it answers 403 before idempotency.
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->actor->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => today()->subMonth(),
            'expiry_date' => today()->addYear(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->actor->id,
            'status' => 'in_progress',
        ]);

        // A scheduled dose must match a real dose_times slot, so anchor the
        // slot to the current worker-time minute.
        $workerSlot = now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfMinute();
        $this->slot = $workerSlot->copy()->utc();

        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => [$workerSlot->format('H:i')],
            'active' => true,
            'state' => 'active',
        ]);
    }

    public function test_duplicate_uuid_returns_cached_sync_payload(): void
    {
        $scheduledFor = $this->slot;
        // The fixture Shift writes its own timeline snapshot; count only the
        // administration's event.
        $timelineBefore = DB::table('timeline_events')->count();
        $payload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'administered_at' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => '39b88216-6350-46d3-ad65-7d8ce327c92c',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'api-device',
            'queued_offline' => true,
        ];

        $url = "/api/medications/clients/{$this->client->id}/medications/{$this->medication->id}/administrations";

        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced');

        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame($timelineBefore + 1, DB::table('timeline_events')->count());
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_request_uuid' => $payload['client_request_uuid'],
        ]);
    }

    public function test_successful_api_administration_cache_expires_after_seven_days(): void
    {
        $now = $this->slot->copy();
        $this->travelTo($now);

        $scheduledFor = $this->slot;
        $uuid = 'b57b4b6d-7322-4b5e-82d7-841d9453562e';
        $payload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'administered_at' => $scheduledFor->toIso8601String(),
            'client_request_uuid' => $uuid,
            // An online submission: offline provenance fields are prohibited
            // unless the request was queued offline.
            'queued_offline' => false,
        ];

        $this->actingAs($this->actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$this->client->id}/medications/{$this->medication->id}/administrations",
                $payload,
            )
            ->assertOk()
            ->assertJsonPath('sync.status', 'processed');

        $cacheKey = "emar:idempotency:administration:{$uuid}";

        $this->assertTrue(Cache::has($cacheKey));

        $this->travelTo($now->copy()->addDays(6)->addHours(23));
        $this->assertTrue(Cache::has($cacheKey));

        $this->travelTo($now->copy()->addDays(7)->addMinute());
        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_queued_api_replay_conflicts_with_existing_scheduled_record(): void
    {
        $scheduledFor = $this->slot;

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'administered_by' => $this->actor->id,
            'scheduled_for' => $scheduledFor,
            'administered_at' => $scheduledFor,
            'status' => 'given',
        ]);

        $this->actingAs($this->actor, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$this->client->id}/medications/{$this->medication->id}/administrations",
                [
                    'status' => 'given',
                    'dose_given' => '500mg',
                    'scheduled_for' => $scheduledFor->copy()->addSeconds(30)->toIso8601String(),
                    'administered_at' => $scheduledFor->toIso8601String(),
                    'client_request_uuid' => 'f312c7f5-686c-44b7-9354-9f52762335a6',
                    'captured_offline_at' => now()->subMinutes(10)->toIso8601String(),
                    'origin_device_id' => 'api-device',
                    'queued_offline' => true,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }
}
