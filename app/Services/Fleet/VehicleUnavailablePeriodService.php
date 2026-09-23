<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleUnavailablePeriod;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Periods when a vehicle can't be booked. Lock order: asset, then the period
 * and any bookings checked for overlap. A period never creates or clears a
 * maintenance restriction; it only keeps the calendar honest.
 */
class VehicleUnavailablePeriodService
{
    /** Bookings still holding or asking for the vehicle. */
    public const BLOCKING_BOOKING_STATUSES = ['pending', 'approved', 'checked_out'];

    public function __construct(private readonly VehicleBookingAccessService $access) {}

    public function canManage(User $actor): bool
    {
        return $actor->canDo('fleet.manage');
    }

    /** @param array<string,mixed> $data */
    public function create(User $actor, int $assetId, array $data, string $requestKey, ?int $workOrderId = null): FleetVehicleUnavailablePeriod
    {
        [$starts, $ends, $reason] = $this->validated($data);
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'asset' => $assetId, 'starts' => $starts->toIso8601String(),
            'ends' => $ends->toIso8601String(), 'reason' => $reason, 'work_order' => $workOrderId,
        ]);
        abort_if($requestKey === '', 422, 'A request key is required.');

        return DB::transaction(function () use ($actor, $assetId, $starts, $ends, $reason, $requestKey, $fingerprint, $workOrderId): FleetVehicleUnavailablePeriod {
            // Maintenance managers may block the window of an appointment they plan.
            $current = $workOrderId !== null && app(MaintenanceAccessService::class)->canManage($actor)
                ? User::query()->findOrFail($actor->id)
                : $this->manager($actor);
            $asset = $this->access->vehicle($current, $assetId, true) ?? abort(404);
            $existing = FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different unavailable period. Reload and try again.');

                return $existing;
            }
            if ($starts->lessThan(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['starts_local' => 'Choose a current or future start for the unavailable period.']);
            }
            $this->assertFree($asset, $starts, $ends, null);

            $period = FleetVehicleUnavailablePeriod::query()->create([
                'asset_id' => $asset->id, 'starts_at' => $starts, 'ends_at' => $ends, 'reason' => $reason,
                'work_order_id' => $workOrderId, 'state' => FleetVehicleUnavailablePeriod::STATE_ACTIVE,
                'lock_version' => 1, 'created_by_user_id' => $current->id,
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.vehicle.unavailable.create', $period, [
                'asset_id' => $asset->id, 'starts_at' => $starts->toIso8601String(),
                'ends_at' => $ends->toIso8601String(), 'reason' => $reason, 'work_order_id' => $workOrderId,
            ]);

            return $period;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, int $assetId, int $periodId, array $data, int $expectedVersion): FleetVehicleUnavailablePeriod
    {
        [$starts, $ends, $reason] = $this->validated($data);
        $change = trim((string) ($data['change_reason'] ?? ''));
        if ($change === '' || mb_strlen($change) > 2000) {
            throw ValidationException::withMessages(['change_reason' => 'Record the reason for this change.']);
        }

        return DB::transaction(function () use ($actor, $assetId, $periodId, $starts, $ends, $reason, $change, $expectedVersion): FleetVehicleUnavailablePeriod {
            $current = User::query()->findOrFail($actor->id);
            $asset = $this->access->vehicle($current, $assetId, true) ?? abort(404);
            $period = FleetVehicleUnavailablePeriod::query()->whereKey($periodId)
                ->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
            $this->assertMayChange($current, $period);
            // A retried save that already landed reports success, not a conflict.
            if ($period->lock_version === $expectedVersion + 1 && $period->starts_at->equalTo($starts)
                && $period->ends_at->equalTo($ends) && $period->reason === $reason) {
                return $period;
            }
            abort_unless($period->state === FleetVehicleUnavailablePeriod::STATE_ACTIVE, 409,
                'This unavailable period has been cancelled. Reload the calendar.');
            abort_unless($period->lock_version === $expectedVersion, 409,
                'This unavailable period changed while you were editing. Reload before saving.');
            $this->assertFree($asset, $starts, $ends, $period->id);

            $before = ['starts_at' => $period->starts_at->toIso8601String(),
                'ends_at' => $period->ends_at->toIso8601String(), 'reason' => $period->reason];
            $period->forceFill([
                'starts_at' => $starts, 'ends_at' => $ends, 'reason' => $reason,
                'lock_version' => $period->lock_version + 1,
            ])->save();
            AuditLogger::logOrFail('fleet.vehicle.unavailable.update', $period, [
                'asset_id' => $asset->id, 'before' => $before,
                'after' => ['starts_at' => $starts->toIso8601String(), 'ends_at' => $ends->toIso8601String(), 'reason' => $reason],
                'reason' => $change,
            ]);

            return $period;
        }, 3);
    }

    public function cancel(User $actor, int $assetId, int $periodId, string $reason, int $expectedVersion): FleetVehicleUnavailablePeriod
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Record why the unavailable period is cancelled.']);
        }

        return DB::transaction(function () use ($actor, $assetId, $periodId, $reason, $expectedVersion): FleetVehicleUnavailablePeriod {
            $current = User::query()->findOrFail($actor->id);
            $asset = $this->access->vehicle($current, $assetId, true) ?? abort(404);
            $period = FleetVehicleUnavailablePeriod::query()->whereKey($periodId)
                ->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
            $this->assertMayChange($current, $period);
            if ($period->state === FleetVehicleUnavailablePeriod::STATE_CANCELLED) {
                abort_unless($period->cancellation_reason === $reason, 409, 'This unavailable period was already cancelled.');

                return $period;
            }
            abort_unless($period->lock_version === $expectedVersion, 409,
                'This unavailable period changed while you were editing. Reload before cancelling.');
            $period->forceFill([
                'state' => FleetVehicleUnavailablePeriod::STATE_CANCELLED, 'cancelled_by_user_id' => $current->id,
                'cancelled_at' => now(), 'cancellation_reason' => $reason, 'lock_version' => $period->lock_version + 1,
            ])->save();
            AuditLogger::logOrFail('fleet.vehicle.unavailable.cancel', $period, [
                'asset_id' => $asset->id, 'reason' => $reason,
            ]);

            return $period;
        }, 3);
    }

    /**
     * The booking or period that a window would collide with, if any. The
     * caller holds the asset lock, so this read is authoritative.
     */
    public function conflictFor(Asset $asset, CarbonImmutable $starts, CarbonImmutable $ends, ?int $ignorePeriodId = null, ?int $ignoreBookingId = null): ?string
    {
        $booking = FleetVehicleBooking::query()->where('asset_id', $asset->id)
            ->whereIn('status', self::BLOCKING_BOOKING_STATUSES)
            ->when($ignoreBookingId, fn ($query) => $query->whereKeyNot($ignoreBookingId))
            ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)
            ->orderBy('starts_at')->lockForUpdate()->first(['id', 'reference_number', 'status']);
        if ($booking) {
            return 'This time overlaps booking '.($booking->reference_number ?: '#'.$booking->id)
                .'. Change or cancel that booking first.';
        }
        $period = FleetVehicleUnavailablePeriod::query()->where('asset_id', $asset->id)
            ->when($ignorePeriodId, fn ($query) => $query->whereKeyNot($ignorePeriodId))
            ->overlapping($starts, $ends)->lockForUpdate()->first(['id']);

        return $period ? 'This time overlaps another unavailable period for this vehicle.' : null;
    }

    private function assertFree(Asset $asset, CarbonImmutable $starts, CarbonImmutable $ends, ?int $ignorePeriodId): void
    {
        $conflict = $this->conflictFor($asset, $starts, $ends, $ignorePeriodId);
        if ($conflict !== null) {
            throw ValidationException::withMessages(['starts_local' => $conflict]);
        }
    }

    /** Fleet managers change any period; maintenance managers the ones their appointments made. */
    private function assertMayChange(User $current, FleetVehicleUnavailablePeriod $period): void
    {
        abort_unless($this->canManage($current)
            || ($period->work_order_id !== null && app(MaintenanceAccessService::class)->canManage($current)), 403);
    }

    private function manager(User $actor): User
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canManage($current), 403);

        return $current;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{CarbonImmutable,CarbonImmutable,string}
     */
    private function validated(array $data): array
    {
        Validator::make($data, [
            'starts_local' => ['required', 'string'],
            'ends_local' => ['required', 'string'],
            'starts_offset' => ['nullable', 'string', 'max:6'],
            'ends_offset' => ['nullable', 'string', 'max:6'],
            'reason' => ['required', 'string', 'max:2000'],
        ], [], ['starts_local' => 'start', 'ends_local' => 'end', 'reason' => 'reason'])->validate();

        $starts = $this->utc($data['starts_local'], $data['starts_offset'] ?? null, 'starts_local');
        $ends = $this->utc($data['ends_local'], $data['ends_offset'] ?? null, 'ends_local');
        if (! $ends->greaterThan($starts)) {
            throw ValidationException::withMessages(['ends_local' => 'The end must be after the start.']);
        }

        return [$starts, $ends, trim((string) $data['reason'])];
    }

    private function utc(mixed $local, mixed $offset, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(MaintenanceLocalTime::toUtc((string) $local, $offset === null ? null : (string) $offset), 'UTC');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => collect($exception->errors())->flatten()->first()]);
        }
    }
}
