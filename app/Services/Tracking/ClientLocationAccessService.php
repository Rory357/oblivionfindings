<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\User;
use App\Services\Clients\ClientProfileSectionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientLocationAccessService
{
    public function eligibleBoundaries(Client $client, int $siteId): Builder
    {
        $otherClient = fn (Builder $asset) => $asset->whereNotNull('client_id')->where('client_id', '!=', $client->id);

        return AssetGeofence::query()->eligibleForClientSite($siteId)
            ->whereDoesntHave('asset', $otherClient)
            ->whereDoesntHave('assignedAssets', $otherClient);
    }

    public function resolve(User $actor, Client $client, bool $manage = false, bool $lockedActor = false): DeviceAssignment
    {
        // The save transaction already holds current actor and custody evidence.
        $actor = $lockedActor ? $actor : $actor->fresh();
        if ($lockedActor) {
            abort_unless(DB::transactionLevel() > 0, 500);
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->first();
            abort_unless($actor && $client, 403);
            // A normal relationship read can retain a removed worker in MySQL's
            // repeatable-read snapshot. Lock the exact pivot, then give both
            // existing access checks this current evidence until the save commits.
            $careAssignment = DB::table('client_user')->where('client_id', $client->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first(['user_id']);
            $client->setRelation('supportWorkers', new Collection($careAssignment ? [$actor] : []));
        } else {
            $client = $client->fresh();
        }
        abort_unless($actor && $client, 403);
        Gate::forUser($actor)->authorize('view', $client);
        abort_unless(app(ClientProfileSectionAccess::class)->for($actor, $client)['tracking'], 403);
        abort_if($manage && ! ($actor->canDo('fleet.manage') || $actor->canDo('assets.trackers.manage')), 403);
        $assignment = app(PersonalTrackingPrivacyService::class)->authorisedClientAssignment($client);
        abort_unless($assignment, 403, 'Location access has ended.');

        return $assignment;
    }

    public function fingerprint(DeviceAssignment $assignment): string
    {
        return hash_hmac('sha256', json_encode([
            $assignment->id, $assignment->device_id, $assignment->assignable_id,
            $assignment->custody_site_id, $assignment->consent_id,
            $assignment->assigned_at?->toISOString(), $assignment->collection_started_at?->toISOString(),
            $assignment->tracking_purpose, $assignment->authority_basis,
            $assignment->access_audience, $assignment->retention_days,
            $assignment->consent?->updated_at?->toISOString(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function recheck(User $actor, Client $client, string $fingerprint, bool $manage = false, bool $lockedActor = false): DeviceAssignment
    {
        $assignment = $this->resolve($actor, $client, $manage, $lockedActor);
        abort_unless(hash_equals($fingerprint, $this->fingerprint($assignment)), 403, 'The tracking assignment changed. Reload this client.');

        return $assignment;
    }

    public static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff'];
    }
}
