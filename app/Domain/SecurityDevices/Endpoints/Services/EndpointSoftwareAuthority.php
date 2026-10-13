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

final class EndpointSoftwareAuthority
{
    public const CAPABILITY = 'endpoint.software.install';

    public function agent(Device $device, bool $fresh = true): EndpointAgent
    {
        $policies = app(EndpointSoftwarePolicy::class);
        if (! $policies->available()) {
            throw ValidationException::withMessages(['capability' => 'Governed software installs are not available in this deployment.']);
        }
        $device = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
        $agent = EndpointAgent::query()->where('device_id', $device->id)->lock('for update nowait')->first();
        abort_unless($agent && $agent->enrolled_at && ! $agent->revoked_at && $agent->credential_hash, 404);
        app(EndpointAgentIdentity::class)->assertScope($agent);
        if ($fresh && (! $agent->last_seen_at || ! $agent->observed_at || $agent->last_seen_at->lessThanOrEqualTo(now()->subSeconds(180)) || $agent->observed_at->lessThan(now()->subSeconds(360)) || $agent->observed_at->greaterThan(now()->addSeconds(30)))) {
            throw ValidationException::withMessages(['capability' => 'A fresh installed software-install agent observation is required.']);
        }
        $evidence = $agent->software_actions;
        if ($fresh && (! is_array($evidence) || ($evidence['version'] ?? null) !== 1 || ($evidence['installed_service'] ?? null) !== true || ($evidence['release_enabled'] ?? null) !== true || ($evidence['executor'] ?? null) !== 'windows_msi')) {
            throw ValidationException::withMessages(['capability' => 'The installed agent does not currently declare governed software execution.']);
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
            $policy = app(EndpointSoftwarePolicy::class)->current($device);
            EndpointSoftwareContract::parameters($parameters);
            $package = app(EndpointSoftwarePackages::class)->published($parameters['package_uuid']);
            if (! $policy['enabled'] || ($agent->inventory['architecture'] ?? null) !== $package->architecture) {
                throw ValidationException::withMessages(['parameters' => 'The approved package is unavailable for this computer policy or architecture.']);
            }
            abort_unless($actor->canDo(EndpointSoftwarePolicy::SERVICE), 403);
            abort_unless($policy['consent_mode'] !== 'unattended' || $actor->canDo(EndpointSoftwarePolicy::UNATTENDED), 403);

            return ['software_installed' => true, 'package_uuid' => $package->uuid, 'package_version' => $package->package_version, 'package_sha256' => $package->sha256, 'metadata_hash' => $package->metadata_hash, 'product_code' => $package->product_code, 'product_version' => $package->product_version, 'architecture' => $package->architecture, 'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'policy_version' => $policy['version'], 'consent_mode' => $policy['consent_mode']];
        });
    }

    public function assertRequest(DeviceCommandRequest $request, User $actor): EndpointAgent
    {
        $current = $this->bind($request->device, $actor, $request->encrypted_parameters);
        $bound = $request->expected_state;
        ksort($bound);
        ksort($current);
        if ($bound !== $current) {
            throw ValidationException::withMessages(['command' => 'The approved software release, policy or computer identity changed. Request a newly reviewed action.']);
        }

        return $this->agent($request->device);
    }
}
