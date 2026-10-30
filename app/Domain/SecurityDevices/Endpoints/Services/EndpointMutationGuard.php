<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointDelivery;

/** Caller owns the authenticated Device/Agent mutex. Receipt completion does not prove a queued reboot stopped. */
final class EndpointMutationGuard
{
    public function assertAvailable(EndpointAgent $agent, EndpointDelivery $own): void
    {
        // Locking joined reads are current under MySQL REPEATABLE READ. A plain
        // exists/whereHas can reuse an older outer snapshot despite the mutex.
        $softwareReady = EndpointDelivery::query()->getConnection()->getSchemaBuilder()->hasColumn('endpoint_deliveries', 'software_readback');
        $others = EndpointDelivery::query()->join('device_command_attempts as guard_attempts', 'guard_attempts.id', '=', 'endpoint_deliveries.device_command_attempt_id')
            ->join('device_command_requests as guard_requests', 'guard_requests.id', '=', 'guard_attempts.device_command_request_id')
            ->where('endpoint_deliveries.endpoint_agent_id', $agent->id)->where('endpoint_deliveries.agent_uuid', $agent->uuid)->where('endpoint_deliveries.id', '!=', $own->id)->whereNotNull('endpoint_deliveries.claimed_at')
            ->where(function ($q) use ($softwareReady): void {
                $q->whereNull('endpoint_deliveries.completed_at')->orWhere(fn ($restart) => $restart->where('guard_requests.capability', EndpointRestartContract::CAPABILITY)->where('endpoint_deliveries.result->mutation_started', true)->whereIn('endpoint_deliveries.result->status', ['awaiting_boot', 'uncertain']));
                // An uncertain report (including unknown entry) is not proof of
                // quiescence. Entered scripts retain exclusion until the separately
                // verified native owned-job completion contract is implemented.
                $q->orWhere(fn ($script) => $script->where('guard_requests.capability', EndpointScriptContract::CAPABILITY)
                    ->where(fn ($unsettled) => $unsettled->where('endpoint_deliveries.result->status', 'uncertain')
                        ->orWhere('endpoint_deliveries.result->mutation_started', true)));
                // Existing SCM/restart releases remain usable before the additive software migration is installed.
                $q->orWhere(function ($software) use ($softwareReady): void {
                    $software->where('guard_requests.capability', EndpointSoftwareContract::CAPABILITY)->where('endpoint_deliveries.result->mutation_started', true);
                    if ($softwareReady) {
                        $software->where(fn ($unsettled) => $unsettled->whereNull('endpoint_deliveries.readback_received_at')->orWhereNull('endpoint_deliveries.software_readback->transaction_quiescent')->orWhere('endpoint_deliveries.software_readback->transaction_quiescent', false));
                    }
                    // Missing proof storage can never release a historical entered installer attempt.
                });
            })
            ->orderBy('endpoint_deliveries.id')->limit(101)->lock('for update nowait')->get(['endpoint_deliveries.*', 'guard_requests.capability as guard_capability']);
        abort_if($others->count() > 100, 409);
        foreach ($others as $other) {
            if (! $other->completed_at || $other->guard_capability === EndpointScriptContract::CAPABILITY || ($other->guard_capability === EndpointRestartContract::CAPABILITY && ($other->restart_readback['boot_id'] ?? null) !== $agent->boot_id) || ($other->guard_capability === EndpointSoftwareContract::CAPABILITY && (! $other->readback_received_at || ($other->software_readback['transaction_quiescent'] ?? false) !== true))) {
                abort(409);
            }
        }
    }
}
