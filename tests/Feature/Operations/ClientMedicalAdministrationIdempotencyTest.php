<?php

namespace Tests\Feature\Operations;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ClientMedicalAdministrationIdempotencyTest extends TestCase
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
            'name' => 'Operations MAR',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
        ]);

        $this->medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Twice daily',
            'dose_times' => ['08:00', '20:00'],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
        ]);

        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldReceive('notifyCrud')->andReturnNull();
        $this->app->instance(NotificationService::class, $notification);
    }

    public function test_duplicate_client_request_uuid_returns_cached_response_without_second_write(): void
    {
        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldReceive('notifyCrud')->once()->andReturnNull();
        $this->app->instance(NotificationService::class, $notification);

        $scheduledFor = now()->setTime(8, 0);
        $payload = [
            'status' => 'given',
            'dose_given' => '500mg',
            'scheduled_for' => $scheduledFor->toIso8601String(),
            'administered_at' => $scheduledFor->copy()->addMinutes(3)->toIso8601String(),
            'client_request_uuid' => '5f996066-45d0-44a0-9c61-f88bc13d31f4',
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'test-device',
            'queued_offline' => true,
        ];

        $url = "/operations/clients/{$this->client->id}/medical/medications/{$this->medication->id}/administrations";

        $this->actingAs($this->admin)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'synced');

        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);

        $this->actingAs($this->admin)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('timeline_events', 1);
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_request_uuid' => $payload['client_request_uuid'],
        ]);
    }

    public function test_offline_replay_conflicts_with_existing_scheduled_record(): void
    {
        $scheduledFor = now()->setTime(8, 0);

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'administered_by' => $this->admin->id,
            'scheduled_for' => $scheduledFor,
            'administered_at' => $scheduledFor,
            'status' => 'given',
            'dose_given' => '500mg',
        ]);

        $this->actingAs($this->admin)
            ->postJson(
                "/operations/clients/{$this->client->id}/medical/medications/{$this->medication->id}/administrations",
                [
                    'status' => 'given',
                    'dose_given' => '500mg',
                    'scheduled_for' => $scheduledFor->copy()->addSeconds(20)->toIso8601String(),
                    'administered_at' => $scheduledFor->copy()->addMinutes(4)->toIso8601String(),
                    'client_request_uuid' => '605350c8-0f59-4cc5-b9c3-2473324095e7',
                    'captured_offline_at' => now()->subMinutes(5)->toIso8601String(),
                    'origin_device_id' => 'test-device',
                    'queued_offline' => true,
                ],
            )
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('sync.status', 'conflict');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }
}
