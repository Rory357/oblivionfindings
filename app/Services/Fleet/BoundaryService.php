<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\BoundaryVersion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tracking\BoundaryHandoffService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Canonical geometry commands. Purpose assignments and tracking authority remain separate. */
final class BoundaryService
{
    public function __construct(public readonly SecurityDevicesAccessService $access) {}

    public function actor(User $actor, bool $write = false): User
    {
        $current = $actor->fresh() ?? abort(403);
        abort_unless($write ? $this->canManage($current) : ($current->canDo('fleet.viewAny') || $current->canDo('assets.geofences.manage')), 403);

        return $current;
    }

    public function canManage(User $actor): bool
    {
        return $actor->canDo('fleet.manage') || $actor->canDo('assets.geofences.manage');
    }

    public function resources(User $actor): Builder
    {
        return Asset::query()->where(function (Builder $q) use ($actor): void {
            $q->whereIn('id', $this->access->accessibleAssets($actor)->select('assets.id'));
            if ($actor->canDo('fleet.viewAny')) {
                $q->orWhereIn('id', $this->access->siteScopedVehiclesForFleet($actor)->select('assets.id'));
            }
        });
    }

    public function permitted(User $actor): Builder
    {
        return AssetGeofence::query()->where(function (Builder $q) use ($actor): void {
            $q->whereIn('site_id', $this->access->accessibleSiteIds($actor))
                ->orWhere(fn (Builder $owned) => $owned->whereNull('site_id')->whereIn('asset_id', $this->resources($actor)->select('assets.id')));
        });
    }

    public function resolve(User $actor, int $id, bool $lock = false): AssetGeofence
    {
        return $this->permitted($actor)->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first() ?? abort(404);
    }

    public function present(AssetGeofence $boundary): array
    {
        return [
            'id' => (int) $boundary->id, 'name' => $boundary->name,
            'site_id' => $boundary->site_id, 'site' => $boundary->site?->name,
            'address' => $boundary->address, 'geometry' => VehicleGeofenceRules::fromBoundary($boundary),
            'geometry_version' => (int) $boundary->geometry_version, 'revision' => (int) $boundary->revision,
            'uses' => $boundary->permitted_uses ?? ['Vehicles', 'Assets'],
            'personal_eligible' => $boundary->retired_at === null && ($boundary->client_location_eligible || ($boundary->is_active && in_array($boundary->scope, ['house', 'resident'], true))),
            'retired_at' => $boundary->retired_at?->toIso8601String(),
            'legacy_monitoring' => (bool) $boundary->is_active && ($boundary->asset_id !== null || ($boundary->assigned_assets_exists ?? $boundary->assignedAssets()->exists())),
            'copy_source' => $boundary->copy_source, 'updated_at' => $boundary->updated_at?->toIso8601String(),
        ];
    }

    public function snapshot(AssetGeofence $b): array
    {
        // No related identities, personal names or current rules in immutable geometry evidence.
        return ['id' => (int) $b->id, 'name' => $b->name, 'site_id' => $b->site_id,
            'address' => $b->address, 'geometry' => VehicleGeofenceRules::fromBoundary($b),
            'geometry_version' => (int) $b->geometry_version, 'revision' => (int) $b->revision,
            'uses' => $b->permitted_uses ?? ['Vehicles', 'Assets'],
            'retired_at' => $b->retired_at?->toIso8601String(), 'copy_source' => $b->copy_source];
    }

    public function record(AssetGeofence $b, ?User $actor, string $reason, string $category): void
    {
        BoundaryVersion::query()->create(['boundary_id' => $b->id, 'revision' => $b->revision,
            'geometry_version' => $b->geometry_version, 'category' => $category,
            'snapshot' => $this->snapshot($b), 'actor_id' => $actor?->id,
            'reason' => $reason, 'recorded_at' => now()]);
        AuditLogger::logOrFail('fleet.boundary.'.$category, $b, ['actor_id' => $actor?->id, 'revision' => $b->revision]);
    }

    public function baseline(AssetGeofence $b): void
    {
        if (! BoundaryVersion::query()->where('boundary_id', $b->id)->exists()) {
            $this->record($b, null, 'First retained snapshot of an existing boundary. Earlier geometry and actor are not recorded here.', 'baseline');
        }
    }

