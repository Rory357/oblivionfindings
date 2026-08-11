<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Non-network adapter for explicitly owned desktop-release fixture Devices.
 *
 * This is deliberately incapable of selecting an ordinary Device, acquiring a
 * credential, or opening a provider connection. It demonstrates the governed
 * command lifecycle only; it is never provider or equipment evidence.
 */
final class ReleaseFixtureCommandAdapter implements CommandExecutionAdapter
{
    private const string PROVIDER = 'release_fixture';

    private const string CAPABILITY = 'access.door.unlock_timed';

    public function __construct(private readonly ReleaseFixtureCommandRuntime $runtime) {}

    public function supports(Device $device, string $capability): bool
    {
        return $capability === self::CAPABILITY
            && strtolower(trim((string) $device->provider)) === self::PROVIDER
            && $device->domain === 'security'
            && $device->category === 'access_control'
            && data_get($device->config ?? [], 'management.release_fixture.no_network') === true
            && $this->runtime->isApprovedStagingFixtureRuntime()
            && $this->runtime->owns($device);
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        $this->assertContext($context);

        return new CommandExecutionResult(
            status: CommandAttemptStatus::Succeeded,
            safeSummary: [
                'execution_mode' => 'release_fixture_no_network',
                'evidence_class' => 'simulated_release_fixture_not_provider_evidence',
                'unlock_duration_seconds' => (int) $context->parameters['duration_seconds'],
            ],
            providerRequestReference: 'release-fixture-no-network:'.$context->commandUuid,
        );
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        $this->assertContext($context);

        return new CommandObservedState(
            state: ['locked' => true],
            observedAt: CarbonImmutable::now('UTC')->startOfSecond(),
            observationReference: 'release-fixture-no-network:door-state:'.hash('sha256', $context->attemptUuid),
            safeEvidenceSummary: 'Simulated no-network release fixture only: the fixture door returned to locked; this is not provider or equipment evidence.',
        );
    }

    private function assertContext(CommandExecutionContext $context): void
    {
        if (! $this->supports($context->device, $context->capability)
            || (int) ($context->parameters['duration_seconds'] ?? 0) < 5
            || (int) ($context->parameters['duration_seconds'] ?? 0) > 60) {
            throw new RuntimeException('The release fixture command context is not eligible for no-network execution.');
        }
    }
}
