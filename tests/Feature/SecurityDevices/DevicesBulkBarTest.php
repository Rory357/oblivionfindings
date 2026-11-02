<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceEventSignalOutbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A provider that answers for the doors in this test only. */
final class DevicesBulkBarDoorAdapter implements CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool
    {
        return $device->provider === 'bulk-bar-contract' && $capability === 'access.door.unlock_timed';
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: ['provider_state' => 'accepted'],
            providerRequestReference: 'bulk-bar:'.$context->attemptUuid,
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'bulk-bar:'.$context->attemptUuid,
            safeEvidenceSummary: 'The expected Device state was freshly confirmed.',
        );
    }
}

/**
 * S&D rebuild C6 gap: the Devices bulk bar runs a governed action on several devices through today's
 * command-batch flow (plan: "/command-batches/{id} → Bulk bar on Devices runs the action with one
 * confirm; results land in the device logbooks"). Every batch check stays; trackers are never offered.
 */
class DevicesBulkBarTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        config()->set('monitoring.signing.active_key_id', 'bulk-bar-test');
        config()->set('monitoring.signing.keys', [
            'bulk-bar-test' => base64_encode(str_repeat('C', SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ]);
        app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([new DevicesBulkBarDoorAdapter]));
        $this->site = Site::factory()->create(['name' => 'Synthetic House']);
    }

    private function actor(string $role = 'it_manager', ?Site $site = null): User
    {
        $actor = User::factory()->create(['approved_at' => now()]);
        $actor->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        if ($site !== null) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
            ]);
        }

        return $actor;
    }

    private function grant(User $user, string $key): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', $key)->firstOrFail()->id => ['allowed' => true],
        ]);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function placed(Device $device, Site $site): Device
    {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        return $device;
    }

    private function door(?Site $site = null, array $attributes = []): Device
    {
        return $this->placed(Device::factory()->security()->create(array_replace([
            'category' => 'access_control',
            'subcategory' => 'door_controller',
            'provider' => 'bulk-bar-contract',
            'last_seen_at' => now(),
            'config' => ['management' => ['capabilities' => ['access.door.unlock_timed']]],
        ], $attributes)), $site ?? $this->site);
    }

    private function tracker(): Device
    {
        return $this->placed(Device::factory()->tracking()->create([
            'provider' => 'bulk-bar-contract',
            'last_seen_at' => now(),
            'config' => ['management' => ['capabilities' => ['access.door.unlock_timed']]],
        ]), $this->site);
    }

    /** @param list<Device> $devices */
    private function payload(array $devices, array $overrides = []): array
    {
        return array_replace([
            'workspace' => 'security',
            'device_ids' => array_map(fn (Device $device): int => (int) $device->id, $devices),
            'capability' => 'access.door.unlock_timed',
            'parameters' => ['duration_seconds' => 15],
            'reason' => 'Contractors need the service doors for the lift repair.',
            'idempotency_key' => 'bulk_devices_test_'.implode('_', array_map(fn (Device $device): int => (int) $device->id, $devices)),
            'impact_acknowledged' => true,
            'confirmation_text' => 'BULK '.count($devices).' DEVICES',
        ], $overrides);
    }

    public function test_the_bar_offers_the_action_the_ticked_doors_share_and_leaves_trackers_out(): void
    {
        $actor = $this->actor();
        $north = $this->door(null, ['name' => 'North service door']);
        $south = $this->door(null, ['name' => 'South service door']);
        $tracker = $this->tracker();

        $response = $this->actingAs($actor)
            ->getJson('/security-devices/devices/bulk-actions?'.http_build_query(['ids' => [$north->id, $south->id, $tracker->id]]))
            ->assertOk();

        $actions = $response->json('actions');
        $this->assertCount(1, $actions);
        $this->assertSame('access.door.unlock_timed', $actions[0]['key']);
        $this->assertSame('security', $actions[0]['workspace']);
        $this->assertSame([$north->id, $south->id], array_column($actions[0]['devices'], 'id'));
        $this->assertSame(2, $actions[0]['ready']);
        $this->assertTrue($actions[0]['elevated']);
        $this->assertTrue($actions[0]['needs_approval']);
        $this->assertSame('BULK 2 DEVICES', $actions[0]['phrase']);
        $this->assertNotContains($tracker->id, array_column($actions[0]['devices'], 'id'));
        $this->assertSame([$tracker->id], array_column($response->json('left_out'), 'id'));
        $this->assertStringContainsString('Fleet', $response->json('left_out.0.reason'));
    }

    public function test_the_bar_is_refused_without_a_key_that_may_start_a_batch(): void
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        foreach (['securityDevices.viewAny', 'securityDevices.devices.view'] as $key) {
            $this->grant($viewer, $key);
        }
        $north = $this->door();
        $south = $this->door();

        $this->actingAs($viewer)
            ->getJson('/security-devices/devices/bulk-actions?'.http_build_query(['ids' => [$north->id, $south->id]]))
            ->assertForbidden();

        $this->actingAs($viewer)->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.commands', false));
        $this->actingAs($this->actor())->get('/security-devices/devices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.commands', true));
    }

    public function test_devices_at_a_site_the_viewer_cannot_see_are_never_offered(): void
    {
        $hidden = Site::factory()->create();
        $coordinator = $this->actor('coordinator', $this->site);
        $this->grant($coordinator, 'securityDevices.commands.control');
        $first = $this->door($hidden);
        $second = $this->door($hidden);

        $response = $this->actingAs($coordinator)
            ->getJson('/security-devices/devices/bulk-actions?'.http_build_query(['ids' => [$first->id, $second->id]]))
            ->assertOk();

        $this->assertSame([], $response->json('actions'));
        $this->assertSame([], $response->json('left_out'));
    }

    public function test_one_confirm_starts_one_batch_and_each_device_gets_a_quiet_logbook_line(): void
    {
        $actor = $this->actor();
        $north = $this->door(null, ['name' => 'North service door']);
        $south = $this->door(null, ['name' => 'South service door']);
        $garage = $this->door(null, ['name' => 'Garage door', 'provider' => 'provider-without-adapter']);
        $payload = $this->payload([$north, $south, $garage]);

        $first = $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/security-devices/command-batches', $payload)
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('summary.included', 2)
            ->assertJsonPath('summary.excluded', 1)
            ->assertJsonPath('summary.awaitingApproval', 2);
        $targets = collect($first->json('targets'))->keyBy('device.id');
        $this->assertFalse($targets[$garage->id]['included']);
        $this->assertStringContainsString('approved execution', (string) $targets[$garage->id]['reason']);
        $this->assertSame(CommandStatus::AwaitingApproval->value, $targets[$north->id]['status']);

        $lines = DeviceEvent::query()->whereIn('event_type', ['bulk_action_requested', 'bulk_action_left_out'])->get();
        $this->assertCount(3, $lines);
        $this->assertSame(
            [$north->id => 'bulk_action_requested', $south->id => 'bulk_action_requested', $garage->id => 'bulk_action_left_out'],
            $lines->sortBy('device_id')->mapWithKeys(fn (DeviceEvent $line): array => [(int) $line->device_id => $line->event_type])->all(),
        );
        $this->assertTrue($lines->every(fn (DeviceEvent $line): bool => $line->processed_at !== null && $line->severity === 'info'));
        $this->assertSame(DeviceCommandBatch::query()->sole()->batch_uuid, $lines->first()->payload['batch_uuid']);
        // A record, not a signal: nothing goes to Control Room, and the operator's reason stays on the batch.
        $this->assertSame(0, DeviceEventSignalOutbox::query()->whereIn('device_event_id', $lines->pluck('id'))->count());
        $this->assertStringNotContainsString('lift repair', json_encode($lines->pluck('payload')->all(), JSON_THROW_ON_ERROR));

        // A lost answer tried again returns the same batch and writes nothing twice.
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/security-devices/command-batches', $payload)
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, DeviceCommandBatch::query()->count());
        $this->assertSame(3, DeviceEvent::query()->whereIn('event_type', ['bulk_action_requested', 'bulk_action_left_out'])->count());
    }

    public function test_the_elevated_checks_still_refuse_the_bars_request(): void
    {
        $actor = $this->actor();
        $north = $this->door();
        $south = $this->door();

        // No recent identity confirmation.
        $this->actingAs($actor)
            ->postJson('/security-devices/command-batches', $this->payload([$north, $south]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_text');
        // The wrong count typed.
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/security-devices/command-batches', $this->payload([$north, $south], ['confirmation_text' => 'BULK 3 DEVICES']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation_text');
        // The combined impact not acknowledged.
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/security-devices/command-batches', $this->payload([$north, $south], ['impact_acknowledged' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('impact_acknowledged');

        $this->assertSame(0, DeviceCommandBatch::query()->count());
        $this->assertSame(0, DeviceEvent::query()->where('event_type', 'like', 'bulk_action_%')->count());
    }

    public function test_approved_children_are_sent_by_the_bars_json_dispatch(): void
    {
        Queue::fake();
        $requester = $this->actor();
        $approver = $this->actor();
        $north = $this->door();
        $south = $this->door();

        $batchId = $this->actingAs($requester)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/security-devices/command-batches', $this->payload([$north, $south]))
            ->assertCreated()
            ->json('id');
        $this->actingAs($approver)
            ->post("/security-devices/command-batches/{$batchId}/decision", [
                'decision' => 'approved',
                'comment' => 'Both doors, the Site and the contractors on site were confirmed.',
            ])
            ->assertRedirect();

        $this->actingAs($requester)
            ->postJson("/security-devices/command-batches/{$batchId}/dispatch")
            ->assertOk()
            ->assertJsonPath('processed', 2)
            ->assertJsonPath('skipped', 0);
        $this->assertSame(2, DeviceCommandRequest::query()->where('status', CommandStatus::Queued->value)->count());
    }

    public function test_confirm_its_you_comes_back_to_the_same_ticked_devices(): void
    {
        $actor = $this->actor();

        $this->actingAs($actor)
            ->get('/security-devices/command-batches/confirm-identity?'.http_build_query([
                'workspace' => 'security',
                'return' => 'devices',
                'ids' => '4,5,x,5',
                'action' => 'access.door.unlock_timed',
            ]))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHas('url.intended', '/security-devices/devices?bulk=4%2C5&bulk_action=access.door.unlock_timed');

        // An action key that isn't one is dropped; the old way back is unchanged.
        $this->actingAs($actor)
            ->get('/security-devices/command-batches/confirm-identity?workspace=security&return=devices&ids=4,5&action=%3Cscript%3E')
            ->assertSessionHas('url.intended', '/security-devices/devices?bulk=4%2C5');
        $this->actingAs($actor)
            ->get('/security-devices/command-batches/confirm-identity?workspace=security')
            ->assertSessionHas('url.intended', '/security-devices/security?tab=management');
        $this->actingAs($actor)
            ->get('/security-devices/command-batches/confirm-identity?workspace=elsewhere&return=devices')
            ->assertNotFound();
    }
}
