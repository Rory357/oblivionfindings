<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MedicationsApiControllerIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Client $client;

    protected ClientMedication $medication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->first());

        $serviceContext = ServiceContext::factory()->create([
            'name' => 'API Idempotency',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
        ]);

        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Metformin',
            'dosage' => '500mg',
            'frequency' => 'Once daily',
            'dose_times' => ['08:00'],
            'active' => true,
            'state' => 'active',
        ]);
    }

    public function test_duplicate_uuid_returns_cached_sync_payload(): void
    {
        $scheduledFor = now()->setTime(8, 0);
        $payload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'administered_at' => $scheduledFor->copy()->addMinute()->toIso8601String(),
            'client_request_uuid' => '39b88216-6350-46d3-ad65-7d8ce327c92c',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'api-device',
            'queued_offline' => true,
        ];

        $url = "/api/medications/clients/{$this->client->id}/medications/{$this->medication->id}/administrations";

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'synced');

        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('timeline_events', 1);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_request_uuid' => $payload['client_request_uuid'],
        ]);
    }

    public function test_successful_api_administration_cache_expires_after_seven_days(): void
    {
        $now = now()->startOfMinute();
        $this->travelTo($now);

        $scheduledFor = $now->copy()->setTime(8, 0);
        $uuid = 'b57b4b6d-7322-4b5e-82d7-841d9453562e';
        $payload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'administered_at' => $scheduledFor->copy()->addMinute()->toIso8601String(),
            'client_request_uuid' => $uuid,
            'captured_offline_at' => $now->toIso8601String(),
            'origin_device_id' => 'api-device',
            'queued_offline' => false,
        ];

        $this->actingAs($this->admin, 'sanctum')
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
        $scheduledFor = now()->setTime(8, 0);

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'administered_by' => $this->admin->id,
            'scheduled_for' => $scheduledFor,
            'administered_at' => $scheduledFor,
            'status' => 'given',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson(
                "/api/medications/clients/{$this->client->id}/medications/{$this->medication->id}/administrations",
                [
                    'status' => 'given',
                    'dose_given' => '500mg',
                    'scheduled_for' => $scheduledFor->copy()->addSeconds(30)->toIso8601String(),
                    'administered_at' => $scheduledFor->copy()->addMinutes(5)->toIso8601String(),
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
