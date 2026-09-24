<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetSpeedLimit;
use App\Models\FleetSpeedLimitEvent;
use App\Models\FleetVehicleAlertPlan;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Manually recorded road speed limits for a vehicle's overspeed evaluations,
 * with the rules the approved design sets: a limit stays pending until
 * someone other than the proposer approves it, approval needs its evidence
 * file and no overlapping approved limit for the same road and direction,
 * and a limit applies only between its effective and expiry times. No
 * speed-limit provider is connected, so an unknown road limit stays unknown
 * and the fleet threshold still applies.
 */
final class VehicleSpeedLimitService
{
    public const DIRECTIONS = ['Both directions', 'Northbound', 'Southbound', 'Eastbound', 'Westbound', 'Inbound', 'Outbound'];

    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleTripHistoryService $trips,
        private readonly RecordedVehicleEvents $events,
    ) {}

    public function canManage(User $user): bool
    {
        return $user->canDo('fleet.manage');
    }

    /**
     * Manual limits with their evidence and history, the recorded overspeed
     * episodes that can be evaluated, and the rule an evaluation applies.
     *
     * @return array<string,mixed>
     */
    public function present(User $viewer, Asset $vehicle): array
    {
        $zone = VehicleTripHistoryService::zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $limits = FleetSpeedLimit::query()->where('asset_id', $vehicle->getKey())
            ->with(['events' => fn ($events) => $events->with('actor:id,name')->orderBy('id'), 'proposedBy:id,name', 'reviewedBy:id,name'])
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'retired')")->orderByDesc('effective_from')->orderByDesc('id')
            ->limit(100)->get();
        $files = AssetDocument::query()->where('asset_id', $vehicle->getKey())->where('source_type', 'speed_limit')
            ->whereIn('source_id', $limits->pluck('id')->all())->whereNull('archived_at')
            ->orderBy('id')->get(['id', 'source_id', 'original_name', 'title', 'state', 'document_set_id'])
            ->groupBy('source_id');
        $episodes = $this->events->overspeedEpisodes($viewer, $vehicle,
            $today->subDays(VehicleDrivingInsightsService::REVIEW_WINDOW_DAYS - 1)->toDateString(), $today->toDateString());
        $manage = $this->canManage($viewer);

        return [
            'as_of' => now()->toIso8601String(),
            'timezone' => $zone,
            'limits' => $limits->map(fn (FleetSpeedLimit $limit): array => $this->limitView($vehicle, $limit,
                $files->get($limit->id, collect()), $viewer))->values()->all(),
            'episodes' => array_map(fn (array $episode): array => [
                'source_key' => $episode['source_key'],
                'trip_id' => $episode['trip_id'],
                'trip_reference' => $episode['trip_reference'],
                'local_date' => $episode['local_date'],
                'event_key' => $episode['event_key'],
                'at' => $episode['at'],
                'peak_kph' => $episode['peak_kph'],
                'seconds' => $episode['seconds'],
                'source' => $episode['source'],
            ], $episodes),
            'rule' => $this->rule($vehicle),
            'directions' => self::DIRECTIONS,
            'can' => [
                'manage' => $manage,
                'upload_evidence' => Gate::forUser($viewer)->allows('manageDocuments', $vehicle),
                'route' => $manage,
            ],
        ];
    }

    /**
     * The overspeed rule an evaluation applies: the vehicle's draft response
     * plan when one exists, otherwise the fleet speed setting that recorded
     * the episode, with no tolerance or minimum duration.
     *
     * @return array{threshold_kph:float,tolerance_kph:float,min_seconds:int,source:string,plan_version:?int}
     */
    public function rule(Asset $vehicle): array
    {
        $plan = FleetVehicleAlertPlan::query()->where('asset_id', $vehicle->getKey())->orderByDesc('version')->first();
        if ($plan) {
            return [
                'threshold_kph' => (float) $plan->speed_threshold_kph,
                'tolerance_kph' => (float) $plan->speed_tolerance_kph,
                'min_seconds' => (int) $plan->speed_duration_s,
                'source' => 'plan',
                'plan_version' => (int) $plan->version,
            ];
        }

        return [
            'threshold_kph' => (float) TripBehaviourAnalyzer::policy()['speed_threshold_kph'],
            'tolerance_kph' => 0.0,
            'min_seconds' => 0,
            'source' => 'fleet_setting',
            'plan_version' => null,
        ];
    }

    /**
     * Which limit applies to a road, direction and time: exactly one approved,
     * unexpired manual limit, else unknown. Conflicting approved limits make
     * the road limit unknown.
     *
     * @param  Collection<int,FleetSpeedLimit>  $approved
     * @return array{road:?int,limit:float,source:string,confidence:string,record:string,limit_id:?int}
     */
    public static function resolve(Collection $approved, CarbonImmutable $at, string $segment, string $direction, float $fleet): array
    {
        $matches = $approved->filter(fn (FleetSpeedLimit $limit): bool => $limit->status === 'approved'
            && self::same($limit->road_segment, $segment) && self::same($limit->direction, $direction)
            && $at->greaterThanOrEqualTo($limit->effective_from) && $at->lessThan($limit->expires_at))->values();
        if ($matches->count() > 1) {
            return ['road' => null, 'limit' => $fleet, 'source' => 'Conflicting manual records · fleet threshold only',
                'confidence' => 'Needs review', 'record' => 'Conflict', 'limit_id' => null];
        }
        $manual = $matches->first();
        if ($manual) {
            return ['road' => (int) $manual->limit_kph, 'limit' => min((float) $manual->limit_kph, $fleet),
                'source' => 'Approved manual limit', 'confidence' => 'Evidence reviewed',
                'record' => 'Speed limit #'.$manual->id, 'limit_id' => (int) $manual->id];
        }

        return ['road' => null, 'limit' => $fleet,
            'source' => 'No approved manual limit for this road, direction and time · fleet threshold only',
            'confidence' => 'Road limit unknown', 'record' => 'No eligible road-limit source', 'limit_id' => null];
    }

    /**
     * Evaluate a recorded overspeed episode against the road and rule.
     *
     * @param  array<string,mixed>  $episode  RecordedVehicleEvents episode
     * @return array<string,mixed>
     */
    public function evaluate(Asset $vehicle, array $episode, string $segment, string $direction): array
    {
        $rule = $this->rule($vehicle);
        $approved = FleetSpeedLimit::query()->where('asset_id', $vehicle->getKey())->where('status', 'approved')->get();
        $resolved = self::resolve($approved, CarbonImmutable::parse((string) $episode['at']), $segment, $direction, $rule['threshold_kph']);
        $trigger = $resolved['limit'] + $rule['tolerance_kph'];
        $peak = $episode['peak_kph'];
        $seconds = $episode['seconds'];
        $qualifies = $peak !== null && (float) $peak > $trigger && $seconds !== null && (int) $seconds >= $rule['min_seconds'];
        $criterion = $resolved['road'] === null
            ? 'Fleet threshold only; posted limit unknown'
            : ($resolved['road'] <= $rule['threshold_kph'] ? 'Road-limit criterion' : 'Fleet threshold is lower than the road limit');

        return $resolved + [
            'trigger_kph' => $trigger,
            'qualifies' => $qualifies,
            'segment' => $segment,
            'direction' => $direction,
            'rule' => $rule,
            'summary' => sprintf('%s km/h for %s above the %s km/h trigger. %s. Road %s; fleet %s; tolerance %s. %s; %s; %s %s; recorded %s.',
                $peak === null ? 'Unknown' : self::number((float) $peak), $seconds === null ? 'an unknown time' : $seconds.' s',
                self::number($trigger), $criterion, $resolved['road'] === null ? 'unknown' : $resolved['road'].' km/h',
                self::number($rule['threshold_kph']).' km/h', self::number($rule['tolerance_kph']).' km/h',
                $resolved['source'], $resolved['record'], $segment, $direction,
                CarbonImmutable::parse((string) $episode['at'])->setTimezone(VehicleTripHistoryService::zone())->format('j M Y, g:i a')),
        ];
    }

    /** @param array<string,mixed> $data */
    public function propose(User $actor, int $assetId, array $data, string $requestKey): FleetSpeedLimit
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'road_segment' => ['required', 'string', 'max:160'],
            'direction' => ['required', 'in:'.implode(',', self::DIRECTIONS)],
            'limit_kph' => ['required', 'integer', 'min:5', 'max:150'],
            'effective_from_local' => ['required', 'string'],
            'expires_at_local' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'road_segment.required' => 'Record the road or segment the limit applies to.',
            'limit_kph.min' => 'Choose a limit between 5 and 150 km/h.',
            'limit_kph.max' => 'Choose a limit between 5 and 150 km/h.',
            'reason.required' => 'Record the authority reference and reason.',
        ], ['road_segment' => 'road or segment', 'limit_kph' => 'speed limit', 'effective_from_local' => 'effective from',
            'expires_at_local' => 'expiry'])->validate();
        $from = $this->utc((string) $data['effective_from_local'], $data['effective_from_offset'] ?? null, 'effective_from_local');
        $until = $this->utc((string) $data['expires_at_local'], $data['expires_at_offset'] ?? null, 'expires_at_local');
        if (! $until->greaterThan($from)) {
            throw ValidationException::withMessages(['expires_at_local' => 'Choose an expiry after the start.']);
        }
        $values = [
            'road_segment' => trim((string) $data['road_segment']),
            'direction' => (string) $data['direction'],
            'limit_kph' => (int) $data['limit_kph'],
            'effective_from' => $from->format('Y-m-d H:i:s'),
            'expires_at' => $until->format('Y-m-d H:i:s'),
            'reason' => trim((string) $data['reason']),
        ];
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'limit' => $values]);

        return DB::transaction(function () use ($actor, $assetId, $values, $requestKey, $fingerprint): FleetSpeedLimit {
            [$current, $vehicle] = $this->manager($actor, $assetId);
            $prior = FleetSpeedLimit::query()->where('asset_id', $vehicle->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different speed limit.');

                return $prior;
            }
            $limit = FleetSpeedLimit::query()->create($values + [
                'asset_id' => $vehicle->id,
                'status' => 'pending',
                'proposed_by_user_id' => $current->id,
                'lock_version' => 1,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
            ]);
            FleetSpeedLimitEvent::query()->create(['speed_limit_id' => $limit->id, 'action' => 'proposed',
                'actor_user_id' => $current->id, 'note' => $values['reason'],
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint]);
            AuditLogger::logOrFail('fleet.speed_limit.proposed', $limit, [
                'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'limit_kph' => $limit->limit_kph,
                'road_segment' => $limit->road_segment, 'direction' => $limit->direction,
            ]);

            return $limit;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function review(User $actor, int $assetId, int $limitId, string $action, array $data, string $requestKey): FleetSpeedLimit
    {
        abort_unless(in_array($action, ['approve', 'retire'], true), 404);
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'reason' => ['required', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ], [
            'reason.required' => 'Record the review decision and reason.',
            'confirmed.accepted' => $action === 'approve'
                ? 'Confirm that you verified the authority, road, direction and dates.'
                : 'Confirm that this limit should no longer apply.',
        ])->validate();
        $reason = trim((string) $data['reason']);
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'limit' => $limitId, 'action' => $action, 'reason' => $reason]);

        return DB::transaction(function () use ($actor, $assetId, $limitId, $action, $data, $reason, $requestKey, $fingerprint): FleetSpeedLimit {
            [$current, $vehicle] = $this->manager($actor, $assetId);
            $limit = FleetSpeedLimit::query()->whereKey($limitId)->where('asset_id', $vehicle->id)->lockForUpdate()->first() ?? abort(404);
            $prior = FleetSpeedLimitEvent::query()->where('speed_limit_id', $limit->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different change.');

                return $limit;
            }
            abort_unless($limit->lock_version === (int) $data['expected_version'], 409,
                'This speed limit changed while you were reviewing it. Reload before saving.');
            if ($action === 'approve') {
                if ($limit->status !== 'pending') {
                    throw ValidationException::withMessages(['reason' => 'Only a pending limit can be approved.']);
                }
                if ((int) $limit->proposed_by_user_id === (int) $current->id) {
                    throw ValidationException::withMessages(['reason' => 'Someone other than the person who proposed this limit must approve it.']);
                }
                // The approver must be able to open the evidence: files still
                // being scanned, or that failed a scan, don't count.
                $files = AssetDocument::query()->where('asset_id', $vehicle->id)->where('source_type', 'speed_limit')
                    ->where('source_id', $limit->id)->whereNull('archived_at')->get(['id', 'state', 'document_set_id']);
                if (! $files->contains(fn (AssetDocument $file): bool => $file->isOpenable())) {
                    throw ValidationException::withMessages(['reason' => $files->isEmpty()
                        ? 'Add the sign photo or authority document before approval.'
                        : 'The evidence can’t be opened yet. Approve once its file check has finished.']);
                }
                $overlap = FleetSpeedLimit::query()->where('asset_id', $vehicle->id)->where('status', 'approved')
                    ->whereKeyNot($limit->id)
                    ->where('effective_from', '<', $limit->expires_at)->where('expires_at', '>', $limit->effective_from)
                    ->lockForUpdate()->get()
                    ->contains(fn (FleetSpeedLimit $other): bool => self::same($other->road_segment, $limit->road_segment)
                        && self::same($other->direction, $limit->direction));
                if ($overlap) {
                    throw ValidationException::withMessages(['reason' => 'An approved limit overlaps this road, direction and time. Retire or correct it before approval.']);
                }
                $limit->forceFill(['status' => 'approved', 'reviewed_by_user_id' => $current->id, 'reviewed_at' => now()]);
            } else {
                if ($limit->status !== 'approved') {
                    throw ValidationException::withMessages(['reason' => 'Only an approved limit can be retired.']);
                }
                $limit->forceFill(['status' => 'retired', 'retired_by_user_id' => $current->id, 'retired_at' => now()]);
            }
            $limit->forceFill(['lock_version' => $limit->lock_version + 1])->save();
            FleetSpeedLimitEvent::query()->create(['speed_limit_id' => $limit->id,
                'action' => $action === 'approve' ? 'approved' : 'retired', 'actor_user_id' => $current->id, 'note' => $reason,
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint]);
            AuditLogger::logOrFail('fleet.speed_limit.'.($action === 'approve' ? 'approved' : 'retired'), $limit, [
                'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'reason' => $reason,
            ]);

            return $limit;
        }, 3);
    }

    /** @return array<string,mixed> */
    private function limitView(Asset $vehicle, FleetSpeedLimit $limit, Collection $files, User $viewer): array
    {
        return [
            'id' => (int) $limit->id,
            'reference' => 'Speed limit #'.$limit->id,
            'road_segment' => $limit->road_segment,
            'direction' => $limit->direction,
            'limit_kph' => (int) $limit->limit_kph,
            'effective_from' => $limit->effective_from?->toIso8601String(),
            'expires_at' => $limit->expires_at?->toIso8601String(),
            'expired' => $limit->expires_at !== null && ! $limit->expires_at->isFuture(),
            'reason' => $limit->reason,
            'status' => $limit->status,
            'lock_version' => (int) $limit->lock_version,
            'proposed_by' => $limit->proposedBy?->name,
            'proposed_by_me' => (int) $limit->proposed_by_user_id === (int) $viewer->id,
            'reviewed_by' => $limit->reviewedBy?->name,
            'files' => $files->map(fn (AssetDocument $file): array => [
                'id' => (int) $file->id,
                'name' => (string) ($file->original_name ?: $file->title ?: 'Evidence file'),
                'state' => $file->state,
                'url' => $file->isOpenable() ? '/fleet-assets/vehicles/'.$vehicle->getKey().'/documents/'.$file->id.'/file' : null,
            ])->values()->all(),
            'history' => $limit->events->map(fn (FleetSpeedLimitEvent $event): array => [
                'id' => (int) $event->id,
                'action' => $event->action,
                'actor' => $event->actor?->name,
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array{0:User,1:Asset} */
    private function manager(User $actor, int $assetId): array
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canManage($current), 403);
        $vehicle = $this->vehicles->assignableVehicle($current, $assetId, true) ?? abort(404);

        return [$current, $vehicle];
    }

    private function utc(string $local, mixed $offset, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(MaintenanceLocalTime::toUtc($local, is_string($offset) && $offset !== '' ? $offset : null), 'UTC');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => collect($exception->errors())->flatten()->first()]);
        }
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $a) ?? $a)) === mb_strtolower(trim(preg_replace('/\s+/', ' ', $b) ?? $b));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
