<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetDrivingScorePolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SchemaCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The versioned trip scoring policy.
 *
 * Version 1 is the fleet configuration (config/fleet.php behaviour
 * settings). A published version supersedes its weights and coverage minimum
 * on every surface that scores trips (trip history, exports and driving
 * insights) through TripBehaviourAnalyzer::policy(), and the earlier
 * versions stay as history. The minimum confirmed trips and distance for a
 * person's score exist only in a published version: until one is published,
 * no score is attributed to a person.
 */
final class DrivingScorePolicyStore
{
    public function __construct(private readonly SecurityDevicesAccessService $vehicles) {}

    /** The latest published version, or null when the fleet settings apply. */
    public static function published(): ?FleetDrivingScorePolicy
    {
        if (! SchemaCache::hasTable('fleet_driving_score_policies')) {
            return null;
        }

        return FleetDrivingScorePolicy::query()->orderByDesc('version')->first();
    }

    /**
     * Overlay the published version on the analyzer's configured policy.
     *
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public static function apply(array $policy): array
    {
        $published = self::published();
        if ($published === null) {
            return $policy + ['version' => 1];
        }
        $acceleration = (float) $published->acceleration_weight;
        $policy['weights'] = [
            'braking' => (float) $published->braking_weight,
            'acceleration' => $acceleration,
            // Cornering and unclassified harsh reports take the acceleration weight.
            'other_harsh' => $acceleration,
            'overspeed' => (float) $published->overspeed_weight,
            'idle_per_minute' => (float) $published->idle_weight,
        ];
        $policy['min_score_coverage_pct'] = (int) $published->min_coverage_pct;
        $policy['version'] = (int) $published->version;

        return $policy;
    }

    /**
     * The current policy as the Driving insights views describe it.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        $policy = TripBehaviourAnalyzer::policy();
        $published = self::published()?->loadMissing('publishedBy:id,name');

        return [
            'version' => (int) ($policy['version'] ?? 1),
            'source' => $published ? 'published' : 'fleet_settings',
            'weights' => $policy['weights'],
            'min_score_coverage_pct' => (int) $policy['min_score_coverage_pct'],
            'min_trips' => $published?->min_trips,
            'min_distance_km' => $published?->min_distance_km,
            'speed_threshold_kph' => (float) $policy['speed_threshold_kph'],
            'coverage_gap_seconds' => (int) $policy['coverage_gap_seconds'],
            'published_at' => $published?->created_at?->toIso8601String(),
            'published_by' => $published?->publishedBy?->name,
            'reason' => $published?->reason,
            'history' => $this->history(),
        ];
    }

    /** @return list<array<string,mixed>> Superseded versions, newest first. */
    public function history(): array
    {
        if (! SchemaCache::hasTable('fleet_driving_score_policies')) {
            return [];
        }
        $rows = FleetDrivingScorePolicy::query()->with('publishedBy:id,name')->orderByDesc('version')->limit(50)->get();

        return $rows->map(fn (FleetDrivingScorePolicy $row): array => [
            'version' => (int) $row->version,
            'braking' => (float) $row->braking_weight,
            'acceleration' => (float) $row->acceleration_weight,
            'overspeed' => (float) $row->overspeed_weight,
            'idle' => (float) $row->idle_weight,
            'min_coverage_pct' => (int) $row->min_coverage_pct,
            'min_trips' => (int) $row->min_trips,
            'min_distance_km' => (float) $row->min_distance_km,
            'reason' => $row->reason,
            'published_by' => $row->publishedBy?->name,
            'published_at' => $row->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * The policy scores every driver at every Site, so publishing it is a
     * fleet-wide setting rather than part of one vehicle's management.
     */
    public function canPublish(User $user): bool
    {
        return $user->canDo('fleet.manage') && $user->canDo('fleet.settings.manage');
    }

    /**
     * Publish a new version. The expected version guards against two people
     * publishing over each other; a retry of the same request returns the
     * version it published.
     *
     * @param  array<string,mixed>  $data
     */
    public function publish(User $actor, int $assetId, array $data, string $requestKey): FleetDrivingScorePolicy
    {
        self::assertKey($requestKey);
        Validator::make($data, [
            'braking' => ['required', 'numeric', 'min:0', 'max:100'],
            'acceleration' => ['required', 'numeric', 'min:0', 'max:100'],
            'overspeed' => ['required', 'numeric', 'min:0', 'max:100'],
            'idle' => ['required', 'numeric', 'min:0', 'max:100'],
            'coverage' => ['required', 'integer', 'min:50', 'max:100'],
            'trips' => ['required', 'integer', 'min:1', 'max:1000'],
            'distance' => ['required', 'numeric', 'min:1', 'max:100000'],
            'reason' => ['required', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ], [
            'coverage.min' => 'Use a minimum coverage between 50% and 100%.',
            'coverage.max' => 'Use a minimum coverage between 50% and 100%.',
            'trips.min' => 'Use a positive whole number of trips.',
            'distance.min' => 'Use a positive distance.',
            'reason.required' => 'Record why this version is needed.',
            'confirmed.accepted' => 'Confirm that you reviewed the scoring impact.',
        ], [
            'braking' => 'braking points', 'acceleration' => 'acceleration points', 'overspeed' => 'overspeed points',
            'idle' => 'points per idle minute', 'coverage' => 'minimum coverage', 'trips' => 'minimum trips',
            'distance' => 'minimum distance',
        ])->validate();
        $values = [
            'braking_weight' => round((float) $data['braking'], 2),
            'acceleration_weight' => round((float) $data['acceleration'], 2),
            'overspeed_weight' => round((float) $data['overspeed'], 2),
            'idle_weight' => round((float) $data['idle'], 2),
            'min_coverage_pct' => (int) $data['coverage'],
            'min_trips' => (int) $data['trips'],
            'min_distance_km' => round((float) $data['distance'], 1),
            'reason' => trim((string) $data['reason']),
        ];
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'policy' => $values]);

        try {
            return DB::transaction(function () use ($actor, $assetId, $values, $data, $requestKey, $fingerprint): FleetDrivingScorePolicy {
                $current = User::query()->findOrFail($actor->id);
                abort_unless($this->canPublish($current), 403);
                $vehicle = $this->vehicles->assignableVehicle($current, $assetId, true) ?? abort(404);
                $prior = FleetDrivingScorePolicy::query()->where('published_by_user_id', $current->id)
                    ->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior) {
                    abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                        'This request was already used for a different policy.');

                    return $prior;
                }
                $latest = FleetDrivingScorePolicy::query()->orderByDesc('version')->lockForUpdate()->first();
                $currentVersion = $latest ? (int) $latest->version : 1;
                abort_unless((int) $data['expected_version'] === $currentVersion, 409,
                    'The scoring policy changed while you were reviewing it. Reload to see the current version.');
                $policy = FleetDrivingScorePolicy::query()->create($values + [
                    'version' => $currentVersion + 1,
                    'asset_id' => $vehicle->id,
                    'published_by_user_id' => $current->id,
                    'request_key' => $requestKey,
                    'request_fingerprint' => $fingerprint,
                ]);
                AuditLogger::logOrFail('fleet.driving.score_policy_published', $policy, [
                    'actor_id' => $current->id,
                    'asset_id' => $vehicle->id,
                    'version' => $policy->version,
                    'superseded_version' => $currentVersion,
                    'reason' => $values['reason'],
                ]);

                return $policy;
            }, 3);
        } catch (QueryException $exception) {
            // Two first versions published at once: the loser sees the winner.
            abort_if((int) ($exception->errorInfo[1] ?? 0) === 1062, 409,
                'The scoring policy changed while you were reviewing it. Reload to see the current version.');

            throw $exception;
        }
    }

    public static function assertKey(string $requestKey): void
    {
        if (mb_strlen($requestKey) < 8 || mb_strlen($requestKey) > 90) {
            throw ValidationException::withMessages(['request_key' => 'Reload and try again.']);
        }
    }
}
