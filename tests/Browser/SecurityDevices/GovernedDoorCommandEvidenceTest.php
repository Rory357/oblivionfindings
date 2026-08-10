<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

final class GovernedDoorEvidenceAdapter implements CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool
    {
        return $capability === 'access.door.unlock_timed'
            && $device->provider === 'unifi'
            && $device->category === 'access_control';
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: [
                'provider_state' => 'accepted',
                'previous_lock_state' => 'locked',
                'unlock_duration_seconds' => $context->parameters['duration_seconds'],
            ],
            providerRequestReference: 'browser-evidence:'.$context->commandUuid,
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'browser-evidence:door-state:'.hash('sha256', $context->attemptUuid),
            safeEvidenceSummary: 'UniFi Access freshly confirmed that the door relay returned to locked.',
        );
    }
}

function scrollGovernedDoorEvidenceIntoView(Browser $browser, string $selector): void
{
    $encodedSelector = json_encode($selector, JSON_THROW_ON_ERROR);
    $browser->script(<<<JS
        const element = document.querySelector({$encodedSelector});
        if (!element) {
            throw new Error('Governed door evidence element not found.');
        }
        element.scrollIntoView({ block: 'center' });
    JS);
}

/** @return array{width: int, height: int, suffix: string, requester: User, reviewer: User, site: Site, device: Device} */
function governedDoorEvidenceFixture(string $suffix, int $width, int $height, string $password): array
{
    $run = Str::lower((string) Str::uuid());
    $role = Role::query()->where('name', 'admin')->firstOrFail();
    $requester = User::factory()->create([
        'name' => 'E07 '.Str::headline($suffix).' Requester',
        'email' => "e07.requester.{$suffix}.{$run}@example.test",
        'password' => Hash::make($password),
        'approved_at' => now(),
    ]);
    $reviewer = User::factory()->create([
        'name' => 'E07 '.Str::headline($suffix).' Reviewer',
        'email' => "e07.reviewer.{$suffix}.{$run}@example.test",
        'password' => Hash::make($password),
        'approved_at' => now(),
    ]);
    $requester->roles()->attach($role);
    $reviewer->roles()->attach($role);

    $site = Site::factory()->create([
        'name' => 'E07 '.Str::headline($suffix).' Harbour Site',
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
    $doorId = (string) Str::uuid();
    $device = Device::factory()->security()->create([
        'name' => 'E07 '.Str::headline($suffix).' Harbour service door',
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'unifi',
        'last_seen_at' => now(),
        'external_ref' => [
            'provider' => 'unifi',
            'provider_resource_kind' => 'door',
            'provider_door_id' => $doorId,
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
    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => null,
        'cidrs' => ['10.77.0.0/16'],
        'protocols' => ['provider'],
        'port_bounds' => ['provider' => [12445]],
        'exclusions' => [],
        'status' => 'active',
    ]);
    IntegrationSiteSecret::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'access_api',
        'base_url' => 'https://10.77.4.5:12445',
        'secret_encrypted' => 'LEGACY-SECRET-MUST-NOT-BE-READ',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ]);
    CredentialReference::query()->create([
        'reference_key' => "vault:browser-evidence/{$site->id}/unifi-access",
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:access.door.unlock_timed'],
        'secret_manager_reference' => "secret/data/browser-evidence/{$site->id}/unifi-access",
        'secret_manager_reference_hash' => hash('sha256', "browser-evidence-unifi-access-{$site->id}"),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    return compact('width', 'height', 'suffix', 'requester', 'reviewer', 'site', 'device');
}

/** @return array{width: int, height: int, requester: User, site: Site, device: Device, collector: MonitoringCollector, command: DeviceCommandRequest} */
function collectorRouteEvidenceFixture(string $suffix, int $width, int $height): array
{
    $run = Str::lower((string) Str::uuid());
    $role = Role::query()->where('name', 'admin')->firstOrFail();
    $requester = User::factory()->create([
        'name' => 'Remote '.Str::headline($suffix).' Requester',
        'email' => "remote.requester.{$suffix}.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $reviewer = User::factory()->create([
        'name' => 'Remote '.Str::headline($suffix).' Reviewer',
        'email' => "remote.reviewer.{$suffix}.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $requester->roles()->attach($role);
    $reviewer->roles()->attach($role);

    $site = Site::factory()->create([
        'name' => 'Remote '.Str::headline($suffix).' Site',
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

    $collectorPublicKey = hash('sha256', 'remote-browser-collector-public-'.$run, true);
    $collector = MonitoringCollector::factory()->create([
        'site_id' => $site->id,
        'collector_uuid' => (string) Str::uuid(),
        'public_key' => base64_encode($collectorPublicKey),
        'public_key_fingerprint' => hash('sha256', $collectorPublicKey),
        'client_certificate_fingerprint' => hash('sha256', 'remote-browser-collector-'.$run),
        'status' => 'online',
        'last_seen_at' => now(),
    ]);
    $doorId = (string) Str::uuid();
    $device = Device::factory()->security()->create([
        'name' => 'Remote '.Str::headline($suffix).' service door',
        'category' => 'access_control',
        'subcategory' => 'door_controller',
        'provider' => 'unifi',
        'last_seen_at' => now(),
        'external_ref' => [
            'provider' => 'unifi',
            'provider_resource_kind' => 'door',
            'provider_door_id' => $doorId,
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
    $profile = MonitoringProfile::factory()->create([
        'stale_after_seconds' => 300,
        'interval_seconds' => 60,
        'is_active' => true,
    ]);
    Monitor::factory()->create([
        'device_id' => $device->id,
        'profile_id' => $profile->id,
        'collector_id' => $collector->id,
        'kind' => MonitorKind::Icmp,
        'target' => '10.77.4.5',
        'current_state' => MonitorState::Healthy,
        'last_observation_at' => now(),
        'is_enabled' => true,
    ]);
    DiscoveryScope::factory()->create([
        'site_id' => $site->id,
        'collector_id' => $collector->id,
        'cidrs' => ['10.77.0.0/16'],
        'seed_hosts' => [],
        'protocols' => ['provider'],
        'port_bounds' => ['provider' => [12445]],
        'exclusions' => [],
        'status' => 'active',
    ]);
    IntegrationSiteSecret::query()->create([
        'site_id' => $site->id,
        'provider' => 'unifi',
        'capability' => 'access_api',
        'base_url' => 'https://10.77.4.5:12445',
        'secret_encrypted' => 'REMOTE-BROWSER-LEGACY-SECRET-MUST-NOT-BE-READ',
        'is_enabled' => true,
        'last_tested_at' => now(),
        'last_error' => null,
    ]);
    CredentialReference::query()->create([
        'reference_key' => "vault:remote-browser/{$site->id}/unifi-access",
        'site_id' => $site->id,
        'provider' => 'unifi',
        'purpose' => 'device_management',
        'capabilities' => ['command:access.door.unlock_timed'],
        'secret_manager_reference' => "secret/data/remote-browser/{$site->id}/unifi-access",
        'secret_manager_reference_hash' => hash('sha256', 'remote-browser-unifi-'.$site->id),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    $command = app(DeviceCommandRequestService::class)->request(
        $device,
        $requester,
        new CommandRequestInput(
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            reason: 'Permit the verified engineer to use the remote Site entrance.',
            idempotencyKey: 'remote-browser-'.$device->id,
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            impactAcknowledged: true,
        ),
    );
    $command = app(DeviceCommandApprovalService::class)->decide(
        $command,
        $reviewer,
        new CommandDecisionInput(
            decision: CommandApprovalDecision::Approved,
            comment: 'Remote Site, Device, engineer, and bounded window independently verified.',
        ),
    );
    app(GovernedCommandDispatchService::class)->dispatch($command, $requester);

    return compact('width', 'height', 'requester', 'site', 'device', 'collector', 'command');
}

test('a remote-only Device explains collector execution and audit recovery on desktop', function () {
    $signingKeyId = 'dusk-remote-collector-command';
    config()->set('monitoring.signing.active_key_id', $signingKeyId);
    config()->set('monitoring.signing.keys', [
        $signingKeyId => base64_encode(str_repeat('R', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
    $cases = [
        collectorRouteEvidenceFixture('wide', 1440, 900),
        collectorRouteEvidenceFixture('compact', 1280, 800),
    ];

    $this->browse(function (Browser $browser) use ($cases): void {
        foreach ($cases as $case) {
            /** @var DeviceCommandRequest $command */
            $command = $case['command'];
            /** @var Device $device */
            $device = $case['device'];
            $managementUrl = "/security-devices/devices/{$device->id}?section=management";

            $browser->driver->manage()->deleteAllCookies();
            $browser->resize($case['width'], $case['height'])
                ->loginAs($case['requester'])
                ->visit($managementUrl)
                ->waitForText('Remote Site collector', 40)
                ->assertSee($device->name)
                ->assertSee($case['site']->name)
                ->assertSee('This remote-only Device will use its current Site-scoped collector and encrypted ordered result path.')
                ->assertSee('Execution route')
                ->assertSee('Execution attempt 1 · dispatching')
                ->assertSee('collector runtime')
                ->assertSee('Export audit evidence')
                ->assertScript(
                    'document.querySelector(`a[href="/security-devices/devices/'.$device->id.'/commands/'.$command->id.'/evidence"]`) !== null',
                    true,
                )
                ->assertDontSee('REMOTE-BROWSER-LEGACY-SECRET-MUST-NOT-BE-READ')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->logout();

            $this->assertSame('collector', $command->fresh()->execution_route);
            $this->assertSame($case['collector']->id, $command->fresh()->collector_id);
            $this->assertSame(CommandStatus::Dispatching, $command->fresh()->status);
            $this->assertSame(CommandAttemptStatus::Dispatching, $command->attempts()->sole()->status);
            $this->assertNull($command->attempts()->sole()->provider_request_reference);
        }

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});

test('a governed UniFi door command is understandable and fully evidenced on desktop', function () {
    $password = 'DuskOnly-E07-StepUp!';
    $signingKeyId = 'dusk-governed-door-command';
    $signingKey = base64_encode(str_repeat('D', SODIUM_CRYPTO_AUTH_KEYBYTES));
    config()->set('monitoring.signing.active_key_id', $signingKeyId);
    config()->set('monitoring.signing.keys', [$signingKeyId => $signingKey]);

    $cases = [
        governedDoorEvidenceFixture('wide', 1440, 900, $password),
        governedDoorEvidenceFixture('compact', 1280, 800, $password),
    ];
    $reason = 'Let the identity-checked maintenance technician through the service entrance.';
    $decisionComment = 'Technician identity and approved attendance window independently confirmed.';

    $this->browse(function (Browser $browser) use ($cases, $password, $reason, $decisionComment): void {
        foreach ($cases as $case) {
            /** @var User $requester */
            $requester = $case['requester'];
            /** @var User $reviewer */
            $reviewer = $case['reviewer'];
            /** @var Site $site */
            $site = $case['site'];
            /** @var Device $device */
            $device = $case['device'];
            $managementUrl = "/security-devices/devices/{$device->id}?section=management";

            $browser->driver->manage()->deleteAllCookies();
            $browser->resize($case['width'], $case['height'])
                ->loginAs($requester)
                ->visit($managementUrl)
                ->waitForText('Governed actions', 40)
                ->assertSee($device->name)
                ->assertSee($site->name)
                ->assertSee('Unifi')
                ->assertSee('Identity check required')
                ->assertSee('Independent approval')
                ->assertSee('Fresh-state reconciliation')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->clickLink('Confirm identity first')
                ->waitForText('Confirm your password', 20)
                ->type('#password', $password)
                ->press('Confirm password')
                ->waitForText('Governed actions', 40)
                ->assertScript(
                    'new URL(window.location.href).searchParams.get("section")',
                    'management',
                )
                ->assertSee('Requestable')
                ->press('Request action')
                ->waitForText('Unlock door temporarily', 20)
                ->assertSee($device->name)
                ->assertSee($site->name)
                ->assertSee('Current state')
                ->assertSee('Identity confirmed')
                ->assertSee('Independent approval')
                ->assertSee('Fresh-state reconciliation')
                ->assertSee('WHAT COULD HAPPEN')
                ->assertSee('Expected safe result')
                ->assertSee('Oblivion central runtime')
                ->type('#command-duration_seconds', '15')
                ->type('#command-reason', $reason)
                ->check('#command-impact-acknowledged');
            scrollGovernedDoorEvidenceIntoView($browser, '#command-create-request');
            $browser->click('#command-create-request')
                ->waitUntilMissing('#command-reason', 20)
                ->waitForText('awaiting approval', 20)
                ->assertSee($reason)
                ->assertScript(
                    'Array.from(document.querySelectorAll("button")).filter((button) => button.textContent.trim() === "Review").length',
                    0,
                );

            $command = DeviceCommandRequest::query()
                ->where('device_id', $device->id)
                ->sole();
            $this->assertSame(CommandStatus::AwaitingApproval, $command->status);
            $this->assertNotNull($command->signature);
            $this->assertNotNull($command->step_up_confirmed_at);
            $this->assertSame(1, $command->auditEvents()->where('action', 'requested')->count());

            $browser->loginAs($reviewer)
                ->visit($managementUrl)
                ->waitForText('awaiting approval', 40)
                ->press('Review')
                ->waitForText('Review command request', 20)
                ->assertSee($device->name)
                ->assertSee($site->name)
                ->assertSee($requester->name)
                ->assertSee($reason)
                ->assertSee('locked: true')
                ->type('#command-decision-comment', $decisionComment)
                ->press('Record decision')
                ->waitUntilMissing('#command-decision-comment', 20)
                ->waitForText('ready', 20)
                ->assertSee('Approved by '.$reviewer->name);

            $command->refresh();
            $this->assertSame(CommandStatus::Ready, $command->status);
            $this->assertSame($reviewer->id, $command->approved_by_user_id);
            $this->assertNotSame($requester->id, $reviewer->id);

            $browser->loginAs($requester)
                ->visit($managementUrl)
                ->waitForText('Add to execution queue', 40)
                ->press('Add to execution queue')
                ->waitForText('queued', 20);

            $command->refresh();
            $this->assertSame(CommandStatus::Queued, $command->status);

            app()->instance(
                CommandExecutionAdapterRegistry::class,
                new CommandExecutionAdapterRegistry([new GovernedDoorEvidenceAdapter]),
            );
            $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);
            $reconciliation = app(DeviceCommandReconciliationService::class)
                ->reconcile($command->fresh(), $requester);

            $this->assertSame(CommandAttemptStatus::Succeeded, $attempt->status);
            $this->assertSame(CommandReconciliationOutcome::Matched, $reconciliation->outcome);
            $this->assertSame(CommandStatus::Reconciled, $command->fresh()->status);
            $this->assertSame(1, $command->attempts()->count());
            $this->assertSame(1, $command->reconciliations()->count());
            $this->assertSame(1, $command->auditEvents()->where('action', 'reconciliation_matched')->count());

            $event = DeviceEvent::query()
                ->where('device_id', $device->id)
                ->where('event_type', 'management_command_reconciled')
                ->sole();
            $this->assertSame('oblivion_command_plane', $event->source);
            $this->assertSame('matched', $event->payload['outcome']);
            $this->assertArrayNotHasKey('reason', $event->payload);

            $browser->refresh()
                ->waitForText('reconciled', 40)
                ->assertSee('Execution attempt 1 · succeeded')
                ->assertSee('Fresh-state verification · matched')
                ->assertSee('UniFi Access freshly confirmed that the door relay returned to locked.')
                ->assertSee('No further action is required; the fresh observed state matched the expected result.')
                ->assertDontSee('LEGACY-SECRET-MUST-NOT-BE-READ')
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

test('governed break glass is short lived notified and post-use reviewed on desktop', function () {
    $password = 'DuskOnly-M04-StepUp!';
    $signingKeyId = 'dusk-governed-door-command';
    config()->set('monitoring.signing.active_key_id', $signingKeyId);
    config()->set('monitoring.signing.keys', [
        $signingKeyId => base64_encode(str_repeat('D', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);

    $cases = [
        governedDoorEvidenceFixture('break-glass-wide', 1440, 900, $password),
        governedDoorEvidenceFixture('break-glass-compact', 1280, 800, $password),
    ];
    $reason = 'Permit the verified emergency technician through the service entrance.';
    $emergencyReason = 'A person is trapped in the secured area and immediate emergency access is required.';
    $reviewSummary = 'The emergency declaration and bounded unlock were appropriate; no further action is required.';

    $this->browse(function (Browser $browser) use ($cases, $password, $reason, $emergencyReason, $reviewSummary): void {
        foreach ($cases as $case) {
            /** @var User $requester */
            $requester = $case['requester'];
            /** @var User $reviewer */
            $reviewer = $case['reviewer'];
            /** @var Site $site */
            $site = $case['site'];
            /** @var Device $device */
            $device = $case['device'];
            $managementUrl = "/security-devices/devices/{$device->id}?section=management";

            $browser->driver->manage()->deleteAllCookies();
            $browser->resize($case['width'], $case['height'])
                ->loginAs($requester)
                ->visit($managementUrl)
                ->waitForText('Governed actions', 40)
                ->clickLink('Confirm identity first')
                ->waitForText('Confirm your password', 20)
                ->type('#password', $password)
                ->press('Confirm password')
                ->waitForText('Governed actions', 40)
                ->press('Request action')
                ->waitForText('Declare emergency break glass', 20)
                ->click('#command-break-glass')
                ->waitForText('Designated post-use reviewer', 20)
                ->select('#command-break-glass-reviewer', (string) $reviewer->id)
                ->type('#command-duration_seconds', '15')
                ->type('#command-reason', $reason)
                ->type('#command-break-glass-reason', $emergencyReason)
                ->check('#command-impact-acknowledged');
            scrollGovernedDoorEvidenceIntoView($browser, '#command-create-request');
            $browser->click('#command-create-request')
                ->waitUntilMissing('#command-break-glass-reason', 20)
                ->waitForText('Break glass', 20)
                ->assertSee('Post-use review required')
                ->assertSee('Designated reviewer: '.$reviewer->name)
                ->assertSee($emergencyReason)
                ->assertSee('Add to execution queue')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->press('Add to execution queue')
                ->waitForText('queued', 20);

            $command = DeviceCommandRequest::query()
                ->where('device_id', $device->id)
                ->sole();
            $this->assertTrue($command->is_break_glass);
            $this->assertSame(CommandStatus::Queued, $command->status);
            $this->assertSame($reviewer->id, $command->break_glass_reviewer_user_id);
            $this->assertNotNull($command->break_glass_notification_sent_at);
            $this->assertLessThanOrEqual(120, $command->expires_at->diffInSeconds($command->break_glass_declared_at));
            $this->assertSame(1, $reviewer->notifications()->count());
            $this->assertStringNotContainsString(
                $emergencyReason,
                json_encode($reviewer->notifications()->sole()->data, JSON_THROW_ON_ERROR),
            );

            app()->instance(
                CommandExecutionAdapterRegistry::class,
                new CommandExecutionAdapterRegistry([new GovernedDoorEvidenceAdapter]),
            );
            $attempt = app(CommandDispatchPort::class)->dispatch($command, $requester);
            $reconciliation = app(DeviceCommandReconciliationService::class)
                ->reconcile($command->fresh(), $requester);
            $this->assertSame(CommandAttemptStatus::Succeeded, $attempt->status);
            $this->assertSame(CommandReconciliationOutcome::Matched, $reconciliation->outcome);

            $browser->loginAs($reviewer)
                ->visit($managementUrl)
                ->waitForText('Complete post-use review', 40)
                ->assertSee($device->name)
                ->assertSee($site->name)
                ->assertSee($emergencyReason)
                ->press('Complete post-use review')
                ->waitForText('Post-use break-glass review', 20)
                ->assertSee($requester->name)
                ->assertSee('Execution outcome')
                ->assertSee('Fresh-state outcome')
                ->select('#command-break-glass-review-outcome', 'confirmed_appropriate')
                ->type('#command-break-glass-review-summary', $reviewSummary)
                ->press('Record permanent review')
                ->waitUntilMissing('#command-break-glass-review-summary', 20)
                ->waitForText('Post-use review completed', 20)
                ->assertSee('confirmed appropriate by '.$reviewer->name)
                ->assertSee($reviewSummary)
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $reviewed = $command->fresh();
            $this->assertSame('confirmed_appropriate', $reviewed->break_glass_review_outcome->value);
            $this->assertSame($reviewer->id, $reviewed->break_glass_reviewed_by_user_id);
            $this->assertSame(1, $reviewed->auditEvents()->where('action', 'break_glass_post_use_reviewed')->count());
            $browser->logout();
        }

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});

test('a changed observation closes the approved command safely without execution on desktop', function () {
    $password = 'DuskOnly-M02-Blocked!';
    $signingKeyId = 'dusk-governed-door-command';
    config()->set('monitoring.signing.active_key_id', $signingKeyId);
    config()->set('monitoring.signing.keys', [
        $signingKeyId => base64_encode(str_repeat('D', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);
    $cases = [
        governedDoorEvidenceFixture('blocked-wide', 1440, 900, $password),
        governedDoorEvidenceFixture('blocked-compact', 1280, 800, $password),
    ];

    foreach ($cases as &$case) {
        $command = app(DeviceCommandRequestService::class)->request(
            $case['device'],
            $case['requester'],
            new CommandRequestInput(
                capability: 'access.door.unlock_timed',
                parameters: ['duration_seconds' => 15],
                reason: 'Permit the identity-checked technician during the approved attendance window.',
                idempotencyKey: 'dusk-blocked-'.$case['device']->id,
                stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
                impactAcknowledged: true,
            ),
        );
        app(DeviceCommandApprovalService::class)->decide(
            $command,
            $case['reviewer'],
            new CommandDecisionInput(
                decision: CommandApprovalDecision::Approved,
                comment: 'Identity, Device, Site and attendance window independently confirmed.',
            ),
        );
        $case['device']->update(['last_seen_at' => now()->subHour()]);
        $case['command'] = $command->fresh();
    }
    unset($case);

    $this->browse(function (Browser $browser) use ($cases): void {
        foreach ($cases as $case) {
            /** @var DeviceCommandRequest $command */
            $command = $case['command'];
            /** @var Device $device */
            $device = $case['device'];
            $managementUrl = "/security-devices/devices/{$device->id}?section=management";

            $browser->driver->manage()->deleteAllCookies();
            $browser->resize($case['width'], $case['height'])
                ->loginAs($case['requester'])
                ->visit($managementUrl)
                ->waitForText('Recheck request safely', 40)
                ->assertSee('stale')
                ->assertSee('Approved conditions changed')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->press('Recheck request safely')
                ->waitForText('This request cannot execute or be resumed', 40)
                ->assertSee('blocked without execution')
                ->assertSee('observation stale')
                ->assertSee('This request cannot execute or be resumed')
                ->assertDontSee('Add to execution queue')
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                )
                ->logout();

            $blocked = $command->fresh();
            $this->assertSame(CommandStatus::Blocked, $blocked->status);
            $this->assertSame('observation_stale', $blocked->blocked_reason_code);
            $this->assertNotNull($blocked->blocked_at);
            $this->assertSame(0, $blocked->attempts()->count());
            $this->assertSame(1, $blocked->auditEvents()->where('action', 'dispatch_blocked')->count());
        }

        $severeLogs = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
            ->values()
            ->all();

        $this->assertSame([], $severeLogs, json_encode($severeLogs));
    });
});
