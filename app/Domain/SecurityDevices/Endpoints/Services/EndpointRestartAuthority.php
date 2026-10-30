<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\It\Services\ItRemoteSupportAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EndpointRestartAuthority
{
    public const CAPABILITY = 'endpoint.computer.restart';

    public function agent(Device $device, bool $fresh = true): EndpointAgent
    {
        $policies = app(EndpointRestartPolicy::class);
        if (! $policies->available()) {
            throw ValidationException::withMessages(['capability' => 'Governed scheduled restarts are not available in this deployment.']);
        }
        $device = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
        $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->first();
        abort_unless($agent && $agent->enrolled_at && ! $agent->revoked_at && $agent->credential_hash, 404);
        app(EndpointAgentIdentity::class)->assertScope($agent);
        if ($fresh && (! $agent->last_seen_at || ! $agent->observed_at || $agent->last_seen_at->lessThanOrEqualTo(now()->subSeconds(180)) || $agent->observed_at->lessThan(now()->subSeconds(360)) || $agent->observed_at->greaterThan(now()->addSeconds(30)))) {
            throw ValidationException::withMessages(['capability' => 'A fresh installed restart agent observation is required.']);
        }
        $evidence = $agent->restart_actions;
        if ($fresh && (! is_array($evidence) || ($evidence['version'] ?? null) !== 1 || ($evidence['installed_service'] ?? null) !== true || ($evidence['release_enabled'] ?? null) !== true || ($evidence['executor'] ?? null) !== 'windows_restart')) {
            throw ValidationException::withMessages(['capability' => 'The installed agent does not currently declare governed restart execution.']);
        }

        return $agent;
    }

    public function supports(Device $device): bool
    {
        try {
            return DB::transaction(fn (): bool => (bool) $this->agent($device));
        } catch (\Throwable) {
            return false;
        }
    }

    public function bind(Device $device, User $actor, array $parameters): array
    {
        return DB::transaction(function () use ($device, $actor, $parameters): array {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $actor->id, ['*']);
            abort_unless($actor->approved_at && $actor->email_verified_at && ! $actor->external_clinical_account
                && app(ItRemoteSupportAccess::class)->canOpen($actor), 403);
            $device = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
            CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $device): void {
                $access = app(SecurityDevicesAccessService::class)->forCurrentEvidence($reads, (int) $device->id);
                $access->assertCanViewDevice($actor, $device);
                foreach ($reads->query($device->assignments()->active()->getQuery())->get() as $assignment) {
                    abort_unless($access->canAccessCurrentAssignment($actor, $assignment), 404);
                }
                $decision = app(DeviceManagementAuthorizationService::class)->forCurrentEvidence($reads, (int) $device->id)
                    ->evaluate($actor, $device, app(CommandCapabilityRegistry::class)->definition(self::CAPABILITY), fresh: true);
                abort_unless($decision->allowed, $decision->concealed ? 404 : 403);
            });
            $agent = $this->agent($device);
            $policy = app(EndpointRestartPolicy::class)->current($device);
            if (! $policy['enabled'] || ($parameters['warning_seconds'] ?? 0) < $policy['minimum_warning_seconds']) {
                throw ValidationException::withMessages(['parameters' => 'This restart schedule is not enabled by the current approved policy.']);
            }
            abort_unless($actor->canDo(EndpointRestartPolicy::SERVICE), 403);
            abort_unless($policy['consent_mode'] !== 'unattended' || $actor->canDo(EndpointRestartPolicy::UNATTENDED), 403);

            return ['boot_changed' => true, 'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'policy_version' => $policy['version'], 'consent_mode' => $policy['consent_mode'], 'minimum_warning_seconds' => $policy['minimum_warning_seconds']];
        });
    }

    public function assertNoOtherSchedule(DeviceCommandRequest $request): void
    {
        // Queue/dispatch owns Device and Agent already. A delivered cancellation
        // remains active until its no-API acknowledgement or absolute expiry.
        $other = DeviceCommandRequest::query()->where('device_id', $request->device_id)->where('capability', self::CAPABILITY)->whereKeyNot($request->id)
            ->whereIn('status', ['queued', 'dispatching', 'accepted'])->where('expires_at', '>', now())->orderBy('id')->lock('for update nowait')->first(['id']);
        if ($other) {
            throw ValidationException::withMessages(['command' => 'Another restart schedule is still active for this computer. Confirm its cancellation before scheduling a replacement.']);
        }
    }

    public function assertRequest(DeviceCommandRequest $request, User $actor): EndpointAgent
    {
        $current = $this->bind($request->device, $actor, $request->encrypted_parameters);
        $bound = $request->expected_state;
        ksort($bound);
        ksort($current);
        if ($bound !== $current) {
            throw ValidationException::withMessages(['command' => 'The approved restart policy or computer identity changed. Request a newly reviewed action.']);
        }

        return $this->agent($request->device);
    }
}
