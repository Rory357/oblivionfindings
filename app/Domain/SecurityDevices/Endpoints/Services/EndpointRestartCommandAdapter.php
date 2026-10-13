<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;
use App\Domain\SecurityDevices\Management\Contracts\CommandExecutionAdapter;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionContext;
use App\Domain\SecurityDevices\Management\Data\CommandExecutionResult;
use App\Domain\SecurityDevices\Management\Data\CommandObservedState;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class EndpointRestartCommandAdapter implements CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool
    {
        return $capability === EndpointRestartContract::CAPABILITY;
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        throw new \LogicException('Native restart requires an authenticated final claim; central provider execution is forbidden.');
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return DB::transaction(function () use ($context): CommandObservedState {
            $device = Device::query()->whereKey($context->device->id)->lock('for update nowait')->firstOrFail();
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->firstOrFail();
            app(EndpointAgentIdentity::class)->assertScope($agent);
            $delivery = EndpointDelivery::query()->whereHas('attempt', fn ($q) => $q->where('attempt_uuid', $context->attemptUuid)->lock('for update nowait'))->lock('for update nowait')->firstOrFail();
            $entry = $delivery->restart_readback;
            $claim = $delivery->encrypted_claim ? json_decode(base64_decode($delivery->encrypted_claim['payload'], true), true, 512, JSON_THROW_ON_ERROR) : null;
            if ($agent->uuid !== ($context->expectedState['agent_uuid'] ?? null) || $agent->assignment_fingerprint !== ($context->expectedState['assignment_fingerprint'] ?? null) || ! $agent->last_seen_at || $agent->last_seen_at->lessThanOrEqualTo(now()->subSeconds(180)) || ! $agent->observed_at || $agent->observed_at->lessThan(now()->subSeconds(120)) || $agent->observed_at->greaterThan(now()->addSeconds(30)) || ! $delivery->completed_at || ($delivery->result['status'] ?? null) !== 'awaiting_boot' || ($delivery->result['reason_code'] ?? null) !== 'restart_requested' || ($delivery->result['mutation_started'] ?? false) !== true || ! $entry || ! $claim || ! $delivery->readback_sequence || $delivery->readback_sequence > $agent->sequence || ! $delivery->readback_received_at || $delivery->readback_received_at->lessThanOrEqualTo(now()->subSeconds(180))) {
                throw new \RuntimeException('Independent restart completion is unconfirmed.');
            }
            $observed = CarbonImmutable::parse($entry['observed_at']);
            if ($entry['command_uuid'] !== $context->commandUuid || $entry['attempt_uuid'] !== $context->attemptUuid || $entry['before_boot_id'] !== $claim['boot_id'] || $entry['before_boot_id'] !== $delivery->result['boot_id'] || $entry['boot_id'] !== $agent->boot_id || $entry['before_boot_id'] === $entry['boot_id'] || ! $observed->greaterThan($delivery->completed_at) || $observed->lessThan(CarbonImmutable::parse($context->parameters['execute_at'])) || $observed->greaterThan($agent->observed_at) || $observed->lessThan(now()->subSeconds(120))) {
                throw new \RuntimeException('Fresh exact-attempt changed boot is unavailable.');
            }

            return new CommandObservedState($context->expectedState, $observed, 'endpoint-heartbeat:'.$agent->uuid.':'.$delivery->readback_sequence, 'An immutable accepted restart receipt and fresh authenticated changed boot confirm this exact attempt.');
        });
    }
}
