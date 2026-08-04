<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

final class BulkManagementEvidenceAdapter implements CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool
    {
        return $capability === 'access.door.unlock_timed'
            && $device->provider === 'unifi'
            && $device->category === 'access_control';
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        if (str_contains($context->device->name, 'Partial failure')) {
            return new CommandExecutionResult(
                status: CommandAttemptStatus::Failed,
                providerRequestReference: 'bulk-browser:failed:'.$context->commandUuid,
                safeFailureReason: 'The provider rejected this Device while the other child remained independently successful.',
            );
        }

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: [
                'provider_state' => 'accepted',
                'unlock_duration_seconds' => $context->parameters['duration_seconds'],
            ],
            providerRequestReference: 'bulk-browser:accepted:'.$context->commandUuid,
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'bulk-browser:locked:'.hash('sha256', $context->attemptUuid),
            safeEvidenceSummary: 'Fresh provider state confirmed that this door returned to locked.',
        );
    }
}

/**
 * @return array{
 *   width: int,
 *   height: int,
 *   suffix: string,
 *   password: string,
 *   requester: User,
 *   reviewer: User,
 *   site: Site,
 *   successful: Device,
 *   failed: Device,
 *   excluded: Device
 * }
 */
function bulkManagementEvidenceFixture(string $suffix, int $width, int $height): array
{
    $run = Str::lower((string) Str::uuid());
    $password = 'DuskOnly-Bulk-Management!';
    $role = Role::query()->where('name', 'admin')->firstOrFail();
    $requester = User::factory()->create([
        'name' => 'Bulk '.Str::headline($suffix).' Requester',
        'email' => "bulk.requester.{$suffix}.{$run}@example.test",
        'password' => Hash::make($password),
        'approved_at' => now(),
    ]);
    $reviewer = User::factory()->create([
        'name' => 'Bulk '.Str::headline($suffix).' Reviewer',
        'email' => "bulk.reviewer.{$suffix}.{$run}@example.test",
        'password' => Hash::make($password),
        'approved_at' => now(),
    ]);
    $requester->roles()->attach($role);
    $reviewer->roles()->attach($role);

    $site = Site::factory()->create([
        'name' => 'Bulk '.Str::headline($suffix).' Harbour Site',
        'is_active' => true,
        'archived' => false,
    ]);
    foreach ([$requester, $reviewer] as $actor) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
    }

    $makeDoor = function (string $name, CarbonImmutable $lastSeenAt) use ($site): Device {
        $device = Device::factory()->security()->create([
            'name' => $name,
            'category' => 'access_control',
            'subcategory' => 'door_controller',
            'provider' => 'unifi',
            'last_seen_at' => $lastSeenAt,
            'external_ref' => [
                'provider' => 'unifi',
                'provider_resource_kind' => 'door',
                'provider_door_id' => (string) Str::uuid(),
            ],
            'config' => [
                'management' => [
                    'capabilities' => ['access.door.unlock_timed'],
                    'unifi_access' => ['unlock_duration_seconds' => 15],
                ],
            ],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subMinute(),
        ]);

        return $device;
    };

    $successful = $makeDoor(
        'Bulk '.Str::headline($suffix).' Successful door',
        CarbonImmutable::now('UTC'),
    );
    $failed = $makeDoor(
        'Bulk '.Str::headline($suffix).' Partial failure door',
        CarbonImmutable::now('UTC'),
    );
    $excluded = $makeDoor(
        'Bulk '.Str::headline($suffix).' Stale excluded door',
        CarbonImmutable::now('UTC')->subDay(),
    );

    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => ['10.78.0.0/16'],
        'protocols' => ['provider'],
        'port_bounds' => ['provider' => [12445]],
        'exclusions' => [],
        'status' => 'active',
    ]);
    IntegrationSiteSecret::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'access_api',
        'base_url' => 'https://10.78.4.5:12445',
        'secret_encrypted' => 'BULK-BROWSER-LEGACY-SECRET-MUST-NOT-APPEAR',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ]);
    CredentialReference::query()->create([
        'reference_key' => "vault:bulk-browser/{$site->id}/unifi-access",
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:access.door.unlock_timed'],
        'secret_manager_reference' => "secret/data/bulk-browser/{$site->id}/unifi-access",
        'secret_manager_reference_hash' => hash('sha256', "bulk-browser-unifi-access-{$site->id}"),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    return compact(
        'width',
        'height',
        'suffix',
        'password',
        'requester',
        'reviewer',
        'site',
        'successful',
        'failed',
        'excluded',
    );
}

function selectBulkManagementEvidenceTargets(Browser $browser, Device $excluded): void
{
    $browser->press('Select all ready')
        ->check('input[aria-label="Select '.$excluded->name.'"]')
        ->assertSee('3 selected')
        ->assertSee('2 ready')
        ->assertSee('1 exclusions')
        ->press('Review selected targets')
        ->waitForText('Review governed bulk action', 20)
        ->assertSee('Explicit exclusions')
        ->assertSee($excluded->name)
        ->assertSee('A fresh Device observation is required.')
        ->assertSee('Partial-result rule');
}

test('governed bulk Device management is understandable and independently reconciled on desktop', function () {
    config()->set('monitoring.signing.active_key_id', 'dusk-governed-door-command');
    config()->set('monitoring.signing.keys', [
        'dusk-governed-door-command' => base64_encode(str_repeat('D', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);

    $cases = [
        bulkManagementEvidenceFixture('wide', 1440, 900),
        bulkManagementEvidenceFixture('compact', 1280, 800),
    ];
    $otherWorkspaceUrls = [
        '/security-devices/network-it?tab=management',
        '/security-devices/tracking?tab=management',
        '/security-devices/healthcare?tab=management',
        '/security-devices/facilities-iot?tab=management',
    ];

    $this->browse(function (Browser $browser) use ($cases, $otherWorkspaceUrls): void {
        foreach ($cases as $case) {
            /** @var User $requester */
            $requester = $case['requester'];
            /** @var User $reviewer */
            $reviewer = $case['reviewer'];
            /** @var Device $excluded */
            $excluded = $case['excluded'];

            $browser->driver->manage()->deleteAllCookies();
            $browser->resize($case['width'], $case['height'])
                ->loginAs($requester);

            if ($case['suffix'] === 'wide') {
                foreach ($otherWorkspaceUrls as $workspaceUrl) {
                    $browser->visit($workspaceUrl)
                        ->waitForText('Governed Device management', 40)
                        ->assertSee('Management')
                        ->assertScript(
                            'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                            true,
                        );
                }
            }

            $browser->visit('/security-devices/security?tab=management')
                ->waitForText('Unlock door temporarily', 40)
                ->assertSee('Choose targets')
                ->assertSee('Unavailable selected targets remain explicit exclusions.');
            selectBulkManagementEvidenceTargets($browser, $excluded);
            $browser->clickLink('Confirm identity')
                ->waitForText('Confirm your password', 20)
                ->type('#password', $case['password'])
                ->press('Confirm password')
                ->waitForText('Governed Device management', 40);

            selectBulkManagementEvidenceTargets($browser, $excluded);
            $reason = 'Apply the approved bounded access window and verify every child result independently.';
            $browser->type('#bulk-parameter-duration_seconds', '15')
                ->type('#bulk-command-reason', $reason)
                ->check('#bulk-command-impact-acknowledged')
                ->type('#bulk-command-confirmation', 'BULK 3 DEVICES')
                ->press('Create 2 child requests')
                ->waitForText('Per-Device results', 40)
                ->assertSee('Download result ledger')
                ->assertSee('Independent child lifecycle')
                ->assertSee('Each included Device has an independent signed request, approval, execution attempt, and reconciliation result.')
                ->assertSee($excluded->name)
                ->assertSee('A fresh Device observation is required.')
                ->assertDontSee('BULK-BROWSER-LEGACY-SECRET-MUST-NOT-APPEAR')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $batch = DeviceCommandBatch::query()
                ->where('requested_by_user_id', $requester->id)
                ->latest('id')
                ->firstOrFail();
            $this->assertSame(3, $batch->target_count);
            $this->assertSame(2, $batch->included_count);
            $this->assertSame(1, $batch->excluded_count);
            $this->assertSame(2, $batch->targets()->whereNotNull('device_command_request_id')->count());
            $this->assertSame(1, $batch->targets()->where('inclusion_status', 'excluded')->count());
            $browser->assertPresent("a[href='/security-devices/command-batches/{$batch->id}/export']");

            $batchUrl = "/security-devices/command-batches/{$batch->id}";
            $browser->loginAs($reviewer)
                ->visit($batchUrl)
                ->waitForText('Review 2 requests', 40)
                ->press('Review 2 requests')
                ->waitForText('Review child command requests', 20)
                ->type('#batch-decision-comment', 'Verified the exact Site, exclusions, impact and expected state for both included Devices.')
                ->press('Record decision')
                ->waitForText('Queue 2 ready requests', 40);

            $browser->loginAs($requester)
                ->visit($batchUrl)
                ->waitForText('Queue 2 ready requests', 40)
                ->press('Queue 2 ready requests')
                ->waitForText('Queue ready child requests?', 20)
                ->press('Queue ready requests')
                ->waitForText('Queued', 40);

            $commands = $batch->targets()
                ->whereNotNull('device_command_request_id')
                ->with('command.device')
                ->get()
                ->pluck('command')
                ->filter()
                ->values();
            $this->assertCount(2, $commands);
            $this->assertTrue($commands->every(
                fn ($command): bool => $command->fresh()->status === CommandStatus::Queued,
            ));

            app()->instance(
                CommandExecutionAdapterRegistry::class,
                new CommandExecutionAdapterRegistry([new BulkManagementEvidenceAdapter]),
            );
            foreach ($commands as $command) {
                $attempt = app(CommandDispatchPort::class)->dispatch($command->fresh(), $requester);
                if ($attempt->status === CommandAttemptStatus::Succeeded) {
                    app(DeviceCommandReconciliationService::class)
                        ->reconcile($command->fresh(), $requester);
                }
            }

            $statuses = $commands->map(fn ($command): CommandStatus => $command->fresh()->status);
            $this->assertTrue($statuses->contains(CommandStatus::Reconciled));
            $this->assertTrue($statuses->contains(CommandStatus::Failed));

            $browser->refresh()
                ->waitForText('partially completed', 40)
                ->assertSee('Fresh provider state confirmed that this door returned to locked.')
                ->assertSee('The provider rejected this Device while the other child remained independently successful.')
                ->assertSee('Resolve the exclusion before creating a new request.')
                ->assertSee('The expected Device state was freshly verified.')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->clickLink('Management workspace')
                ->waitForText('Recent governed activity', 40)
                ->assertSee('partially completed')
                ->assertSee('2 included')
                ->assertSee('1 excluded')
                ->assertSee('1 reconciled')
                ->assertSee('1 failed or blocked')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->logout();
        }

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});
