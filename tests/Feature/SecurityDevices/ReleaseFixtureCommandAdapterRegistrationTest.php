<?php

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Management\Adapters\DatabaseReleaseFixtureCommandRuntime;
use App\Domain\SecurityDevices\Management\Adapters\ReleaseFixtureCommandAdapter;
use App\Domain\SecurityDevices\Management\Adapters\ReleaseFixtureCommandRuntime;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\Site;
use App\Models\User;
use App\Support\Release\ItSecurityDesktopReleaseFixtureManager;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

final class D16ForbiddenCommandHttpTransport implements CommandHttpTransport
{
    public int $calls = 0;

    public function request(
        AuthorizedProbeTarget $target,
        string $method,
        array $headers = [],
        ?array $json = null,
    ): CommandHttpResponse {
        $this->calls++;

        throw new RuntimeException('D16 release fixture must not open an HTTP provider path.');
    }
}

final class D16ForbiddenCredentialLeaseProvider implements CredentialLeaseProvider
{
    public int $calls = 0;

    public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
    {
        $this->calls++;

        throw new RuntimeException('D16 release fixture must not acquire provider credentials.');
    }
}

const RELEASE_FIXTURE_REVISION = '0123456789abcdef0123456789abcdef01234567';

/** @return array<string, mixed> */
function releaseFixtureIntegrityManifest(int $deviceId): array
{
    return [
        'schema_version' => 1,
        'records' => [['type' => 'device', 'id' => $deviceId]],
        'files' => [[
            'path' => 'it-security-release-fixtures/release-network-evidence.txt',
            'sha256' => hash('sha256', "Non-sensitive desktop release acceptance evidence.\n"),
        ]],
    ];
}

function releaseFixtureIntegrityCanonicalValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    foreach ($value as $key => $item) {
        $value[$key] = releaseFixtureIntegrityCanonicalValue($item);
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return $value;
}

/** @param array<string, mixed> $manifest */
function releaseFixtureIntegrityHash(array $manifest): string
{
    return hash('sha256', json_encode(
        releaseFixtureIntegrityCanonicalValue($manifest),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ));
}

function releaseFixtureIntegrityDevice(): Device
{
    return Device::factory()->security()->create([
        'name' => 'RELEASE Alpha Door',
        'provider' => 'release_fixture',
        'category' => 'access_control',
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
                'release_fixture' => ['no_network' => true],
            ],
        ],
    ]);
}

/** @param array<string, mixed> $manifest */
function releaseFixtureIntegrityPack(array $manifest, array $overrides = []): ItSecurityDesktopReleaseFixturePack
{
    return ItSecurityDesktopReleaseFixturePack::query()->create(array_replace([
        'pack_key' => ItSecurityDesktopReleaseFixturePack::PACK_KEY,
        'release_revision' => RELEASE_FIXTURE_REVISION,
        'state' => ItSecurityDesktopReleaseFixturePack::STATE_READY,
        'manifest' => $manifest,
        'manifest_sha256' => releaseFixtureIntegrityHash($manifest),
        'prepared_at' => now(),
        'last_verified_at' => now(),
    ], $overrides));
}

function approvedReleaseFixtureDatabaseRuntime(): DatabaseReleaseFixtureCommandRuntime
{
    config()->set('it.desktop_release_fixtures.enabled', true);
    config()->set('it.desktop_release_fixtures.environment_class', 'approved_non_production');
    config()->set('it.desktop_release_fixtures.release_revision', RELEASE_FIXTURE_REVISION);
    config()->set('monitoring.signing.active_key_id', 'release-fixture-integration');
    config()->set('monitoring.signing.keys', [
        'release-fixture-integration' => base64_encode(str_repeat('R', SODIUM_CRYPTO_AUTH_KEYBYTES)),
    ]);

    return new DatabaseReleaseFixtureCommandRuntime(app(), fn (): string => 'staging');
}

