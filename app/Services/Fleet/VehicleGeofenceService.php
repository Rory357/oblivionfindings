<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetVehicleGeofenceAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A vehicle's geofences on the vehicle profile map.
 *
 * Boundaries are the canonical AssetGeofence records shared with sites,
 * houses and clients. A vehicle links a boundary through an assignment that
 * holds its own purpose, schedule and response proposal, and the boundary
 * version it was reviewed against. Assignments are saved inactive: selecting
 * or creating a boundary here never starts monitoring. The fleet evaluator
 * only reads asset_geofences.asset_id and asset_geofence_assignments; links
 * made there ("Fleet geofences") are shown read-only and stay managed there.
 *
 * Shared geometry is never changed from the vehicle: a new boundary is
 * created inactive and owned by the vehicle's Site, and "Make a custom copy"
 * creates another boundary rather than editing the linked one.
 */
final class VehicleGeofenceService
{
    public const CATALOGUE_LIMIT = 50;

    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function canManage(User $user): bool
    {
        return $user->canDo('fleet.manage') || $user->canDo('assets.geofences.manage');
    }

    /** The vehicle, when the viewer may open its profile. */
    public function vehicle(User $user, int $assetId, bool $lock = false): Asset
    {
        abort_unless($user->canDo('fleet.viewAny'), 403);

        return $this->access->assignableVehicle($user, $assetId, $lock) ?? abort(404);
    }

    /** Boundaries this viewer may see or link for this vehicle. */
    public function permittedBoundaries(User $user, Asset $vehicle): Builder
    {
        $siteIds = $this->access->accessibleSiteIds($user);

        return AssetGeofence::query()->where(function (Builder $scope) use ($siteIds, $user, $vehicle): void {
            if ($siteIds === []) {
                $scope->whereRaw('1 = 0');
            } else {
                $scope->whereIn('site_id', $siteIds);
            }
            // Vehicle-owned boundaries without a Site follow their vehicle's access.
            $scope->orWhere(fn (Builder $owned) => $owned->whereNull('site_id')->where(fn (Builder $vehicles) => $vehicles
                ->where('asset_id', $vehicle->getKey())
                ->orWhereIn('asset_id', $this->access->accessibleVehiclesForFleet($user)->select('assets.id'))));
        });
    }

