<?php

namespace App\Services\Tracking;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceCustodySiteResolver;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\Consents\CurrentConsentEvidence;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ClientLocationCommandContext
{
    public function __construct(private readonly ClientLocationAccessService $access) {}

    public function origin(DeviceAssignment $assignment): ClientLocationCommandOrigin
    {
        return ClientLocationCommandOrigin::fromArray([
            'kind' => 'client_location', 'version' => 1,
            'client_id' => (int) $assignment->assignable_id, 'assignment_id' => (int) $assignment->id,
            'consent_id' => (int) $assignment->consent_id, 'access_fingerprint' => $this->access->fingerprint($assignment),
        ]);
    }

    /** Caller owns the request row and the outer transaction; contention must unwind it. */
    public function lock(ClientLocationCommandOrigin $origin, User $actor, int $deviceId, int $siteId, string $capabilityKey = 'tracking.location_refresh'): array
    {
        CurrentConsentEvidence::assertTransaction();
        try {
            $evidence = CurrentConsentEvidence::lock($origin->consentId);
            $device = Device::query()->whereKey($deviceId)->lock('for share nowait')->firstOrFail();
            $client = $evidence->client($origin->clientId);
            abort_unless($client && (int) $client->site_id === $siteId, 403);
            abort_unless(app(DeviceCustodySiteResolver::class)->resolve(DeviceAssignment::TARGET_CLIENT, (int) $client->id, 'for share nowait') === $siteId, 403);
            // Range lock also prevents a new current assignment appearing before commit.
            $assignments = DeviceAssignment::query()->where('device_id', $deviceId)->active()
                ->orderBy('id')->lock('for share nowait')->get();
            $assignment = $assignments->firstWhere('id', $origin->assignmentId);
            abort_unless($assignment && $assignments->where('assignable_type', DeviceAssignment::TARGET_CLIENT)->count() === 1
                && (int) $assignment->assignable_id === $origin->clientId && (int) $assignment->custody_site_id === $siteId, 403);
            abort_unless(app(PersonalTrackingPrivacyService::class)->assignmentAuthorisesResidentLocationFromCurrentEvidence(
                $assignment, $device, $evidence, $client,
            ), 403, 'Location access has ended.');
            $assignment->setRelation('device', $device);
            $assignment->setRelation('consent', $evidence->consent);
            abort_unless(hash_equals($origin->accessFingerprint, $this->access->fingerprint($assignment)), 403, 'The tracking assignment changed.');
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, ['*']);
            $actor->setRelation('hrEmployeeProfile', HrEmployeeProfile::query()->where('user_id', $actor->id)->lock('for share nowait')->first());
            $care = DB::table('client_user')->where('client_id', $client->id)->where('user_id', $actor->id)->lock('for share nowait')->first();
            $client->setRelation('supportWorkers', new Collection($care ? [$actor] : []));
            abort_unless($actor->approved_at && ($actor->canDo('fleet.manage') || $actor->canDo('assets.trackers.manage')), 403);

            return CurrentAuthorizationReads::within(function ($reads) use ($actor, $client, $device, $assignment, $capabilityKey): array {
                abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($actor, $client, $reads), 403);
                abort_unless(app(ClientProfileSectionAccess::class)->trackingFromCurrentEvidence($actor, $client, $reads), 403);
                $capability = app(CommandCapabilityRegistry::class)->definition($capabilityKey);
                $authorization = app(DeviceManagementAuthorizationService::class)
                    ->forCurrentEvidence($reads, (int) $device->id)->evaluate($actor, $device, $capability, fresh: true);
                abort_unless($authorization->allowed, 403);
                $canonicalSiteId = app(CanonicalDeviceSiteResolver::class)->forCurrentEvidence($reads)->resolve((int) $device->id);
                $assignmentFingerprint = app(CommandAssignmentFingerprint::class)->forDevice($device, reads: $reads);

                return compact('actor', 'client', 'device', 'assignment', 'authorization', 'canonicalSiteId', 'assignmentFingerprint');
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 3572) {
                throw new ClientLocationEvidenceBusy('Access is being updated. Try this same request again.', previous: $error);
            }
            throw $error;
        }
    }

    public function lockForCommand(DeviceCommandRequest $command): ?array
    {
        if ($command->origin_context === null) {
            abort_if(str_starts_with(strtolower($command->idempotency_key), 'client-location'), 403);

            return null;
        }
        $this->assertSupported($command->device, $command->capability, $command->encrypted_parameters);

        $current = $this->lock(ClientLocationCommandOrigin::fromArray($command->origin_context),
            User::query()->findOrFail($command->requested_by_user_id), (int) $command->device_id, (int) $command->site_id, $command->capability);
        abort_unless($current['canonicalSiteId'] === (int) $command->site_id
            && is_string($command->assignment_fingerprint) && hash_equals($command->assignment_fingerprint, $current['assignmentFingerprint']), 403);

        return $current;
    }

    public function assertSupported(Device $device, string $capability, array $parameters): void
    {
        if ($capability === 'tracking.location_refresh' && $parameters === []) {
            return;
        }
        abort_unless($capability === 'configuration.apply' && array_keys($parameters) === ['configuration_profile_id']
            && is_int($parameters['configuration_profile_id']), 403);
        app(ClientTrackerModeProfiles::class)->require($device, $parameters['configuration_profile_id']);
    }
}
