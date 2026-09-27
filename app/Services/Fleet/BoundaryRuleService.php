<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\FleetVehicleGeofenceAssignment as Assignment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Purpose proposals use the existing assignment register; only its owner can activate monitoring. */
final class BoundaryRuleService
{
    public function __construct(private readonly BoundaryService $boundaries) {}

    public function save(User $actor, array $input, ?int $id = null): Assignment
    {
        $data = Validator::make($input, [
            'asset_id' => ['required', 'integer'], 'boundary_id' => ['required', 'integer'],
            'boundary_revision' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:120'], 'purpose' => ['required', 'string', 'max:2000'],
            'response_proposal' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'expected_version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'],
            'request_key' => ['required', 'string', 'max:100'],
            'schedule' => ['nullable', 'array:timezone,weekdays,start,end,following_day,first_date,last_date,exception_dates'],
            'policy' => ['required', 'array:direction,timing,windows,accuracyM,confirmationSeconds,bufferM,dwellSeconds,repeatMinutes,priority,acknowledgeMinutes,escalateMinutes,escalationTeam,owner'],
            'policy.direction' => ['required', 'in:entry,exit,both,dwell'],
            'policy.timing' => ['required', 'in:unconfigured,scheduled,always'],
            'policy.windows' => ['required_if:policy.timing,scheduled', 'array', 'max:7'],
            'policy.windows.*' => ['array:start,end,following_day'],
            'policy.windows.*.start' => ['required', 'date_format:H:i'],
            'policy.windows.*.end' => ['required', 'date_format:H:i'],
            'policy.windows.*.following_day' => ['required', 'boolean'],
            'policy.accuracyM' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'policy.confirmationSeconds' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'policy.bufferM' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'policy.dwellSeconds' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'policy.repeatMinutes' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'policy.priority' => ['nullable', 'in:low,medium,high,critical'],
            'policy.acknowledgeMinutes' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'policy.escalateMinutes' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            // Written responsibility proposal, never an unverified notification destination.
            'policy.owner' => ['nullable', 'string', 'max:120'],
            'policy.escalationTeam' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $policy = $data['policy'];
        if ($policy['timing'] === 'scheduled') {
            $schedule = $data['schedule'] ?? [];
            Validator::make(['schedule' => $schedule], VehicleGeofenceRules::scheduleRules())->validate();
            $windows = $policy['windows'] ?? [];
            if (! count($windows)) {
                VehicleGeofenceRules::fail('policy.windows', 'Add a time window.');
            }
            $intervals = [];
            foreach ($windows as $window) {
                $current = array_replace($schedule, $window);
                $check = Validator::make(['schedule' => $current], VehicleGeofenceRules::scheduleRules());
                $check->after(function ($v) use ($current): void {
                    foreach (VehicleGeofenceRules::scheduleProblems($current) as $field => $message) {
                        $v->errors()->add($field, $message);
                    }
                });
                $check->validate();
                $minute = fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
                foreach ($schedule['weekdays'] as $day) {
                    $start = ($day - 1) * 1440 + $minute($window['start']);
                    $end = ($day - 1) * 1440 + $minute($window['end']) + ($window['following_day'] ? 1440 : 0);
                    foreach ($intervals as [$a, $b]) {
                        foreach ([-10080, 0, 10080] as $offset) {
                            if ($start < $b + $offset && $a + $offset < $end) {
                                VehicleGeofenceRules::fail('policy.windows', 'Time windows overlap, including the following day.');
                            }
                        }
                    }
                    $intervals[] = [$start, $end];
                }
            }
            $data['schedule'] = array_replace($schedule, $windows[0]);
        } else {
            $data['schedule'] = null;
        }
        if (! empty($policy['escalateMinutes']) && (empty($policy['escalationTeam'])
            || (float) $policy['escalateMinutes'] < (float) ($policy['acknowledgeMinutes'] ?? 0))) {
            VehicleGeofenceRules::fail('policy.escalateMinutes', 'Propose a responsible team and an escalation after the acknowledgement target.');
        }

        return DB::transaction(function () use ($actor, $data, $id): Assignment {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor = $this->boundaries->actor($actor, true);
            $asset = $this->boundaries->resources($actor)->whereKey($data['asset_id'])->lockForUpdate()->first() ?? abort(404);
            $b = $this->boundaries->resolve($actor, $data['boundary_id'], true);
            abort_if($b->retired_at || $b->revision !== $data['boundary_revision'], 409, 'This boundary changed or was retired. Select it again to review the current version.');
            $kind = Asset::query()->vehicles()->whereKey($asset->id)->exists() ? 'Vehicles' : 'Assets';
            abort_unless(in_array($kind, $b->permitted_uses ?? ['Vehicles', 'Assets'], true), 422, 'This boundary does not permit this purpose.');
            $this->notMonitored($asset->id, $b);
            $fingerprint = MaintenanceFingerprint::of($data);
            $r = $id ? Assignment::query()->whereKey($id)->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail() : null;
            if (! $id) {
                $replay = Assignment::query()->where('asset_id', $asset->id)->where('request_key', $data['request_key'])->first();
                if ($replay) {
                    abort_unless(hash_equals((string) $replay->request_fingerprint, $fingerprint), 409, 'This request key has different details.');

                    return $replay;
                }
            } else {
                if ($r->state === 'active' && $r->lock_version === $data['expected_version'] + 1
                    && hash_equals((string) $r->payload_hash, $fingerprint)) {
                    return $r;
                }
                abort_unless($r->state === 'active' && $r->lock_version === $data['expected_version'], 409, 'The rule changed. Review its current version.');
                if ($r->geofence_id) {
                    $this->notMonitored($asset->id, AssetGeofence::findOrFail($r->geofence_id));
                }
            }
            abort_if(Assignment::query()->active()->where('asset_id', $asset->id)->where('geofence_id', $b->id)
                ->when($id, fn ($q) => $q->whereKeyNot($id))->exists(), 422, 'This resource already has an assignment here. Edit that rule.');
            $r ??= new Assignment;
            $r->fill(['asset_id' => $asset->id, 'geofence_id' => $b->id, 'label' => $data['label'],
                'origin' => $r->origin ?? 'linked', 'purpose' => $data['purpose'], 'response_proposal' => $data['response_proposal'] ?? null,
                'schedule' => $data['schedule'], 'policy_proposal' => $data['policy'],
                'geometry_hash' => VehicleGeofenceRules::boundaryHash($b), 'geometry_snapshot' => VehicleGeofenceRules::fromBoundary($b),
                'monitoring' => 'inactive', 'state' => 'active', 'lock_version' => $id ? $r->lock_version + 1 : 1,
                'payload_hash' => $fingerprint, 'updated_by_user_id' => $actor->id]);
            if (! $id) {
                $r->fill(['created_by_user_id' => $actor->id, 'request_key' => $data['request_key'], 'request_fingerprint' => $fingerprint]);
            }
            $r->save();
            $this->record($r, $actor, $data['reason']);

            return $r;
        }, 3);
    }

    public function remove(User $actor, int $id, int $expected, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000']])->validate();
        DB::transaction(function () use ($actor, $id, $expected, $reason): void {
            $actor = $this->boundaries->actor($actor, true);
            $r = Assignment::query()->whereIn('asset_id', $this->boundaries->resources($actor)->select('assets.id'))->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($r->state === 'active' && $r->lock_version === $expected, 409, 'The rule changed. Review its current version.');
            abort_unless($r->geofence_id, 409, 'The source boundary is unavailable. Review this assignment in its owning profile.');
            $b = $this->boundaries->resolve($actor, $r->geofence_id, true);
            $this->notMonitored($r->asset_id, $b);
            $r->forceFill(['state' => 'removed', 'removed_at' => now(), 'removed_by_user_id' => $actor->id, 'removal_reason' => $reason, 'lock_version' => $r->lock_version + 1])->save();
            $this->record($r, $actor, $reason);
        }, 3);
    }

    private function notMonitored(int $assetId, AssetGeofence $b): void
    {
        abort_if($b->is_active && ((int) $b->asset_id === $assetId || $b->assignedAssets()->whereKey($assetId)->exists()), 409,
            'Existing monitoring must be paused by its owner before this assignment changes.');
    }

    public function record(Assignment $r, User $actor, string $reason): void
    {
        DB::table('boundary_rule_versions')->insert(['assignment_id' => $r->id, 'boundary_id' => $r->geofence_id,
            'revision' => $r->lock_version, 'snapshot' => json_encode($r->only(['id', 'asset_id', 'geofence_id', 'label', 'origin', 'purpose', 'response_proposal', 'schedule', 'policy_proposal', 'geometry_hash', 'geometry_snapshot', 'monitoring', 'state', 'lock_version', 'removed_at', 'removed_by_user_id', 'removal_reason']), JSON_THROW_ON_ERROR),
            'actor_id' => $actor->id, 'reason' => $reason, 'recorded_at' => now()]);
        AuditLogger::logOrFail('fleet.boundary.rule.saved', $r, ['actor_id' => $actor->id, 'revision' => $r->lock_version]);
    }
}
