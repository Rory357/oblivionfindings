<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/** Current agent observation only. This is never policy, approval or launch authority. */
final class EndpointScriptReadiness
{
    /** The caller must retain this transaction through its command decision. */
    public function current(Device $device): EndpointAgent
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Script readiness requires the current command transaction.');
        }
        abort_unless(config('endpoint_management.enabled') && Schema::hasColumn('endpoint_agents', 'script_actions'), 503);
        $locked = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
        $agent = EndpointAgent::query()->where('device_id', $locked->id)->lock('for update nowait')->first();
        abort_unless($agent && $agent->enrolled_at && $agent->credential_hash && ! $agent->revoked_at, 409);
        app(EndpointAgentIdentity::class)->assertScope($agent);
        $evidence = $agent->script_actions;
        abort_unless(is_array($evidence) && count($evidence) === 4
            && ($evidence['version'] ?? null) === 1
            && ($evidence['installed_service'] ?? null) === true
            && ($evidence['release_enabled'] ?? null) === true
            && ($evidence['executor'] ?? null) === 'reviewed_script', 409);
        abort_unless($agent->sequence > 0 && is_string($agent->heartbeat_hash)
            && preg_match('/\A[a-f0-9]{64}\z/', $agent->heartbeat_hash)
            && is_string($agent->boot_id) && Str::isUuid($agent->boot_id)
            && in_array($agent->inventory['architecture'] ?? null, ['amd64', 'arm64'], true)
            && $agent->last_seen_at && $agent->observed_at
            && $agent->last_seen_at->greaterThan(now()->subSeconds(180))
            && $agent->last_seen_at->lessThanOrEqualTo(now())
            && $agent->observed_at->greaterThan(now()->subSeconds(120))
            && $agent->observed_at->lessThanOrEqualTo(now()->addSeconds(30)), 409);

        return $agent;
    }
}
