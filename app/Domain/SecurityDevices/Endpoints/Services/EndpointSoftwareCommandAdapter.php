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

final class EndpointSoftwareCommandAdapter implements CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool
    {
        return $capability === EndpointSoftwareContract::CAPABILITY;
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        throw new \LogicException('Native MSI execution requires an authenticated final claim. Central provider execution is forbidden.');
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return DB::transaction(function () use ($context): CommandObservedState {
            $device = Device::query()->whereKey($context->device->id)->lock('for update nowait')->firstOrFail();
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->firstOrFail();
            app(EndpointAgentIdentity::class)->assertScope($agent);
            $delivery = EndpointDelivery::query()->where('endpoint_agent_id', $agent->id)->where('agent_uuid', $agent->uuid)->whereHas('attempt', fn ($q) => $q->where('attempt_uuid', $context->attemptUuid))->lock('for update nowait')->firstOrFail();
            $entry = $delivery->software_readback;
            if ($agent->uuid !== ($context->expectedState['agent_uuid'] ?? null) || $agent->assignment_fingerprint !== ($context->expectedState['assignment_fingerprint'] ?? null) || ! $agent->last_seen_at || $agent->last_seen_at->lessThanOrEqualTo(now()->subSeconds(180)) || ! $agent->observed_at || $agent->observed_at->lessThan(now()->subSeconds(120)) || $agent->observed_at->greaterThan(now()->addSeconds(30)) || ! $delivery->completed_at || ($delivery->result['status'] ?? null) !== 'awaiting_observation' || ! in_array($delivery->result['exit_code'] ?? null, [0, 3010], true) || ($delivery->result['mutation_started'] ?? false) !== true || ! $entry || ! $delivery->readback_received_at || $delivery->readback_received_at->lessThanOrEqualTo(now()->subSeconds(180)) || ! $delivery->readback_sequence || $delivery->readback_sequence > $agent->sequence) {
                throw new \RuntimeException('Independent machine installation completion is unconfirmed.');
            }
            $observed = CarbonImmutable::parse($entry['observed_at']);
            if ($entry['command_uuid'] !== $context->commandUuid || $entry['attempt_uuid'] !== $context->attemptUuid || $entry['product_code'] !== ($context->expectedState['product_code'] ?? null) || $entry['boot_id'] !== $agent->boot_id || ! $entry['transaction_quiescent'] || $entry['state'] === 'unknown' || ! $observed->greaterThan($delivery->completed_at) || $observed->greaterThan($agent->observed_at) || $observed->lessThan(now()->subSeconds(120))) {
                throw new \RuntimeException('Fresh exact-attempt machine product evidence is unavailable.');
            }
            $state = $context->expectedState;
            $state['software_installed'] = $entry['state'] === 'installed';
            $state['product_version'] = $entry['product_version'];

            return new CommandObservedState($state, $observed, 'endpoint-heartbeat:'.$agent->uuid.':'.$delivery->readback_sequence, 'A known installer receipt and fresh independently measured machine ProductCode/version with installer quiescence confirm this exact attempt.');
        });
    }
}
