<?php

namespace App\Services\Tracking;

use App\Models\Client;
use App\Models\User;
use App\Services\Fleet\BoundaryService;
use App\Services\Fleet\VehicleGeofenceRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Opaque, same-actor return channel. It conveys geometry, never tracking authority. */
final class BoundaryHandoffService
{
    public function __construct(private readonly ClientLocationAccessService $access, private readonly ClientLocationZoneDraftService $drafts) {}

    public function start(User $actor, Client $client, string $fingerprint): array
    {
        $actor = app(BoundaryService::class)->actor($actor);
        $assignment = $this->access->recheck($actor, $client, $fingerprint, true);
        $token = Str::random(64);
        DB::table('boundary_handoffs')->insert(['token_hash' => hash('sha256', $token), 'actor_id' => $actor->id,
            'client_id' => $client->id, 'access_fingerprint' => $fingerprint, 'site_id' => $assignment->custody_site_id,
            'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

        return ['token' => $token, 'href' => '/fleet-assets/geofences?handoff='.$token.'&tab=boundaries', 'expires_at' => now()->addMinutes(15)->toIso8601String()];
    }

    public function resolve(User $actor, string $token, bool $lock = false): object
    {
        abort_unless(strlen($token) === 64, 404);
        $h = DB::table('boundary_handoffs')->where('token_hash', hash('sha256', $token))->where('actor_id', $actor->id)
            ->where('expires_at', '>', now())->when($lock, fn ($q) => $q->lockForUpdate())->first() ?? abort(404, 'This return request expired or is unavailable. Your person draft is still in its original workspace.');
        $client = Client::findOrFail($h->client_id);
        $assignment = $this->access->recheck($actor, $client, $h->access_fingerprint, true);
        abort_unless((int) $assignment->custody_site_id === (int) $h->site_id, 403);

        return $h;
    }

    public function select(User $actor, string $token, int $id, int $revision): array
    {
        return DB::transaction(function () use ($actor, $token, $id, $revision): array {
            $h = $this->resolve($actor, $token, true);
            abort_if($h->consumed_at !== null, 409, 'This selection has already returned to its person draft. Start a new return request to choose another area.');
            $b = $this->access->eligibleBoundaries(Client::findOrFail($h->client_id), $h->site_id)->whereKey($id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($b->revision === $revision, 409, 'The area changed. Review its current geometry before returning it.');
            DB::table('boundary_handoffs')->where('id', $h->id)->update(['boundary_id' => $b->id,
                'geometry_hash' => $this->drafts->geometryHash($b), 'updated_at' => now()]);

            return ['ready' => true];
        }, 3);
    }

    public function take(User $actor, Client $client, string $token): array
    {
        return DB::transaction(function () use ($actor, $client, $token): array {
            $h = $this->resolve($actor, $token, true);
            abort_unless((int) $h->client_id === (int) $client->id, 404);
            if (! $h->boundary_id) {
                return ['ready' => false];
            }
            $b = $this->access->eligibleBoundaries($client, $h->site_id)->whereKey($h->boundary_id)->lockForUpdate()->first() ?? abort(409, 'This area is no longer eligible. Your draft is retained.');
            abort_unless(hash_equals($h->geometry_hash, $this->drafts->geometryHash($b)), 409, 'The area changed after selection. Review it again; your draft is retained.');
            DB::table('boundary_handoffs')->where('id', $h->id)->update(['consumed_at' => now()]);

            return ['ready' => true, 'boundary' => ['id' => $b->id, 'name' => $b->name,
                'geometry' => VehicleGeofenceRules::fromBoundary($b),
                'hash' => $h->geometry_hash, 'geometry_version' => $b->geometry_version, 'revision' => $b->revision, 'copy_source' => $b->copy_source]];
        }, 3);
    }

    public function cancel(User $actor, Client $client, string $token): void
    {
        // Cancellation must also work after consent is withdrawn. Ownership is sufficient
        // to expire this opaque channel; no geometry or personal record is returned.
        abort_unless(strlen($token) === 64, 404);
        DB::table('boundary_handoffs')->where('token_hash', hash('sha256', $token))
            ->where('actor_id', $actor->id)->where('client_id', $client->id)
            ->update(['expires_at' => now(), 'updated_at' => now()]);
    }
}