    /**
     * Shared boundaries matching a search, for "Select existing" and the wizard.
     *
     * @return array{boundaries:list<array<string,mixed>>,truncated:bool}
     */
    public function catalogue(User $user, Asset $vehicle, string $search): array
    {
        $search = trim(mb_substr($search, 0, 100));
        $rows = $this->permittedBoundaries($user, $vehicle)
            ->with(['site:id,name', 'asset:id,name'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $like)
                    ->orWhereHas('site', fn (Builder $site) => $site->where('name', 'like', $like))
                    ->when(ctype_digit($search), fn (Builder $id) => $id->orWhere('asset_geofences.id', (int) $search)));
            })
            ->orderBy('name')->orderBy('id')
            ->limit(self::CATALOGUE_LIMIT + 1)
            ->get();
        $links = $this->fleetLinks($vehicle);
        $assigned = FleetVehicleGeofenceAssignment::query()->active()->where('asset_id', $vehicle->getKey())
            ->whereNotNull('geofence_id')->pluck('id', 'geofence_id');

        return [
            'boundaries' => $rows->take(self::CATALOGUE_LIMIT)->map(fn (AssetGeofence $fence): array => $this->boundary($fence) + [
                'is_active' => (bool) $fence->is_active,
                'assignment_id' => isset($assigned[$fence->id]) ? (int) $assigned[$fence->id] : null,
                'fleet_link' => isset($links[$fence->id]),
            ])->values()->all(),
            'truncated' => $rows->count() > self::CATALOGUE_LIMIT,
        ];
    }

    /**
     * Everything linked to this vehicle, with the selection's version.
     *
     * @return array<string,mixed>
     */
    public function linked(User $user, Asset $vehicle): array
    {
        $assignments = FleetVehicleGeofenceAssignment::query()->active()->where('asset_id', $vehicle->getKey())
            ->with(['geofence.site:id,name', 'geofence.asset:id,name', 'updatedBy:id,name', 'createdBy:id,name'])
            ->orderBy('id')->get();
        $links = $this->fleetLinks($vehicle);
        $permitted = $this->permittedIds($user, $vehicle, [
            ...$assignments->pluck('geofence_id')->filter()->all(),
            ...array_keys($links),
        ]);

        $items = $assignments->map(fn (FleetVehicleGeofenceAssignment $assignment): array => $this->presentAssignment(
            $assignment, $permitted, $links,
        ))->all();
        $covered = $assignments->pluck('geofence_id')->filter()->map(fn ($id): int => (int) $id)->all();
        $fleetOnly = array_diff(array_keys($links), $covered);
        if ($fleetOnly !== []) {
            $fences = AssetGeofence::query()->whereKey($fleetOnly)->with(['site:id,name', 'asset:id,name'])->orderBy('name')->get();
            foreach ($fences as $fence) {
                $visible = isset($permitted[$fence->id]);
                $items[] = [
                    'key' => 'fleet:'.$fence->id,
                    'assignment_id' => null,
                    'geofence_id' => (int) $fence->id,
                    'label' => $visible ? (string) $fence->name : 'Boundary managed at another site',
                    'origin' => 'fleet_rule',
                    'boundary' => $visible ? $this->boundary($fence) : null,
                    'source_state' => $visible ? 'current' : 'restricted',
                    'purpose' => null,
                    'response_proposal' => null,
                    'schedule' => null,
                    'monitoring' => $links[$fence->id] ? 'on' : 'paused',
                    'lock_version' => null,
                    'saved_at' => $fence->updated_at?->toIso8601String(),
                    'saved_by' => null,
                ];
            }
        }

        return [
            'items' => array_values($items),
            'version' => $this->selectionVersion($assignments),
            'owner_site' => $this->ownerSite($user, $vehicle),
            'can' => ['manage' => $this->canManage($user)],
        ];
    }

    /**
     * "Select existing": keep the chosen links and add newly chosen boundaries.
     * Replaying a selection that already applied succeeds without a change.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function select(User $actor, int $assetId, array $data): array
    {
        Validator::make($data, [
            'keep_assignment_ids' => ['present', 'array', 'max:500'],
            'keep_assignment_ids.*' => ['integer', 'min:1', 'distinct'],
            'add_geofence_ids' => ['present', 'array', 'max:100'],
            'add_geofence_ids.*' => ['integer', 'min:1', 'distinct'],
            'expected_version' => ['required', 'string', 'size:64'],
        ])->validate();
        $keep = array_map('intval', $data['keep_assignment_ids']);
        $requested = array_map('intval', $data['add_geofence_ids']);

        return DB::transaction(function () use ($actor, $assetId, $keep, $requested, $data): array {
            $current = $this->manager($actor);
            $vehicle = $this->vehicle($current, $assetId, true);
            $assignments = FleetVehicleGeofenceAssignment::query()->active()->where('asset_id', $vehicle->id)
                ->orderBy('id')->lockForUpdate()->get();
            $links = $this->fleetLinks($vehicle);
            $assignedBoundaries = $assignments->pluck('geofence_id')->filter()->map(fn ($id): int => (int) $id)->all();
            // Kept: chosen links, boundaries this request asks for (a retry after
            // they were linked), and links owned by Fleet geofences (shown locked).
            $remove = $assignments->reject(fn (FleetVehicleGeofenceAssignment $assignment): bool => in_array($assignment->id, $keep, true)
                || ($assignment->geofence_id !== null && (in_array((int) $assignment->geofence_id, $requested, true)
                    || isset($links[(int) $assignment->geofence_id]))));
            $add = array_values(array_diff($requested, $assignedBoundaries, array_keys($links)));

            if ($remove->isEmpty() && $add === []) {
                return $this->linked($current, $vehicle);
            }
            abort_unless(hash_equals($this->selectionVersion($assignments), (string) $data['expected_version']), 409,
                'The linked geofences changed while you were choosing. Review the latest list and try again.');

            $fences = $add === [] ? new Collection : $this->permittedBoundaries($current, $vehicle)
                ->whereKey($add)->lockForUpdate()->get()->keyBy('id');
            foreach ($add as $geofenceId) {
                $field = 'add_geofence_ids.'.array_search($geofenceId, $requested, true);
                $fence = $fences->get($geofenceId);
                if (! $fence) {
                    VehicleGeofenceRules::fail($field, 'This boundary is not available to you. Refresh the list and choose again.');
                }
                if (VehicleGeofenceRules::fromBoundary($fence) === null) {
                    VehicleGeofenceRules::fail($field, "{$fence->name} has an incomplete shape. Fix it in Fleet geofences before linking it.");
                }
            }

            $removedIds = [];
            foreach ($remove as $assignment) {
                $assignment->forceFill([
                    'state' => FleetVehicleGeofenceAssignment::STATE_REMOVED,
                    'removed_at' => now(),
                    'removed_by_user_id' => $current->id,
                    'removal_reason' => 'Removed from the vehicle’s geofence selection.',
                    'lock_version' => $assignment->lock_version + 1,
                ])->save();
                $removedIds[] = $assignment->id;
            }
            $addedIds = [];
            foreach ($add as $geofenceId) {
                $fence = $fences->get($geofenceId);
                $assignment = FleetVehicleGeofenceAssignment::query()->create([
                    'asset_id' => $vehicle->id,
                    'geofence_id' => $fence->id,
                    'label' => mb_substr((string) $fence->name, 0, 120),
                    'origin' => FleetVehicleGeofenceAssignment::ORIGIN_LINKED,
                    'geometry_hash' => VehicleGeofenceRules::boundaryHash($fence),
                    'geometry_snapshot' => VehicleGeofenceRules::fromBoundary($fence),
                    'monitoring' => 'inactive',
                    'state' => FleetVehicleGeofenceAssignment::STATE_ACTIVE,
                    'lock_version' => 1,
                    'created_by_user_id' => $current->id,
                    'updated_by_user_id' => $current->id,
                ]);
                $addedIds[] = $assignment->id;
            }
            AuditLogger::logOrFail('fleet.vehicle.geofence.selection', $vehicle, [
                'asset_id' => $vehicle->id,
                'added_assignment_ids' => $addedIds,
                'added_geofence_ids' => $add,
                'removed_assignment_ids' => $removedIds,
                'monitoring' => 'inactive',
            ]);

            return $this->linked($current, $vehicle);
        }, 3);
    }

    /**
     * Create an inactive assignment from the wizard: link an existing boundary,
     * or draw a new shared boundary owned by the vehicle's Site.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(User $actor, int $assetId, array $data, string $requestKey): FleetVehicleGeofenceAssignment
    {
        abort_if($requestKey === '', 422, 'A request key is required.');
        $source = (string) ($data['source'] ?? '');
        $values = VehicleGeofenceRules::validate($data, [
            'source' => ['required', 'in:existing,new'],
            'geofence_id' => ['required_if:source,existing', 'prohibited_if:source,new', 'nullable', 'integer', 'min:1'],
            'geometry_hash' => ['required_if:source,existing', 'prohibited_if:source,new', 'nullable', 'string', 'size:64'],
        ], $source === 'new');
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'asset' => $assetId, 'source' => $source,
            'geofence_id' => $source === 'existing' ? (int) $data['geofence_id'] : null,
            'geometry_hash' => $source === 'existing' ? (string) $data['geometry_hash'] : null,
        ] + $values);

        return DB::transaction(function () use ($actor, $assetId, $data, $requestKey, $source, $values, $fingerprint): FleetVehicleGeofenceAssignment {
            $current = $this->manager($actor);
            $vehicle = $this->vehicle($current, $assetId, true);
            $existing = FleetVehicleGeofenceAssignment::query()->where('asset_id', $vehicle->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different geofence. Reload and try again.');

                return $existing;
            }

            if ($source === 'existing') {
                $fence = $this->permittedBoundaries($current, $vehicle)->whereKey((int) $data['geofence_id'])
                    ->lockForUpdate()->first();
                if (! $fence) {
                    VehicleGeofenceRules::fail('geofence_id', 'This boundary is not available to you. Choose another boundary.');
                }
                abort_unless(hash_equals(VehicleGeofenceRules::boundaryHash($fence), (string) $data['geometry_hash']), 409,
                    'This boundary changed after you reviewed it. Select it again to review its current version.');
                if (VehicleGeofenceRules::fromBoundary($fence) === null) {
                    VehicleGeofenceRules::fail('geofence_id', 'This boundary has an incomplete shape. Fix it in Fleet geofences before linking it.');
                }
                $this->assertNotMonitored($vehicle, $fence);
                // A locking read: the snapshot may predate a concurrent link.
                if (FleetVehicleGeofenceAssignment::query()->active()->where('asset_id', $vehicle->id)
                    ->where('geofence_id', $fence->id)->lockForUpdate()->first(['id']) !== null) {
                    VehicleGeofenceRules::fail('geofence_id', 'This boundary is already linked to the vehicle. Use Manage to change its assignment.');
                }
                $origin = FleetVehicleGeofenceAssignment::ORIGIN_LINKED;
            } else {
                $fence = $this->createBoundary($current, $vehicle, $values['label'], $values['geometry'], 'vehicle_profile');
                $origin = FleetVehicleGeofenceAssignment::ORIGIN_CREATED;
            }

            $assignment = FleetVehicleGeofenceAssignment::query()->create([
                'asset_id' => $vehicle->id,
                'geofence_id' => $fence->id,
                'label' => $values['label'],
                'origin' => $origin,
                'purpose' => $values['purpose'],
                'response_proposal' => $values['response_proposal'],
                'schedule' => $values['schedule'],
                'geometry_hash' => VehicleGeofenceRules::boundaryHash($fence),
                'geometry_snapshot' => VehicleGeofenceRules::fromBoundary($fence),
                'monitoring' => 'inactive',
                'state' => FleetVehicleGeofenceAssignment::STATE_ACTIVE,
                'lock_version' => 1,
                'payload_hash' => $this->payloadHash($values, $fence),
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
                'created_by_user_id' => $current->id,
                'updated_by_user_id' => $current->id,
            ]);
            AuditLogger::logOrFail('fleet.vehicle.geofence.assign', $assignment, [
                'asset_id' => $vehicle->id,
                'geofence_id' => $fence->id,
                'origin' => $origin,
                'label' => $values['label'],
                'schedule' => $values['schedule'],
                'monitoring' => 'inactive',
            ]);

            return $assignment;
        }, 3);
    }

    /**
     * Manage an assignment: its name, purpose, response and schedule, a review
     * of a changed source, a different shared boundary, or a custom copy of
     * the linked geometry.
     *
     * @param  array<string,mixed>  $data
     */
    public function update(User $actor, int $assetId, int $assignmentId, array $data): FleetVehicleGeofenceAssignment
    {
        $source = (string) ($data['source'] ?? '');
        $values = VehicleGeofenceRules::validate($data, [
            'source' => ['required', 'in:keep,existing,copy'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'geofence_id' => ['required_if:source,existing', 'prohibited_unless:source,existing', 'nullable', 'integer', 'min:1'],
            'geometry_hash' => ['required_unless:source,copy', 'prohibited_if:source,copy', 'nullable', 'string', 'size:64'],
            'source_change_reviewed' => ['sometimes', 'boolean'],
        ], $source === 'copy');
        $expected = (int) $data['expected_version'];

        return DB::transaction(function () use ($actor, $assetId, $assignmentId, $data, $source, $values, $expected): FleetVehicleGeofenceAssignment {
            $current = $this->manager($actor);
            $vehicle = $this->vehicle($current, $assetId, true);
            $assignment = FleetVehicleGeofenceAssignment::query()->where('asset_id', $vehicle->id)
                ->whereKey($assignmentId)->lockForUpdate()->first() ?? abort(404);
            abort_unless($assignment->state === FleetVehicleGeofenceAssignment::STATE_ACTIVE, 409,
                'This geofence is no longer linked to the vehicle. Reload the map.');
            $fence = $assignment->geofence_id === null ? null
                : AssetGeofence::query()->whereKey($assignment->geofence_id)->lockForUpdate()->first();
            if ($fence) {
                $this->assertNotMonitored($vehicle, $fence);
            }
            // A retried save that already landed reports success, not a conflict.
            if ($assignment->lock_version === $expected + 1 && $fence
                && hash_equals((string) $assignment->payload_hash, $this->payloadHash($values, $fence))
                && ($source !== 'existing' || (int) $assignment->geofence_id === (int) $data['geofence_id'])
                && ($source !== 'copy' || $assignment->origin === FleetVehicleGeofenceAssignment::ORIGIN_COPIED)) {
                return $assignment;
            }
            abort_unless($assignment->lock_version === $expected, 409,
                'This geofence assignment changed while you were editing. Review its latest version before saving.');

            $before = $this->auditState($assignment);
            if ($source === 'existing' && (int) $data['geofence_id'] !== (int) $assignment->geofence_id) {
                $next = $this->permittedBoundaries($current, $vehicle)->whereKey((int) $data['geofence_id'])
                    ->lockForUpdate()->first();
                if (! $next) {
                    VehicleGeofenceRules::fail('geofence_id', 'This boundary is not available to you. Choose another boundary.');
                }
                abort_unless(hash_equals(VehicleGeofenceRules::boundaryHash($next), (string) $data['geometry_hash']), 409,
                    'This boundary changed after you reviewed it. Select it again to review its current version.');
                if (VehicleGeofenceRules::fromBoundary($next) === null) {
                    VehicleGeofenceRules::fail('geofence_id', 'This boundary has an incomplete shape. Fix it in Fleet geofences before linking it.');
                }
                $this->assertNotMonitored($vehicle, $next);
                if (FleetVehicleGeofenceAssignment::query()->active()->where('asset_id', $vehicle->id)
                    ->where('geofence_id', $next->id)->whereKeyNot($assignment->id)->lockForUpdate()->first(['id']) !== null) {
                    VehicleGeofenceRules::fail('geofence_id', 'This boundary is already linked to the vehicle. Choose another boundary.');
                }
                $fence = $next;
                $origin = FleetVehicleGeofenceAssignment::ORIGIN_LINKED;
            } elseif ($source === 'keep' || $source === 'existing') {
                $visible = $fence !== null && $this->permittedBoundaries($current, $vehicle)->whereKey($fence->id)->exists();
                abort_unless($visible, 409,
                    'The linked boundary is no longer available. Make a custom copy or remove it from the selection.');
                $hash = VehicleGeofenceRules::boundaryHash($fence);
                abort_unless(hash_equals($hash, (string) $data['geometry_hash']), 409,
                    'The linked boundary changed after you reviewed it. Reload and review its current version.');
                if (! hash_equals($hash, (string) $assignment->geometry_hash)
                    && ! filter_var($data['source_change_reviewed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    VehicleGeofenceRules::fail('source_change_reviewed', 'Review the changed boundary before saving this assignment.');
                }
                $origin = $assignment->origin;
            } else {
                $snapshot = is_array($assignment->geometry_snapshot) ? $assignment->geometry_snapshot : null;
                $fence = $this->createBoundary($current, $vehicle, $values['label'], $values['geometry'], 'vehicle_profile_copy',
                    $assignment->geofence_id === null ? null : (int) $assignment->geofence_id, $snapshot);
                $origin = FleetVehicleGeofenceAssignment::ORIGIN_COPIED;
            }

            $assignment->forceFill([
                'geofence_id' => $fence->id,
                'label' => $values['label'],
                'origin' => $origin,
                'purpose' => $values['purpose'],
                'response_proposal' => $values['response_proposal'],
                'schedule' => $values['schedule'],
                'geometry_hash' => VehicleGeofenceRules::boundaryHash($fence),
                'geometry_snapshot' => VehicleGeofenceRules::fromBoundary($fence),
                'lock_version' => $assignment->lock_version + 1,
                'payload_hash' => $this->payloadHash($values, $fence),
                'updated_by_user_id' => $current->id,
            ])->save();
            AuditLogger::logOrFail('fleet.vehicle.geofence.update', $assignment, [
                'asset_id' => $vehicle->id,
                'source' => $source,
                'before' => $before,
                'after' => $this->auditState($assignment),
                'monitoring' => 'inactive',
            ]);

            return $assignment;
        }, 3);
    }

    /**
     * One assignment as the map shows it.
     *
     * @param  array<int,true>  $permitted  Boundary ids this viewer may see.
     * @param  array<int,bool>  $links  Fleet geofence links (true = monitoring on).
     * @return array<string,mixed>
     */
    public function presentAssignment(FleetVehicleGeofenceAssignment $assignment, array $permitted, array $links): array
    {
        $fence = $assignment->geofence;
        $visible = $fence !== null && isset($permitted[$fence->id]);
        $state = match (true) {
            $fence === null => 'removed',
            ! $visible => 'restricted',
            ! hash_equals(VehicleGeofenceRules::boundaryHash($fence), (string) $assignment->geometry_hash) => 'changed',
            default => 'current',
        };
        $monitoring = $fence !== null && isset($links[$fence->id])
            ? ($links[$fence->id] ? 'on' : 'paused')
            : 'inactive';

        return [
            'key' => 'assignment:'.$assignment->id,
            'assignment_id' => (int) $assignment->id,
            'geofence_id' => $fence ? (int) $fence->id : null,
            'label' => (string) $assignment->label,
            'origin' => (string) $assignment->origin,
            'boundary' => $visible ? $this->boundary($fence) : null,
            'source_state' => $state,
            // The version this assignment was reviewed against.
            'reviewed_geometry' => $state === 'restricted' ? null : $assignment->geometry_snapshot,
            'purpose' => $assignment->purpose,
            'response_proposal' => $assignment->response_proposal,
            'schedule' => $assignment->schedule,
            'monitoring' => $monitoring,
            'lock_version' => (int) $assignment->lock_version,
            'saved_at' => $assignment->updated_at?->toIso8601String(),
            'saved_by' => $assignment->updatedBy?->name ?? $assignment->createdBy?->name,
        ];
    }

    /**
     * A shared boundary for the map and pickers.
     *
     * @return array<string,mixed>
     */
    public function boundary(AssetGeofence $fence): array
    {
        return [
            'id' => (int) $fence->id,
            'name' => (string) $fence->name,
            'type' => (string) $fence->type,
            'scope' => (string) ($fence->scope ?? 'vehicle'),
            'site' => $fence->site ? ['id' => (int) $fence->site->id, 'name' => (string) $fence->site->name] : null,
            'vehicle' => $fence->site_id === null && $fence->asset
                ? ['id' => (int) $fence->asset->id, 'name' => (string) $fence->asset->name] : null,
            'geometry' => VehicleGeofenceRules::fromBoundary($fence),
            'hash' => VehicleGeofenceRules::boundaryHash($fence),
        ];
    }

    /**
     * Boundaries the fleet evaluator monitors (or would, if active) for this
     * vehicle: its own boundaries and the ones it is assigned to in Fleet
     * geofences. Value is true when the boundary is active.
     *
     * @return array<int,bool>
     */
    public function fleetLinks(Asset $vehicle): array
    {
        $links = AssetGeofence::query()->where('asset_id', $vehicle->getKey())->pluck('is_active', 'id')->all();
        $assigned = AssetGeofence::query()->whereHas('assignedAssets', fn (Builder $assets) => $assets->whereKey($vehicle->getKey()))
            ->pluck('is_active', 'id')->all();
        $all = [];
        foreach ($links + $assigned as $id => $active) {
            $all[(int) $id] = (bool) $active;
        }

        return $all;
    }

    /** The Site that owns a boundary created from this vehicle, when the viewer may use it. */
    public function ownerSite(User $user, Asset $vehicle): ?array
    {
        $siteId = $vehicle->site_id ?? $vehicle->home_site_id ?? null;
        if ($siteId === null || ! in_array((int) $siteId, $this->access->accessibleSiteIds($user), true)) {
            return null;
        }
        $site = $this->access->accessibleSites($user)->whereKey((int) $siteId)->first(['id', 'name']);

        return $site ? ['id' => (int) $site->id, 'name' => (string) $site->name] : null;
    }

    private function createBoundary(User $actor, Asset $vehicle, string $name, array $geometry, string $context, ?int $copiedFrom = null, ?array $copiedGeometry = null): AssetGeofence
    {
        $owner = $this->ownerSite($actor, $vehicle);
        if ($owner === null) {
            VehicleGeofenceRules::fail('geometry', 'A new shared boundary needs the vehicle’s Site, and you need access to it. Link an existing boundary instead.');
        }
        $fence = AssetGeofence::query()->create([
            'asset_id' => null,
            'site_id' => $owner['id'],
            'name' => $name,
            'type' => $geometry['type'],
            'scope' => 'vehicle',
            'shape' => VehicleGeofenceRules::shape($geometry),
            'breach_type' => 'both',
            'alert_config' => null,
            'time_rules' => null,
            // Created for an inactive assignment: nothing monitors it yet.
            'is_active' => false,
        ]);
        $fence->refresh();
        AuditLogger::logOrFail('fleet.geofence.create', $fence, [
            'site_id' => $owner['id'],
            'name' => $name,
            'context' => $context,
            'vehicle_id' => $vehicle->id,
            'copied_from_geofence_id' => $copiedFrom,
            'copied_from_shape' => $copiedGeometry['type'] ?? null,
            'is_active' => false,
        ]);

        return $fence;
    }

    private function assertNotMonitored(Asset $vehicle, AssetGeofence $fence): void
    {
        $links = $this->fleetLinks($vehicle);
        abort_if(($links[$fence->id] ?? false) === true, 409,
            'Monitoring is on for this boundary in Fleet geofences. Pause it there before changing this vehicle’s assignment.');
    }

    private function manager(User $actor): User
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canManage($current), 403);

        return $current;
    }

    /**
     * @param  list<int|string|null>  $ids
     * @return array<int,true>
     */
    private function permittedIds(User $user, Asset $vehicle, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return $this->permittedBoundaries($user, $vehicle)->whereKey($ids)->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
    }

    /** @param  Collection<int,FleetVehicleGeofenceAssignment>|\Illuminate\Support\Collection<int,FleetVehicleGeofenceAssignment>  $assignments */
    private function selectionVersion($assignments): string
    {
        return MaintenanceFingerprint::of($assignments->sortBy('id')->map(fn (FleetVehicleGeofenceAssignment $assignment): array => [
            (int) $assignment->id, (int) $assignment->lock_version, $assignment->geofence_id === null ? null : (int) $assignment->geofence_id,
        ])->values()->all());
    }

    /** @param  array<string,mixed>  $values */
    private function payloadHash(array $values, AssetGeofence $fence): string
    {
        return MaintenanceFingerprint::of([
            'label' => $values['label'],
            'purpose' => $values['purpose'],
            'response_proposal' => $values['response_proposal'],
            'schedule' => $values['schedule'],
            'geometry' => VehicleGeofenceRules::fromBoundary($fence),
        ]);
    }

    /** @return array<string,mixed> */
    private function auditState(FleetVehicleGeofenceAssignment $assignment): array
    {
        return [
            'geofence_id' => $assignment->geofence_id,
            'label' => $assignment->label,
            'origin' => $assignment->origin,
            'purpose' => $assignment->purpose,
            'response_proposal' => $assignment->response_proposal,
            'schedule' => $assignment->schedule,
            'geometry_hash' => $assignment->geometry_hash,
        ];
    }
}
