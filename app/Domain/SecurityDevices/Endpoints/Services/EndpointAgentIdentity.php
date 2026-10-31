<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Support\SecurityPolicy;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EndpointAgentIdentity
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CommandAssignmentFingerprint $assignments,
        private readonly EndpointCommandSigner $signer,
    ) {}

    /** Raw enrolment material is returned once and never stored in an event. */
    public function issue(User $actor, Device $device, string $platform): array
    {
        abort_unless(app(EndpointRemoteSupportSettings::class)->endpointEnabled(), 404);
        $publicKey = $this->signer->publicKey();

        return DB::transaction(function () use ($actor, $device, $platform, $publicKey): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertAdministrator($actor, $device);
            $this->assertComputer($device);
            if ($platform !== 'windows') {
                throw ValidationException::withMessages(['platform' => 'Computer agents currently support Windows only.']);
            }
            $site = $this->sites->resolve((int) $device->id);
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lockForUpdate()->first();
            if ($agent && $agent->revoked_at === null) {
                throw ValidationException::withMessages(['agent' => 'Revoke the existing enrolment before replacing it.']);
            }
            $token = bin2hex(random_bytes(32));
            $agent ??= new EndpointAgent;
            $agent->fill([
                'uuid' => (string) Str::uuid(), 'device_id' => $device->id, 'site_id' => $site,
                'assignment_fingerprint' => $this->assignments->forDevice($device),
                'platform' => $platform, 'enrolment_hash' => hash('sha256', $token),
                'enrolment_expires_at' => now()->addMinutes(max(1, min(30, (int) config('endpoint_management.enrolment_minutes', 15)))),
                'credential_hash' => null, 'enrolled_at' => null, 'revoked_at' => null,
                'last_seen_at' => null, 'observed_at' => null, 'agent_version' => null,
                ...(Schema::hasColumn('endpoint_agents', 'script_actions') ? ['script_actions' => null] : []),
                ...(Schema::hasColumn('endpoint_agents', 'software_actions') ? ['software_actions' => null, 'software_observations' => null] : []),
                ...(Schema::hasColumn('endpoint_agents', 'restart_actions') ? ['restart_actions' => null, 'restart_observations' => null] : []),
                'boot_id' => null, 'sequence' => 0, 'heartbeat_hash' => null, 'inventory' => null,
                ...(Schema::hasColumn('endpoint_agents', 'service_actions') ? ['service_actions' => null, 'service_action_observations' => null] : []),
                'created_by_user_id' => $actor->id,
            ])->save();
            $this->event($device, $actor, 'endpoint_enrolment_issued', ['agent_uuid' => $agent->uuid, 'platform' => $platform]);

            return [
                'agent_uuid' => $agent->uuid, 'enrolment_token' => $token,
                'expires_at' => $agent->enrolment_expires_at->toISOString(),
                'server_public_key' => $publicKey, 'platform' => $platform,
            ];
        });
    }

    /** Read-only installation context, with current authority and canonical assignment. */
    public function installationContext(User $actor, Device $device): array
    {
        abort_unless(app(EndpointRemoteSupportSettings::class)->endpointEnabled(), 404);

        return DB::transaction(function () use ($actor, $device): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertAdministrator($actor, $device);
            $this->assertComputer($device);

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $device): array {
                $siteId = $this->sites->forCurrentEvidence($reads)->resolve((int) $device->id);
                $site = $reads->query(Site::query())->findOrFail($siteId);
                $agent = EndpointAgent::query()->where('device_id', $device->id)->lockForUpdate()->first();
                $state = ! $agent ? 'none' : ($agent->revoked_at ? 'revoked' : ($agent->enrolled_at ? 'enrolled'
                    : ($agent->enrolment_expires_at?->isFuture() ? 'pending' : 'expired')));

                return [
                    'actor_id' => (int) $actor->id, 'device_id' => (int) $device->id,
                    'device_label' => $device->name, 'device_uid' => $device->device_uid,
                    'site' => ['id' => $siteId, 'name' => $site->name],
                    'assignment_fingerprint' => $this->assignments->forDevice($device, reads: $reads),
                    'enrolment' => ['state' => $state, 'uuid' => $agent?->uuid,
                        'expires_at' => $agent?->enrolment_expires_at?->toISOString()],
                ];
            });
        });
    }

    /** Retransmission is accepted only for the same client-generated credential. */
    public function enrol(string $token, string $credential, string $platform): array
    {
        abort_unless(app(EndpointRemoteSupportSettings::class)->endpointEnabled(), 404);
        abort_unless($platform === 'windows', 422);
        $this->assertToken($token);
        $this->assertToken($credential);
        abort_if(hash_equals($token, $credential), 422);

        return DB::transaction(function () use ($token, $credential, $platform): array {
            $candidate = EndpointAgent::query()->where('enrolment_hash', hash('sha256', $token))->first();
            abort_unless($candidate, 401);
            Device::query()->whereKey($candidate->device_id)->lockForUpdate()->firstOrFail();
            $agent = EndpointAgent::query()->whereKey($candidate->id)->where('enrolment_hash', hash('sha256', $token))->lockForUpdate()->first();
            abort_unless($agent && $agent->revoked_at === null && $agent->enrolment_expires_at?->isFuture(), 401);
            abort_unless($agent->platform === $platform, 422);
            $this->assertScope($agent);
            $actor = User::query()->find($agent->created_by_user_id);
            abort_unless($actor, 401);
            $this->assertAdministrator($actor, $agent->device);
            $credentialHash = hash('sha256', $credential);
            if ($agent->credential_hash !== null) {
                abort_unless(hash_equals($agent->credential_hash, $credentialHash), 401);
            } else {
                abort_if(EndpointAgent::query()->where('credential_hash', $credentialHash)->exists(), 422);
                $agent->credential_hash = $credentialHash;
                $agent->enrolled_at = now();
                $agent->save();
                $this->event($agent->device, $actor, 'endpoint_enrolled', ['agent_uuid' => $agent->uuid]);
            }

            return ['agent_uuid' => $agent->uuid, 'server_public_key' => $this->signer->publicKey()];
        });
    }

    public function authenticate(string $credential): EndpointAgent
    {
        return $this->withAuthenticated($credential, fn (EndpointAgent $agent): EndpointAgent => $agent);
    }

    /** Recheck identity under the same lock as the accepted report or delivery. */
    public function withAuthenticated(string $credential, Closure $operation): mixed
    {
        abort_unless(app(EndpointRemoteSupportSettings::class)->endpointEnabled(), 404);
        $this->assertToken($credential);

        return DB::transaction(function () use ($credential, $operation): mixed {
            $hash = hash('sha256', $credential);
            $candidate = EndpointAgent::query()->where('credential_hash', $hash)->first();
            abort_unless($candidate, 401);
            Device::query()->whereKey($candidate->device_id)->lockForUpdate()->firstOrFail();
            $agent = EndpointAgent::query()->whereKey($candidate->id)->where('credential_hash', $hash)
                ->whereNotNull('enrolled_at')->whereNull('revoked_at')->lockForUpdate()->first();
            abort_unless($agent, 401);
            $this->assertScope($agent);

            return $operation($agent);
        });
    }

    public function revoke(User $actor, Device $device, string $reason, ?string $expectedUuid = null): void
    {
        DB::transaction(function () use ($actor, $device, $reason, $expectedUuid): void {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $this->assertAdministrator($actor, $device);
            if (mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 500 || preg_match('/[\\x00-\\x1F\\x7F]/', $reason)) {
                throw ValidationException::withMessages(['reason' => 'Give a reason between 10 and 500 characters.']);
            }
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lockForUpdate()->firstOrFail();
            abort_if($expectedUuid !== null && ! hash_equals($agent->uuid, $expectedUuid), 409, 'The enrolment changed. Refresh before revoking it.');
            if ($agent->revoked_at !== null) {
                return;
            }
            $agent->forceFill([
                'revoked_at' => now(), 'credential_hash' => null, 'enrolment_hash' => null,
                'enrolment_expires_at' => null,
            ])->save();
            $this->event($device, $actor, 'endpoint_revoked', ['agent_uuid' => $agent->uuid, 'reason' => trim($reason)]);
        });
    }

    public function assertScope(EndpointAgent $agent): void
    {
        $agent->setRelation('device', Device::query()->lockForUpdate()->find($agent->device_id));
        abort_unless($agent->device && $agent->revoked_at === null && $agent->platform === 'windows', 401);
        $this->assertComputer($agent->device);
        try {
            $matches = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads): bool => $this->sites->forCurrentEvidence($reads)->resolve((int) $agent->device_id) === (int) $agent->site_id
                && hash_equals($agent->assignment_fingerprint, $this->assignments->forDevice($agent->device, reads: $reads))
            );
        } catch (Throwable) {
            $matches = false;
        }
        abort_unless($matches, 401, 'Endpoint assignment changed; approved re-enrolment is required.');
    }

    private function assertAdministrator(User $actor, Device $device): void
    {
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $device): void {
            $current = $reads->query(User::query())->findOrFail($actor->id);
            $current->load([
                'permissionOverrides' => fn ($query) => $reads->query($query->getQuery()),
                'roles' => fn ($query) => $reads->query($query->getQuery()),
                'roles.permissions' => fn ($query) => $reads->query($query->getQuery()),
            ]);
            abort_unless($current->email_verified_at && ! $current->external_clinical_account, 403);
            foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
                abort_if($current->role === $role || $current->hasRole($role), 403);
            }
            abort_if(SecurityPolicy::forceTwoFactor() && ! $current->two_factor_confirmed_at, 403);
            abort_unless($current->approved_at !== null
                && $current->canDo('securityDevices.viewAny')
                && $current->canDo('securityDevices.devices.view')
                && $current->canDo('securityDevices.devices.update')
                && $current->canDo('securityDevices.commands.admin'), 403);
            $access = $this->access->forCurrentEvidence($reads, (int) $device->id);
            $access->assertCanViewDevice($current, $device);
            $access->assertCanManageActiveAssignment($current, $device, true);
        });
    }

    private function assertComputer(Device $device): void
    {
        abort_unless($device->domain === 'it_infrastructure' && in_array($device->category, ['endpoint', 'server'], true), 422);
    }

    private function assertToken(string $token): void
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 401);
    }

    private function event(Device $device, User $actor, string $kind, array $payload): void
    {
        DeviceEvent::query()->create([
            'device_id' => $device->id, 'event_type' => $kind, 'severity' => 'info',
            'source' => 'oblivion_endpoint', 'occurred_at' => now(),
            'payload' => ['actor_user_id' => $actor->id, ...$payload],
        ]);
    }
}
