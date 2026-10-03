<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\NotificationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class ClientMedicalAdministrationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected Site $site;

    protected Client $client;

    protected ClientMedication $medication;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-01 08:10:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->site = $site = Site::factory()->create([
            'name' => 'Operations eMAR Home',
            'is_active' => true,
        ]);
        $this->operator = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->operator->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'support_worker')->firstOrFail()->id,
        ]);
        $permissionIds = Permission::query()
            ->whereIn('key', [
                'clients.viewAssigned',
                'medications.view',
                'medications.administer.record',
            ])
            ->pluck('id');
        $this->operator->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn ($permissionId) => [$permissionId => ['allowed' => true]],
        ));
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->operator->id,
            'position_role' => 'support_worker',
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $this->operator->id,
            'updated_by' => $this->operator->id,
        ]);
        // A bare `passed` row reads as unassessed: signing "given" needs a
        // declared, acknowledged assessment by someone other than the worker.
        $assessor = User::factory()->create([
            'role' => 'manager',
            'approved_at' => now(),
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->operator->id,
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
            'name' => 'Operations MAR',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);
        $this->client->supportWorkers()->syncWithoutDetaching([$this->operator->id]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $this->operator->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->operator->id,
            'status' => 'in_progress',
        ]);

        // Entered the day before: nothing is owed before an order exists, so
        // an order entered at 08:10 would owe no 08:00 dose today.
        Carbon::setTestNow(Carbon::now()->subDay());
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
        Carbon::setTestNow(Carbon::now()->addDay());

        $notification = Mockery::mock(NotificationService::class);
        $notification->shouldReceive('notifyCrud')->andReturnNull();
        $this->app->instance(NotificationService::class, $notification);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function siteColleague(): User
    {
        $colleague = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $colleague->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'support_worker')->firstOrFail()->id,
        ]);
        $colleague->permissionOverrides()->sync([
            Permission::query()->where('key', 'medications.administer.record')->value('id') => ['allowed' => true],
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $colleague->id,
            'position_role' => 'support_worker',
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $colleague->id,
            'updated_by' => $colleague->id,
        ]);

        return $colleague;
    }

    public function test_duplicate_client_request_uuid_returns_cached_response_without_second_write(): void
    {
        // Administrations notify Site colleagues who record doses (not via
        // notifyCrud); the duplicate replay must not notify them again.
        Notification::fake();
        $colleague = $this->siteColleague();

        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);
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

        $this->actingAs($this->operator)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'synced');

        Cache::forget('emar:idempotency:administration:'.$payload['client_request_uuid']);

        $this->actingAs($this->operator)
            ->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sync.status', 'duplicate')
            ->assertJsonPath('sync.duplicate', true);

        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame(
            1,
            TimelineEvent::query()
                ->where('source_type', ClientMedicationAdministration::class)
                ->count(),
        );
        $this->assertDatabaseHas('client_medication_administrations', [
            'client_request_uuid' => $payload['client_request_uuid'],
        ]);
        Notification::assertSentToTimes($colleague, AppEventNotification::class, 1);
    }

    public function test_offline_replay_conflicts_with_existing_scheduled_record(): void
    {
        $scheduledFor = Carbon::now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(8, 0);

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medication->id,
            'administered_by' => $this->operator->id,
            'scheduled_for' => $scheduledFor,
            'administered_at' => $scheduledFor,
            'status' => 'given',
            'dose_given' => '500mg',
        ]);

        $this->actingAs($this->operator)
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
