<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Http\Controllers\HealthSafety\LoneWorkerController;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\LoneWorkerSession;
use App\Models\Permission;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Integration\IntegrationContextProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoneWorkerLifecycleAndIntegrationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    #[DataProvider('checkInActors')]
    public function test_normal_check_in_remains_available_for_active_and_overdue_sessions(string $actorKind): void
    {
        $site = Site::factory()->create();
        $owner = $this->siteScopedUser($site);
        $actor = $actorKind === 'owner'
            ? $owner
            : $this->siteScopedUser($site, ['hazards.manage']);

        foreach (['active', 'overdue'] as $initialStatus) {
            $session = $this->makeSession($owner, $site, [
                'status' => $initialStatus,
                'last_check_in_at' => now()->subHour(),
            ]);

            $this->actingAs($actor)
                ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                    'status' => 'ok',
                    'notes' => 'Routine safety check-in.',
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $session->refresh();

            $this->assertSame('active', $session->status);
            $this->assertSame($actor->id, $session->updated_by);
            $this->assertSame(1, $session->checkIns()->count());
        }
    }

    #[DataProvider('checkInActors')]
    public function test_normal_check_in_does_not_clear_an_emergency_session(string $actorKind): void
    {
        $site = Site::factory()->create();
        $owner = $this->siteScopedUser($site);
        $actor = $actorKind === 'owner'
            ? $owner
            : $this->siteScopedUser($site, ['hazards.manage']);
        $emergencyAt = now()->subMinutes(10)->startOfSecond();
        $lastCheckInAt = now()->subMinutes(20)->startOfSecond();
        $session = $this->makeSession($owner, $site, [
            'status' => 'emergency',
            'emergency_triggered_at' => $emergencyAt,
            'emergency_notes' => 'Worker requested urgent help.',
            'last_check_in_at' => $lastCheckInAt,
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                'status' => 'ok',
                'notes' => 'Routine check-in must not resolve an emergency.',
            ]);

        $session->refresh();

        $this->assertSame('emergency', $session->status);
        $this->assertTrue($session->emergency_triggered_at->equalTo($emergencyAt));
        $this->assertSame('Worker requested urgent help.', $session->emergency_notes);
        $this->assertTrue($session->last_check_in_at->equalTo($lastCheckInAt));
        $this->assertSame(0, $session->checkIns()->count());
    }

    #[DataProvider('checkInActors')]
    public function test_completed_session_rejects_normal_check_in_and_remains_terminal(string $actorKind): void
    {
        $site = Site::factory()->create();
        $owner = $this->siteScopedUser($site);
        $actor = $actorKind === 'owner'
            ? $owner
            : $this->siteScopedUser($site, ['hazards.manage']);
        $endedAt = now()->subHour()->startOfSecond();
        $lastCheckInAt = now()->subHours(2)->startOfSecond();
        $session = $this->makeSession($owner, $site, [
            'status' => 'completed',
            'ended_at' => $endedAt,
            'last_check_in_at' => $lastCheckInAt,
        ]);

        $this->actingAs($actor)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/check-in", [
                'status' => 'ok',
            ]);

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertTrue($session->ended_at->equalTo($endedAt));
        $this->assertTrue($session->last_check_in_at->equalTo($lastCheckInAt));
        $this->assertSame(0, $session->checkIns()->count());
    }

    public function test_completed_session_cannot_be_reopened_as_an_emergency(): void
    {
        $site = Site::factory()->create();
        $worker = $this->siteScopedUser($site);
        $coordinator = $this->siteScopedUser($site, ['hazards.manage']);
        $endedAt = now()->subHour()->startOfSecond();
        $session = $this->makeSession($worker, $site, [
            'status' => 'completed',
            'ended_at' => $endedAt,
        ]);

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/emergency", [
                'emergency_notes' => 'This completed record must stay terminal.',
            ]);

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertTrue($session->ended_at->equalTo($endedAt));
        $this->assertNull($session->emergency_triggered_at);
        $this->assertNull($session->emergency_notes);
        $this->assertSame(0, $session->alerts()->count());
        $this->assertSame(0, ControlRoomAlert::query()
            ->where('source', 'lone_worker')
            ->where('context->normalized_data->lone_worker_session_id', $session->id)
            ->count());
    }

    public function test_repeated_emergency_trigger_does_not_duplicate_the_emergency_alert(): void
    {
        $site = Site::factory()->create();
        $worker = $this->siteScopedUser($site);
        $coordinator = $this->siteScopedUser($site, ['hazards.manage']);
        $session = $this->makeSession($worker, $site);

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/emergency", [
                'emergency_notes' => 'Worker requested urgent help.',
            ])
            ->assertRedirect();
        $firstTriggeredAt = $session->fresh()->emergency_triggered_at;

        $this->actingAs($coordinator)
            ->post("/health-safety/lone-workers/sessions/{$session->id}/emergency", [
                'emergency_notes' => 'Duplicate emergency request.',
            ])
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('emergency', $session->status);
        $this->assertNotNull($firstTriggeredAt);
        $this->assertTrue($session->emergency_triggered_at->equalTo($firstTriggeredAt));
        $this->assertSame('Worker requested urgent help.', $session->emergency_notes);
        $this->assertSame(1, $session->alerts()->where('alert_type', 'emergency')->count());
    }

    public function test_stale_session_update_cannot_clear_an_emergency_transition(): void
    {
        $site = Site::factory()->create();
        $worker = $this->siteScopedUser($site);
        $coordinator = $this->siteScopedUser($site, ['hazards.manage']);
        $session = $this->makeSession($worker, $site);
        $staleSession = LoneWorkerSession::query()->findOrFail($session->id);
        $emergencyAt = now()->subMinute()->startOfSecond();

        LoneWorkerSession::query()->whereKey($session->id)->update([
            'status' => 'emergency',
            'emergency_triggered_at' => $emergencyAt,
            'emergency_notes' => 'Emergency won the race.',
        ]);

        $request = Request::create(
            "/health-safety/lone-workers/sessions/{$session->id}",
            'PATCH',
            [
                'expected_end_at' => now()->addHours(3)->toDateTimeString(),
                'activity_description' => 'A stale coordinator edit.',
            ],
        );
        $request->setUserResolver(fn () => $coordinator);

        app(LoneWorkerController::class)->updateSession($request, $staleSession);

        $session->refresh();
        $this->assertSame('emergency', $session->status);
        $this->assertTrue($session->emergency_triggered_at->equalTo($emergencyAt));
        $this->assertSame('Emergency won the race.', $session->emergency_notes);
    }

    public function test_session_detail_does_not_expose_a_foreign_tenant_worker_tracker(): void
    {
        $site = Site::factory()->create();
        $worker = $this->siteScopedUser($site);
        $coordinator = $this->siteScopedUser($site, ['hazards.view']);
        $session = $this->makeSession($worker, $site);
        $foreignTracker = Device::factory()->create([
            'tenant_id' => 999,
            'domain' => 'tracking',
            'imei' => 'FOREIGN-TENANT-IMEI',
        ]);
        DeviceAssignment::create([
            'device_id' => $foreignTracker->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $worker->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($coordinator)
            ->get("/health-safety/lone-workers?session={$session->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('detail.id', $session->id)
                ->where('detail.tracker', null)
            );
    }

    public function test_session_creation_cannot_leave_a_site_scoped_coordinator_with_an_active_orphan(): void
    {
        $site = Site::factory()->create();
        $coordinator = $this->siteScopedUser($site, ['hazards.manage']);
        $worker = $this->siteScopedUser($site);

        $this->actingAs($coordinator)
            ->post('/health-safety/lone-workers/sessions', [
                'user_id' => $worker->id,
                'expected_end_at' => now()->addHours(2)->toDateTimeString(),
                'activity_description' => 'Ad-hoc lone-worker visit.',
            ]);

        $this->assertFalse(LoneWorkerSession::query()
            ->where('user_id', $worker->id)
            ->where('status', 'active')
            ->whereNull('site_id')
            ->exists());

        $createdSession = LoneWorkerSession::query()
            ->where('user_id', $worker->id)
            ->latest('id')
            ->first();

        if ($createdSession) {
            $this->assertSame($site->id, $createdSession->site_id);
        }
    }

    public function test_integration_context_uses_the_supplied_canonical_site_for_devices(): void
    {
        $tenantId = 37;
        $site = Site::factory()->create(['tenant_id' => $tenantId]);
        $this->assignDeviceToSite($tenantId, $site);
        $this->assignDeviceToSite($tenantId, $site);
        $this->assignDeviceToSite(1, $site);

        $context = app(IntegrationContextProvider::class)->getContext($site->id);

        $this->assertSame(3, $context['site_summary']['hardware_total']);
        $this->assertSame(3, $context['site_summary']['hardware_online']);
        $this->assertSame(0, $context['site_summary']['hardware_offline']);
    }

    public function test_integration_context_returns_the_requested_canonical_site(): void
    {
        $site = Site::factory()->create(['tenant_id' => 42]);
        IntegrationEvent::factory()->create([
            'tenant_id' => 42,
            'site_id' => $site->id,
            'event_type' => 'site_event',
        ]);
        SiteRoom::create([
            'tenant_id' => 42,
            'site_id' => $site->id,
            'name' => 'Site room',
        ]);
        ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'source' => 'integration_nurse_call',
            'status' => ControlRoomAlert::STATUS_OPEN,
        ]);

        $context = app(IntegrationContextProvider::class)->getContext($site->id);
        $this->assertSame(0, $context['site_summary']['hardware_total']);
        $this->assertSame(1, $context['site_summary']['open_alerts']);
        $this->assertCount(1, $context['recent_events']);
        $this->assertCount(1, $context['open_alerts']);
        $this->assertCount(1, $context['rooms']);
    }

    public function test_integration_context_does_not_follow_poisoned_cross_tenant_room_or_hardware_links(): void
    {
        $tenantId = 51;
        $foreignTenantId = 52;
        $site = Site::factory()->create(['tenant_id' => $tenantId]);
        $foreignSite = Site::factory()->create(['tenant_id' => $foreignTenantId]);
        $localRoom = SiteRoom::create([
            'tenant_id' => $tenantId,
            'site_id' => $site->id,
            'name' => 'Local room',
        ]);
        $foreignRoom = SiteRoom::create([
            'tenant_id' => $foreignTenantId,
            'site_id' => $foreignSite->id,
            'name' => 'Foreign room name',
        ]);
        $foreignHardware = LocationHardware::create([
            'tenant_id' => $foreignTenantId,
            'site_id' => $foreignSite->id,
            'room_id' => $localRoom->id,
            'provider' => 'manual',
            'category' => 'sensor',
            'name' => 'Foreign hardware name',
            'status' => 'online',
        ]);
        IntegrationEvent::factory()->create([
            'tenant_id' => $tenantId,
            'site_id' => $site->id,
            'room_id' => $foreignRoom->id,
            'hardware_id' => $foreignHardware->id,
            'event_type' => 'poisoned_relation_event',
        ]);

        $context = app(IntegrationContextProvider::class)->getContext($site->id);

        $this->assertCount(1, $context['recent_events']);
        $this->assertNull($context['recent_events'][0]['hardware']);
        $this->assertNull($context['recent_events'][0]['room']);
        $this->assertSame('Local room', $context['rooms'][0]['name']);
        $this->assertSame(0, $context['rooms'][0]['hardware_count']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function checkInActors(): array
    {
        return [
            'owner' => ['owner'],
            'coordinator' => ['coordinator'],
        ];
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteScopedUser(Site $site, array $permissionKeys = []): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function makeSession(User $worker, Site $site, array $overrides = []): LoneWorkerSession
    {
        return LoneWorkerSession::create(array_merge([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'started_at' => now()->subHour(),
            'expected_end_at' => now()->addHours(2),
            'last_check_in_at' => now()->subMinutes(10),
            'check_in_interval_minutes' => 30,
            'status' => 'active',
            'activity_description' => 'Home visit',
            'created_by' => $worker->id,
            'updated_by' => $worker->id,
        ], $overrides));
    }

    private function assignDeviceToSite(int $tenantId, Site $site): Device
    {
        $device = Device::factory()->create(['tenant_id' => $tenantId]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }
}