it('does not make release fixture commands available in the testing runtime even when the fixture flag is set', function () {
    config()->set('it.desktop_release_fixtures.enabled', true);
    config()->set('it.desktop_release_fixtures.environment_class', 'approved_non_production');
    $device = Device::factory()->security()->create([
        'name' => 'RELEASE Alpha Door',
        'provider' => 'release_fixture',
        'category' => 'access_control',
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
                'release_fixture' => ['no_network' => true],
            ],
        ],
    ]);

    expect(app(CommandExecutionAdapterRegistry::class)
        ->supports($device, 'access.door.unlock_timed'))->toBeFalse();
});

it('accepts only the persisted Device listed by a canonical revision-bound manifest', function () {
    $device = releaseFixtureIntegrityDevice();
    $manifest = releaseFixtureIntegrityManifest((int) $device->id);
    releaseFixtureIntegrityPack($manifest);

    expect(approvedReleaseFixtureDatabaseRuntime()->owns($device->fresh()))->toBeTrue();
});

it('rejects a fixture pack with a tampered manifest hash', function () {
    $device = releaseFixtureIntegrityDevice();
    $manifest = releaseFixtureIntegrityManifest((int) $device->id);
    releaseFixtureIntegrityPack($manifest, ['manifest_sha256' => str_repeat('0', 64)]);

    expect(approvedReleaseFixtureDatabaseRuntime()->owns($device->fresh()))->toBeFalse();
});

it('rejects a ready pack whose manifest does not list the loaded Device', function () {
    $device = releaseFixtureIntegrityDevice();
    $manifest = releaseFixtureIntegrityManifest((int) $device->id + 1);
    releaseFixtureIntegrityPack($manifest);

    expect(approvedReleaseFixtureDatabaseRuntime()->owns($device->fresh()))->toBeFalse();
});

it('rejects a stale pack revision even when its manifest and hash are canonical', function () {
    $device = releaseFixtureIntegrityDevice();
    $manifest = releaseFixtureIntegrityManifest((int) $device->id);
    releaseFixtureIntegrityPack($manifest, [
        'release_revision' => '89abcdef0123456789abcdef0123456789abcdef',
    ]);

    expect(approvedReleaseFixtureDatabaseRuntime()->owns($device->fresh()))->toBeFalse();
});

