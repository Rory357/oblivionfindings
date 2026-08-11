<?php

use App\Domain\SecurityDevices\Management\Adapters\ReleaseFixtureCommandAdapter;
use App\Domain\SecurityDevices\Management\Adapters\ReleaseFixtureCommandRuntime;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;

final class ReleaseFixtureRuntimeStub implements ReleaseFixtureCommandRuntime
{
    public int $ownershipChecks = 0;

    public function __construct(
        private readonly bool $approved,
        private readonly bool $owned,
    ) {}

    public function isApprovedStagingFixtureRuntime(): bool
    {
        return $this->approved;
    }

    public function owns(Device $device): bool
    {
        $this->ownershipChecks++;

        return $this->owned;
    }
}

function releaseFixtureCommandDevice(array $overrides = []): Device
{
    return new Device(array_replace_recursive([
        'id' => 7001,
        'name' => 'RELEASE Alpha Door',
        'provider' => 'release_fixture',
        'domain' => 'security',
        'category' => 'access_control',
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
                'release_fixture' => ['no_network' => true],
            ],
        ],
    ], $overrides));
}

function releaseFixtureCommandContext(Device $device): CommandExecutionContext
{
    return new CommandExecutionContext(
        commandUuid: '018f01f0-5d66-7d2f-91e2-c5e7ee6d0001',
        attemptUuid: '018f01f0-5d66-7d2f-91e2-c5e7ee6d0002',
        attemptNumber: 1,
        device: $device,
        siteId: 9001,
        capability: 'access.door.unlock_timed',
        parameters: ['duration_seconds' => 15],
        expectedState: ['locked' => true],
        idempotencyKey: 'release-fixture-command-7001',
        expiresAt: CarbonImmutable::parse('2026-08-11T00:05:00Z'),
    );
}

it('supports only an owned release fixture door in the explicitly approved runtime', function () {
    $runtime = new ReleaseFixtureRuntimeStub(approved: true, owned: true);
    $adapter = new ReleaseFixtureCommandAdapter($runtime);

    expect($adapter->supports(releaseFixtureCommandDevice(), 'access.door.unlock_timed'))->toBeTrue()
        ->and($adapter->supports(releaseFixtureCommandDevice(['provider' => 'unifi']), 'access.door.unlock_timed'))->toBeFalse()
        ->and($adapter->supports(releaseFixtureCommandDevice(), 'access.door.unlock_timed'))->toBeTrue()
        ->and($adapter->supports(releaseFixtureCommandDevice(['name' => 'Ordinary door']), 'access.door.lock'))->toBeFalse()
        ->and($runtime->ownershipChecks)->toBe(2);
});

it('refuses every non-approved runtime before it checks fixture ownership', function () {
    foreach (['production', 'testing', 'local'] as $runtimeName) {
        $runtime = new ReleaseFixtureRuntimeStub(approved: false, owned: true);
        $adapter = new ReleaseFixtureCommandAdapter($runtime);

        expect($adapter->supports(releaseFixtureCommandDevice(['name' => "RELEASE {$runtimeName} Door"]), 'access.door.unlock_timed'))->toBeFalse()
            ->and($runtime->ownershipChecks)->toBe(0);
    }
});

it('refuses an ordinary device even when every visible fixture attribute matches', function () {
    $runtime = new ReleaseFixtureRuntimeStub(approved: true, owned: false);
    $adapter = new ReleaseFixtureCommandAdapter($runtime);

    expect($adapter->supports(releaseFixtureCommandDevice(), 'access.door.unlock_timed'))->toBeFalse()
        ->and($runtime->ownershipChecks)->toBe(1);
});

it('returns deterministic no-network execution and reconciliation evidence without an external dependency', function () {
    $runtime = new ReleaseFixtureRuntimeStub(approved: true, owned: true);
    $adapter = new ReleaseFixtureCommandAdapter($runtime);
    $context = releaseFixtureCommandContext(releaseFixtureCommandDevice());

    $result = $adapter->execute($context);
    $observation = $adapter->observe($context);

    expect($result->status)->toBe(CommandAttemptStatus::Succeeded)
        ->and($result->safeSummary)->toBe([
            'execution_mode' => 'release_fixture_no_network',
            'evidence_class' => 'simulated_release_fixture_not_provider_evidence',
            'unlock_duration_seconds' => 15,
        ])
        ->and($result->providerRequestReference)->toBe('release-fixture-no-network:'.$context->commandUuid)
        ->and($observation->state)->toBe(['locked' => true])
        ->and($observation->observationReference)->toBe('release-fixture-no-network:door-state:'.hash('sha256', $context->attemptUuid))
        ->and($observation->safeEvidenceSummary)->toContain('not provider or equipment evidence')
        ->and($runtime->ownershipChecks)->toBe(2);
});
