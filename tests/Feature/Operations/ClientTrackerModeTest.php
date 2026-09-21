<?php

namespace Tests\Feature\Operations;

use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItChange;
use App\Models\ItTicketLink;
use App\Models\Permission;
use App\Models\Queclink\QueclinkDevice;
use App\Services\Queclink\ConfigurationSnapshotService;
use App\Services\Tracking\ClientLocationAccessService;
use App\Services\Tracking\ClientLocationCommandContext;
use App\Services\Tracking\ClientTrackerModeProfiles;
use Carbon\CarbonImmutable;
use Database\Seeders\QueclinkPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ClientLocateFixture;
use Tests\TestCase;

class ClientTrackerModeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12T00:00:00Z'));
        Http::preventStrayRequests();
        Queue::fake();
        $f = ClientLocateFixture::make('queclink');
        foreach (['securityDevices.commands.control', 'it.manage', 'it.view'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Test']);
            $f['actor']->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }
        $f['actor']->unsetRelations();
        $f['device']->update(['model' => 'GL30MEU', 'last_seen_at' => now(),
            'config' => ['management' => ['capabilities' => ['configuration.apply', 'tracking.location_refresh']]]]);
        $this->seed(QueclinkPresetSeeder::class);
        app()->instance(CommandExecutionAdapterRegistry::class, new CommandExecutionAdapterRegistry([new class implements CommandExecutionAdapter
        {
            public function supports(Device $device, string $capability): bool
            {
                return $device->provider === 'queclink';
            }

            public function execute(CommandExecutionContext $context): CommandExecutionResult
            {
                throw new \RuntimeException('No hardware execution in this fixture.');
            }

            public function observe(CommandExecutionContext $context): CommandObservedState
            {
                throw new \RuntimeException('No hardware observation in this fixture.');
            }
        }]));
        $change = ItChange::factory()->standard()->create(['maintenance_starts_at' => now()->subMinute(), 'maintenance_ends_at' => now()->addMinutes(15)]);
        $change->ticket()->update(['site_id' => $f['site']->id, 'is_organisation_wide' => false, 'work_type' => 'change', 'workflow_state' => 'scheduled']);
        ItTicketLink::query()->create(['ticket_id' => $change->ticket_id, 'relationship' => 'affected_device',
            'linkable_type' => $f['device']->getMorphClass(), 'linkable_id' => $f['device']->id, 'created_by_user_id' => $f['actor']->id]);
        $f['profiles'] = app(ClientTrackerModeProfiles::class)->profiles($f['device']);
        $f['fingerprint'] = app(ClientLocationAccessService::class)->fingerprint(app(ClientLocationAccessService::class)->resolve($f['actor'], $f['client']));
        $f['url'] = '/operations/clients/'.$f['client']->id.'/location/tracker-modes';
        $f['input'] = ['access_fingerprint' => $f['fingerprint'], 'mode' => 'live', 'profile_id' => $f['profiles']['live']->id,
            'it_change_id' => $change->id, 'reason' => 'Synthetic agreed live tracking review.', 'impact_acknowledged' => true, 'idempotency_key' => (string) Str::uuid()];

        return $f;
    }

    public function test_modes_require_exact_profiles_and_preserve_approval_and_client_context(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'])->getJson($f['url'].'?access_fingerprint='.$f['fingerprint'])->assertOk()
            ->assertJsonPath('modes.1.interval_seconds', 10)->assertJsonPath('modes.2.interval_seconds', 120)
            ->assertJsonPath('observed.mode', null)->assertJsonCount(1, 'changes');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($f['url'], $f['input'])->assertOk()
            ->assertJsonPath('request.status', 'awaiting_approval')->assertJsonPath('observed.mode', null);
        $this->postJson($f['url'], $f['input'])->assertOk();
        $command = DeviceCommandRequest::query()->sole();
        $this->assertTrue(app(DeviceCommandContractVerifier::class)->verify($command));
        $this->assertSame($f['client']->id, $command->origin_context['client_id']);
        $this->assertSame(['configuration_profile_id' => $f['profiles']['live']->id], $command->encrypted_parameters);
        Queue::assertNothingPushed();
        $this->postJson($f['url'], [...$f['input'], 'mode' => 'power_saving', 'profile_id' => $f['profiles']['power_saving']->id])->assertUnprocessable();
        $this->postJson($f['url'], [...$f['input'], 'impact_acknowledged' => false])->assertUnprocessable();
        $this->postJson($f['url'], [...$f['input'], 'profile_id' => $f['profiles']['standard']->id])->assertUnprocessable();
    }

    public function test_confirmation_resumes_exact_request_and_revocation_blocks_delivery(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'])->postJson($f['url'], $f['input'])->assertOk()->assertJsonPath('request.status', 'awaiting_step_up');
        $command = DeviceCommandRequest::query()->sole();
        $this->get($f['url'].'/'.$command->command_uuid.'/confirm-identity?access_fingerprint='.$f['fingerprint'])->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson($f['url'].'/'.$command->command_uuid.'/resume', ['access_fingerprint' => $f['fingerprint']])
            ->assertOk()->assertJsonPath('request.status', 'awaiting_approval');
        DB::transaction(fn () => app(ClientLocationCommandContext::class)->lockForCommand($command->fresh()));
        $f['assignment']->update(['collection_stopped_at' => now()]);
        $this->getJson($f['url'].'?access_fingerprint='.$f['fingerprint'])->assertForbidden();
        $this->expectException(HttpException::class);
        DB::transaction(fn () => app(ClientLocationCommandContext::class)->lockForCommand($command->fresh()));
    }

    public function test_hardware_support_and_confirmed_mode_are_not_inferred_from_a_request(): void
    {
        $f = $this->fixture();
        QueclinkDevice::query()->create(['device_id' => $f['device']->id, 'imei' => $f['device']->imei ?: $f['device']->device_uid, 'status' => 'paired']);
        $snapshot = ['available' => true, 'received_at' => now()->toISOString(), 'summary' => ['global' => $f['profiles']['power_saving']->sectionPayloads()['tracking']]];
        $this->mock(ConfigurationSnapshotService::class)->shouldReceive('latestForDevice')->andReturn($snapshot);
        $this->actingAs($f['actor'])->getJson($f['url'].'?access_fingerprint='.$f['fingerprint'])->assertOk()->assertJsonPath('observed.mode', 'power_saving');
        $f['device']->update(['model' => 'Future unknown tracker']);
        $this->getJson($f['url'].'?access_fingerprint='.$f['fingerprint'])->assertOk()->assertJsonPath('modes.1.available', false)->assertJsonPath('observed.mode', null);
        $this->postJson($f['url'], $f['input'])->assertUnprocessable();
    }

    public function test_missing_change_permissions_and_server_field_injection_cannot_create_a_mode_command(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['actor'])->postJson($f['url'], [...$f['input'], 'it_change_id' => 999999])->assertUnprocessable();
        $this->postJson($f['url'], [...$f['input'], 'parameters' => ['configuration_profile_id' => 1]])->assertUnprocessable();
        $permission = Permission::query()->where('key', 'securityDevices.commands.control')->firstOrFail();
        $f['actor']->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->postJson($f['url'], $f['input'])->assertUnprocessable();
        $this->assertSame(0, DeviceCommandRequest::count());
    }
}
