<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\FleetVehicleAlertPlan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A vehicle's draft response plan: the response owner and backup and the
 * thresholds Control Room should review against. Each save is a new version;
 * a draft is never a live device setting and activates nothing. Control
 * Room's own response targets (SLA) keep owning acknowledgement deadlines
 * and escalation.
 */
final class VehicleAlertPlanService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleStaffDirectory $staff,
    ) {}

    public function canManage(User $user): bool
    {
        return $user->canDo('fleet.manage');
    }

    public function current(Asset $vehicle): ?FleetVehicleAlertPlan
    {
        return FleetVehicleAlertPlan::query()->where('asset_id', $vehicle->getKey())
            ->with(['owner:id,name', 'backup:id,name', 'createdBy:id,name'])->orderByDesc('version')->first();
    }

    /** @return array<string,mixed>|null */
    public function present(?FleetVehicleAlertPlan $plan): ?array
    {
        if ($plan === null) {
            return null;
        }

        return [
            'version' => (int) $plan->version,
            'status' => 'draft',
            'owner' => $plan->owner ? ['id' => (int) $plan->owner->id, 'name' => (string) $plan->owner->name] : null,
            'backup' => $plan->backup ? ['id' => (int) $plan->backup->id, 'name' => (string) $plan->backup->name] : null,
            'speed_threshold_kph' => (int) $plan->speed_threshold_kph,
            'speed_tolerance_kph' => (int) $plan->speed_tolerance_kph,
            'speed_duration_s' => (int) $plan->speed_duration_s,
            'speed_cooldown_s' => (int) $plan->speed_cooldown_s,
            'offline_minutes' => (int) $plan->offline_minutes,
            'low_voltage_v' => (float) $plan->low_voltage_v,
            'low_voltage_minutes' => (int) $plan->low_voltage_minutes,
            'notes' => (string) $plan->notes,
            'saved_by' => $plan->createdBy?->name,
            'saved_at' => $plan->created_at?->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $data */
    public function save(User $actor, int $assetId, array $data, string $requestKey): FleetVehicleAlertPlan
    {
        DrivingScorePolicyStore::assertKey($requestKey);
        Validator::make($data, [
            'owner_user_id' => ['required', 'integer', 'min:1'],
            'backup_user_id' => ['required', 'integer', 'min:1', 'different:owner_user_id'],
            'speed_threshold_kph' => ['required', 'integer', 'min:1', 'max:150'],
            'speed_tolerance_kph' => ['required', 'integer', 'min:1', 'max:20'],
            'speed_duration_s' => ['required', 'integer', 'min:1', 'max:600'],
            'speed_cooldown_s' => ['required', 'integer', 'min:1', 'max:3600'],
            'offline_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'low_voltage_v' => ['required', 'numeric', 'min:8', 'max:32'],
            'low_voltage_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'notes' => ['required', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ], [
            'backup_user_id.different' => 'Choose a different person as the backup owner.',
            'speed_threshold_kph.max' => 'Use a fleet threshold up to 150 km/h.',
            'speed_tolerance_kph.max' => 'Use a tolerance up to 20 km/h.',
            'low_voltage_v.min' => 'Use a vehicle voltage threshold within 8–32 V.',
            'low_voltage_v.max' => 'Use a vehicle voltage threshold within 8–32 V.',
            'notes.required' => 'Record the response, cooldown and escalation instructions.',
        ], [
            'owner_user_id' => 'primary response owner', 'backup_user_id' => 'backup response owner',
            'speed_threshold_kph' => 'fleet speed threshold', 'speed_tolerance_kph' => 'tolerance',
            'speed_duration_s' => 'minimum continuous duration', 'speed_cooldown_s' => 'below-threshold time',
            'offline_minutes' => 'minutes overdue', 'low_voltage_v' => 'vehicle voltage', 'low_voltage_minutes' => 'low voltage duration',
        ])->validate();
        $values = [
            'owner_user_id' => (int) $data['owner_user_id'],
            'backup_user_id' => (int) $data['backup_user_id'],
            'speed_threshold_kph' => (int) $data['speed_threshold_kph'],
            'speed_tolerance_kph' => (int) $data['speed_tolerance_kph'],
            'speed_duration_s' => (int) $data['speed_duration_s'],
            'speed_cooldown_s' => (int) $data['speed_cooldown_s'],
            'offline_minutes' => (int) $data['offline_minutes'],
            'low_voltage_v' => round((float) $data['low_voltage_v'], 1),
            'low_voltage_minutes' => (int) $data['low_voltage_minutes'],
            'notes' => trim((string) $data['notes']),
        ];
        $reason = trim((string) ($data['reason'] ?? ''));
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'plan' => $values, 'reason' => $reason]);

        try {
            return DB::transaction(function () use ($actor, $assetId, $values, $reason, $data, $requestKey, $fingerprint): FleetVehicleAlertPlan {
                $current = User::query()->findOrFail($actor->id);
                abort_unless($this->canManage($current), 403);
                $vehicle = $this->vehicles->assignableVehicle($current, $assetId, true) ?? abort(404);
                $prior = FleetVehicleAlertPlan::query()->where('asset_id', $vehicle->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior) {
                    abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                        'This request was already used for a different plan.');

                    return $prior;
                }
                $latest = FleetVehicleAlertPlan::query()->where('asset_id', $vehicle->id)->orderByDesc('version')->lockForUpdate()->first();
                $version = $latest ? (int) $latest->version : 0;
                abort_unless((int) $data['expected_version'] === $version, 409,
                    'The response plan changed while you were editing it. Reload to see the current draft.');
                if ($latest && $reason === '') {
                    throw ValidationException::withMessages(['reason' => 'Record why the draft plan changed.']);
                }
                foreach (['owner_user_id' => 'primary response owner', 'backup_user_id' => 'backup response owner'] as $field => $label) {
                    if (! $this->staff->isCandidate($vehicle, $values[$field])) {
                        throw ValidationException::withMessages([$field => "Choose a current staff member at this vehicle's site as the {$label}."]);
                    }
                }
                $plan = FleetVehicleAlertPlan::query()->create($values + [
                    'asset_id' => $vehicle->id,
                    'version' => $version + 1,
                    'reason' => $reason === '' ? null : $reason,
                    'created_by_user_id' => $current->id,
                    'request_key' => $requestKey,
                    'request_fingerprint' => $fingerprint,
                ]);
                AuditLogger::logOrFail('fleet.vehicle.alert_plan_saved', $plan, [
                    'actor_id' => $current->id, 'asset_id' => $vehicle->id, 'version' => $plan->version,
                    'reason' => $plan->reason,
                ]);

                return $plan;
            }, 3);
        } catch (QueryException $exception) {
            abort_if((int) ($exception->errorInfo[1] ?? 0) === 1062, 409,
                'The response plan changed while you were editing it. Reload to see the current draft.');

            throw $exception;
        }
    }
}