it('routes both manager-prepared D16 doors through the owned no-network central adapter only', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    Storage::fake('private');
    config()->set('it.desktop_release_fixtures.actor_password', 'release-only-password');
    config()->set('it.desktop_release_fixtures.reviewer_totp_secret', 'JBSWY3DPEHPK3PXP');
    config()->set('it.desktop_release_fixtures.enabled', true);
    config()->set('it.desktop_release_fixtures.environment_class', 'approved_non_production');
    config()->set('it.desktop_release_fixtures.release_revision', RELEASE_FIXTURE_REVISION);

    $prepared = app(ItSecurityDesktopReleaseFixtureManager::class)
        ->execute('prepare', RELEASE_FIXTURE_REVISION);
    expect($prepared['state'])->toBe('ready');

    $runtime = approvedReleaseFixtureDatabaseRuntime();
    $http = new D16ForbiddenCommandHttpTransport;
    $credentials = new D16ForbiddenCredentialLeaseProvider;
    app()->instance(ReleaseFixtureCommandRuntime::class, $runtime);
    app()->instance(CommandHttpTransport::class, $http);
    app()->instance(CredentialLeaseProvider::class, $credentials);
    app()->forgetInstance(CommandExecutionAdapterRegistry::class);

    $registry = app(CommandExecutionAdapterRegistry::class);
    $routes = app(CommandExecutionRouteResolver::class);
    $declared = app(DeclaredDeviceCommandCapabilities::class);
    $siteId = (int) Site::query()->where('name', 'RELEASE Site Alpha')->value('id');
    $doors = Device::query()
        ->whereIn('name', ['RELEASE Alpha Door', 'RELEASE Alpha Door Secondary'])
        ->orderBy('name')
        ->get();

    expect($doors)->toHaveCount(2);
    foreach ($doors as $position => $door) {
        expect($declared->supports($door, 'access.door.unlock_timed'))->toBeTrue()
            ->and($registry->for($door, 'access.door.unlock_timed'))->toBeInstanceOf(ReleaseFixtureCommandAdapter::class);
        $route = $routes->resolve($door, $siteId, 'access.door.unlock_timed');
        expect($route->available)->toBeTrue()
            ->and($route->mode)->toBe('central')
            ->and($route->adapter)->toBeInstanceOf(ReleaseFixtureCommandAdapter::class);

        $context = new CommandExecutionContext(
            commandUuid: '018f01f0-5d66-7d2f-91e2-c5e7ee6d'.str_pad((string) ($position + 1), 4, '0', STR_PAD_LEFT),
            attemptUuid: '018f01f0-5d66-7d2f-91e2-c5e7ee7d'.str_pad((string) ($position + 1), 4, '0', STR_PAD_LEFT),
            attemptNumber: 1,
            device: $door,
            siteId: $siteId,
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            expectedState: ['locked' => true],
            idempotencyKey: 'release-fixture-integration-'.$door->id,
            expiresAt: CarbonImmutable::now('UTC')->addMinute(),
        );
        expect($route->adapter->execute($context)->safeSummary['evidence_class'])
            ->toBe('simulated_release_fixture_not_provider_evidence')
            ->and($route->adapter->observe($context)->state)->toBe(['locked' => true]);
    }

    $requester = User::query()->where('email', 'release-it-manager@acceptance.invalid')->firstOrFail();
    $reviewer = User::query()->where('email', 'release-it-reviewer@acceptance.invalid')->firstOrFail();
    $command = app(DeviceCommandRequestService::class)->request(
        $doors->first(),
        $requester,
        new CommandRequestInput(
            capability: 'access.door.unlock_timed',
            parameters: ['duration_seconds' => 15],
            reason: 'Simulate the governed D16 release fixture lifecycle without contacting equipment.',
            idempotencyKey: 'release-fixture-governed-lifecycle',
            stepUpConfirmedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            impactAcknowledged: true,
        ),
    );
    expect($command->status)->toBe(CommandStatus::AwaitingApproval)
        ->and($command->signature)->not->toBeNull();
    $approved = app(DeviceCommandApprovalService::class)->decide(
        $command,
        $reviewer,
        new CommandDecisionInput(
            decision: CommandApprovalDecision::Approved,
            comment: 'Independent reviewer confirms this is the owned no-network D16 fixture only.',
        ),
    );
    expect($approved->status)->toBe(CommandStatus::Ready)
        ->and($approved->approved_by_user_id)->toBe($reviewer->id);

    $attempt = app(GovernedCommandDispatchService::class)->dispatch($approved, $requester);
    $reconciliation = app(DeviceCommandReconciliationService::class)
        ->reconcile($approved->fresh(), $requester);
    $persisted = DeviceCommandRequest::query()->findOrFail($command->id);
    $executionAudit = $persisted->auditEvents()->where('action', 'execution_succeeded')->sole();

    expect($attempt->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($attempt->runtime)->toBe('central')
        ->and($attempt->provider_request_reference)->toStartWith('release-fixture-no-network:')
        ->and($attempt->safe_result_summary['evidence_class'])->toBe('simulated_release_fixture_not_provider_evidence')
        ->and($reconciliation->outcome)->toBe(CommandReconciliationOutcome::Matched)
        ->and($reconciliation->observation_reference)->toStartWith('release-fixture-no-network:door-state:')
        ->and($reconciliation->safe_evidence_summary)->toContain('not provider or equipment evidence')
        ->and($persisted->status)->toBe(CommandStatus::Reconciled)
        ->and($persisted->auditEvents()->where('action', 'requested')->count())->toBe(1)
        ->and($persisted->auditEvents()->where('action', 'approved')->count())->toBe(1)
        ->and($persisted->auditEvents()->where('action', 'execution_succeeded')->count())->toBe(1)
        ->and(data_get($executionAudit->safe_context, 'safe_result.evidence_class'))
        ->toBe('simulated_release_fixture_not_provider_evidence')
        ->and($persisted->auditEvents()->where('action', 'reconciliation_matched')->count())->toBe(1);

    expect($http->calls)->toBe(0)
        ->and($credentials->calls)->toBe(0);
});
