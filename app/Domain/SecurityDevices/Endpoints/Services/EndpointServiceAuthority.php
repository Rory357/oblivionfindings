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

final class EndpointServiceAuthority
{
    public const CAPABILITY = 'endpoint.service.manage';

    public function agent(Device $device, bool $fresh = true): EndpointAgent
    {
        $policies = app(EndpointActionPolicy::class);
        if (! $policies->available()) {
            throw ValidationException::withMessages(['capability' => 'Governed service actions are not available in this deployment.']);
        }
        $device = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
        $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->first();
        abort_unless($agent && $agent->enrolled_at && ! $agent->revoked_at && $agent->credential_hash, 404);
        app(EndpointAgentIdentity::class)->assertScope($agent);
        if ($fresh && (! $agent->last_seen_at || ! $agent->observed_at || $agent->last_seen_at->lessThanOrEqualTo(now()->subSeconds(180)) || $agent->observed_at->lessThan(now()->subSeconds(360)) || $agent->observed_at->greaterThan(now()->addSeconds(30)))) {
            throw ValidationException::withMessages(['capability' => 'A fresh installed service-action agent observation is required.']);
        }
        $evidence = $agent->service_actions;
        if ($fresh && (! is_array($evidence) || ($evidence['version'] ?? null) !== 1 || ($evidence['installed_service'] ?? null) !== true || ($evidence['release_enabled'] ?? null) !== true || ($evidence['executor'] ?? null) !== 'windows_scm' || ! is_array($evidence['operations'] ?? null))) {
            throw ValidationException::withMessages(['capability' => 'The installed agent does not currently declare governed service execution.']);
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
            $policy = app(EndpointActionPolicy::class)->current($device);
            $catalogue = app(EndpointServiceCatalogue::class);
            if (! $policy['enabled'] || ! $catalogue->allows((string) ($parameters['service_name'] ?? ''), (string) ($parameters['operation'] ?? '')) || ! in_array($parameters['operation'], $agent->service_actions['operations'], true)) {
                throw ValidationException::withMessages(['parameters' => 'This service operation is not enabled by the current approved policy.']);
            }
            abort_unless($actor->canDo(EndpointActionPolicy::SERVICE), 403);
            abort_unless($policy['consent_mode'] !== 'unattended' || $actor->canDo(EndpointActionPolicy::UNATTENDED), 403);

            return ['service_name' => $parameters['service_name'], 'service_state' => $parameters['operation'] === 'stop' ? 'stopped' : 'running', 'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'policy_version' => $policy['version'], 'consent_mode' => $policy['consent_mode'], 'catalogue_digest' => $catalogue->digest()];
        });
    }

    public function assertRequest(DeviceCommandRequest $request, User $actor): EndpointAgent
    {
        $current = $this->bind($request->device, $actor, $request->encrypted_parameters);
        $bound = $request->expected_state;
        ksort($bound);
        ksort($current);
        if ($bound !== $current) {
            throw ValidationException::withMessages(['command' => 'The approved service, policy or computer identity changed. Request a newly reviewed action.']);
        }

        return $this->agent($request->device);
    }
}