    /** Return only an aggregate warning; protected identities never enter Fleet responses. */
    public function impact(AssetGeofence $b): array
    {
        $personal = ($b->site_id !== null && $b->is_active && in_array($b->scope, ['house', 'resident'], true))
            || DB::table('clients')->where('house_geofence_id', $b->id)->exists()
            || DB::table('client_geofence_rule_versions as v')->join('client_geofence_rules as r', 'r.id', '=', 'v.rule_id')
                ->whereColumn('r.current_revision', 'v.revision')->where('r.status', 'draft')
                ->where('v.canonical_geofence_id', $b->id)->exists();
        $legacy = $b->asset_id !== null || $b->assignedAssets()->exists();
        $assignments = DB::table('fleet_vehicle_geofence_assignments')->where('geofence_id', $b->id)->where('state', 'active')->exists();

        return ['protected_dependency' => $personal,
            'geometry_blocked' => $personal || ($legacy && $b->is_active),
            'retirement_blocked' => $personal || $legacy || $assignments,
            'linked_assignments' => $assignments,
            'legacy_monitoring' => $legacy && $b->is_active];
    }

    public function save(User $actor, array $input, ?int $id = null): AssetGeofence
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'], 'address' => ['required', 'string', 'max:500'],
            'site_id' => [$id ? 'sometimes' : 'required', 'integer'],
            'uses' => ['required', 'array', 'min:1', 'max:2'], 'uses.*' => ['required', 'in:Vehicles,Assets', 'distinct'],
            'verified' => ['accepted'], 'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'expected_revision' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'],
            'request_key' => ['required', 'string', 'max:100'],
            'handoff_token' => ['nullable', 'string', 'size:64'],
            'copy_source' => ['nullable', 'array:id,revision'],
            'copy_source.id' => ['required_with:copy_source', 'integer'],
            'copy_source.revision' => ['required_with:copy_source', 'integer'],
        ] + VehicleGeofenceRules::geometryRules())->validate();
        if ($error = VehicleGeofenceRules::geometryProblem($data['geometry'])) {
            VehicleGeofenceRules::fail('geometry', $error);
        }
        $data['geometry'] = VehicleGeofenceRules::normalise($data['geometry']);

        return DB::transaction(function () use ($actor, $data, $id): AssetGeofence {
            // Serialise actor request keys before checking retries, including concurrent creates.
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor = $this->actor($actor, true);
            $handoff = ! empty($data['handoff_token'])
                ? app(BoundaryHandoffService::class)->resolve($actor, $data['handoff_token'], true) : null;
            $hash = MaintenanceFingerprint::of(['id' => $id, 'data' => $data]);
            $prior = DB::table('boundary_requests')->where('actor_id', $actor->id)->where('request_key', $data['request_key'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $hash), 409, 'This save key was already used for different details.');

                return $this->resolve($actor, $prior->boundary_id, true);
            }
            $b = $id ? $this->resolve($actor, $id, true) : new AssetGeofence;
            if ($id) {
                abort_if($b->retired_at, 409, 'This boundary has been retired. Make a new custom copy.');
                abort_unless($b->revision === $data['expected_revision'], 409, 'This boundary changed. Your draft is retained; review the current version before saving.');
                abort_if(isset($data['site_id']) && (int) $data['site_id'] !== (int) $b->site_id, 422, 'The owning site cannot be changed.');
                $this->baseline($b);
                $geometryChanged = VehicleGeofenceRules::fromBoundary($b) !== $data['geometry'];
                $usesChanged = array_diff($b->permitted_uses ?? ['Vehicles', 'Assets'], $data['uses']) !== [];
                abort_if(($geometryChanged || $usesChanged) && $this->impact($b)['geometry_blocked'], 409,
                    'A protected dependency or active monitoring requires its owner’s review before this change. Make a custom copy instead.');
                $b->revision++;
                if ($geometryChanged) {
                    $b->geometry_version++;
                }
            } else {
                abort_unless($this->access->accessibleSites($actor)->whereKey($data['site_id'])->exists(), 404);
                $b->site_id = $data['site_id'];
                if ($handoff) {
                    abort_unless((int) $handoff->site_id === (int) $b->site_id, 403);
                    $b->client_location_eligible = true;
                }
                $b->asset_id = null;
                $b->scope = 'site';
                $b->is_active = false;
                $b->breach_type = 'both';
                $b->geometry_version = $b->revision = 1;
                if (! empty($data['copy_source'])) {
                    $source = $this->resolve($actor, $data['copy_source']['id'], true);
                    $snapshot = BoundaryVersion::query()->where('boundary_id', $source->id)->where('revision', $data['copy_source']['revision'])->first();
                    abort_unless($snapshot || $source->revision === $data['copy_source']['revision'], 409, 'The source version is unavailable.');
                    $b->copy_source = ['id' => $source->id, 'revision' => $data['copy_source']['revision'],
                        'geometry_version' => $snapshot?->geometry_version ?? $source->geometry_version];
                }
            }
            $b->fill(['name' => trim($data['name']), 'address' => trim($data['address']),
                'permitted_uses' => $data['uses'], 'type' => $data['geometry']['type'],
                'shape' => VehicleGeofenceRules::shape($data['geometry'])])->save();
            $this->record($b, $actor, $data['reason'], ! $id ? 'created' : ($geometryChanged ? 'geometry' : 'details'));
            DB::table('boundary_requests')->insert(['actor_id' => $actor->id, 'request_key' => $data['request_key'], 'fingerprint' => $hash, 'boundary_id' => $b->id]);

            return $b;
        }, 3);
    }

    public function retire(User $actor, int $id, int $expected, string $reason): AssetGeofence
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();

        return DB::transaction(function () use ($actor, $id, $expected, $reason): AssetGeofence {
            $actor = $this->actor($actor, true);
            $b = $this->resolve($actor, $id, true);
            abort_unless($b->revision === $expected, 409, 'The boundary changed. Review the current record.');
            abort_if($b->retired_at || $this->impact($b)['retirement_blocked'], 409, 'Linked dependencies must be removed by their owners before retirement.');
            $this->baseline($b);
            $b->forceFill(['retired_at' => now(), 'is_active' => false, 'revision' => $b->revision + 1])->save();
            $this->record($b, $actor, $reason, 'retired');

            return $b;
        }, 3);
    }

    public function legacyLinks(User $actor, int $id, int $expected, string $action, string $reason): AssetGeofence
    {
        Validator::make(compact('action', 'reason'), ['action' => ['required', 'in:pause,unlink'], 'reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();

        return DB::transaction(function () use ($actor, $id, $expected, $action, $reason): AssetGeofence {
            $actor = $this->actor($actor, true);
            $b = $this->resolve($actor, $id, true);
            abort_unless($b->revision === $expected && ! $b->retired_at, 409, 'The area changed. Review its current version.');
            abort_if($this->impact($b)['protected_dependency'], 409, 'A protected location or attendance dependency requires its authorised owner’s review.');
            $ids = DB::table('asset_geofence_assignments')->where('asset_geofence_id', $b->id)->lockForUpdate()->pluck('asset_id');
            if ($b->asset_id) {
                $ids->push($b->asset_id);
            }
            $ids = $ids->unique();
            abort_unless($this->resources($actor)->whereKey($ids)->count() === $ids->count(), 409, 'Some linked resources require another authorised owner’s review.');
            $this->baseline($b);
            if ($action === 'pause') {
                $b->is_active = false;
            } else {
                abort_if($b->is_active, 409, 'Pause existing monitoring before removing the links.');
                if ($b->site_id === null && $b->asset_id) {
                    $asset = $this->resources($actor)->whereKey($b->asset_id)->lockForUpdate()->firstOrFail();
                    $siteId = $asset->site_id ?? $asset->home_site_id;
                    abort_unless($siteId && $this->access->accessibleSites($actor)->whereKey($siteId)->exists(), 409, 'An approved owning site is required before removing the resource link.');
                    $b->site_id = $siteId;
                }
                $b->assignedAssets()->detach();
                $b->asset_id = null;
            }
            // Evaluation state is transient. Removing it is not a location observation.
            DB::table('fleet_geofence_states')->where('geofence_id', $b->id)->delete();
            $b->revision++;
            $b->save();
            $this->record($b, $actor, ($action === 'pause' ? 'Legacy monitoring paused: ' : 'Legacy resource links removed: ').$reason, 'details');

            return $b;
        }, 3);
    }
}
