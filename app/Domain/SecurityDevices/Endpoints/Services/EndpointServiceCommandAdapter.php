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

final class EndpointServiceCommandAdapter implements CommandExecutionAdapter
{
    public function supports(Device $device, string $capability): bool
    {
        // Observation remains available after release withdrawal; fresh execution is routed separately.
        return $capability === EndpointServiceAuthority::CAPABILITY;
    }

    public function execute(CommandExecutionContext $context): CommandExecutionResult
    {
        throw new \LogicException('Native service execution requires an authenticated short claim, never a central provider call.');
    }

    public function observe(CommandExecutionContext $context): CommandObservedState
    {
        return DB::transaction(function () use ($context): CommandObservedState {
            $device = Device::query()->whereKey($context->device->id)->lock('for update nowait')->firstOrFail();
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->firstOrFail();
            app(EndpointAgentIdentity::class)->assertScope($agent);
            $delivery = EndpointDelivery::query()->whereHas('attempt', fn ($q) => $q->where('attempt_uuid', $context->attemptUuid)->lock('for update nowait'))->lock('for update nowait')->firstOrFail();
            if ($agent->uuid !== ($context->expectedState['agent_uuid'] ?? null) || $agent->assignment_fingerprint !== ($context->expectedState['assignment_fingerprint'] ?? null)
                || ! $delivery->completed_at || ! $agent->observed_at || $agent->observed_at->lessThanOrEqualTo($delivery->completed_at)
                || ! $agent->last_seen_at || $agent->last_seen_at->lessThanOrEqualTo(now()->subSeconds(180)) || $agent->observed_at->lessThan(now()->subSeconds(360)) || $agent->observed_at->greaterThan(now()->addSeconds(30))) {
                throw new \RuntimeException('Fresh post-result native state is unavailable.');
            }
            // A running service alone cannot prove an uncertain restart was completed.
            if ($context->parameters['operation'] === 'restart' && ($delivery->result['status'] ?? null) !== 'succeeded') {
                throw new \RuntimeException('Restart completion is unconfirmed.');
            }
            $services = collect($delivery->service_readback ? [$delivery->service_readback] : [])->filter(fn ($row) => is_array($row)
                && ($row['command_uuid'] ?? null) === $context->commandUuid && ($row['attempt_uuid'] ?? null) === $context->attemptUuid
                && ($row['service_name'] ?? null) === $context->parameters['service_name']);
            if ($services->count() !== 1 || ! in_array($services->first()['state'] ?? null, ['running', 'stopped'], true)) {
                throw new \RuntimeException('Independent service observation is unavailable.');
            }
            $observed = CarbonImmutable::parse($services->first()['observed_at']);
            if (! $delivery->readback_received_at || $delivery->readback_received_at->lessThanOrEqualTo(now()->subSeconds(180)) || ! $delivery->readback_sequence || $delivery->readback_sequence > $agent->sequence
                || ! $observed->greaterThan($delivery->completed_at) || $observed->greaterThan($agent->observed_at) || $observed->lessThan(now()->subSeconds(360))) {
                throw new \RuntimeException('Independent service observation is stale.');
            }
            $state = $context->expectedState;
            $state['service_state'] = $services->first()['state'];

            return new CommandObservedState($state, $observed, 'endpoint-heartbeat:'.$agent->uuid.':'.$agent->sequence, 'Fresh independently measured native service state observed after the immutable execution receipt.');
        });
    }
}
