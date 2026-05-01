<?php

namespace App\Services;

use App\Events\CoverageSupplyAdded;
use App\Models\CoverageReservation;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CoverageReservationService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        protected ShiftCoverageService $coverage,
    ) {
    }

    public function createQuickFillReservation(
        User $actor,
        int $siteId,
        CarbonInterface $windowStartsAt,
        CarbonInterface $windowEndsAt,
        ?int $coverageRequirementId = null,
        ?string $roleKey = null,
        array $meta = [],
        int $ttlMinutes = 10,
    ): CoverageReservation {
        return DB::transaction(function () use ($actor, $siteId, $windowStartsAt, $windowEndsAt, $coverageRequirementId, $roleKey, $meta, $ttlMinutes) {
            $this->expireStaleReservations();
            if ($coverageRequirementId) {
                SiteCoverageRequirement::query()->lockForUpdate()->find($coverageRequirementId);
            }
            $window = $this->findCoverageWindow($siteId, $windowStartsAt, $windowEndsAt, $coverageRequirementId);
            if (! $window) {
                throw ValidationException::withMessages([
                    'coverage' => 'That coverage gap is no longer active.',
                ]);
            }

            $active = CoverageReservation::query()
                ->where('status', self::STATUS_ACTIVE)
                ->where('site_id', $siteId)
                ->when(
                    $coverageRequirementId,
                    fn ($query) => $query->where('coverage_requirement_id', $coverageRequirementId),
                    fn ($query) => $query->whereNull('coverage_requirement_id'),
                )
                ->where('window_starts_at', $windowStartsAt)
                ->where('window_ends_at', $windowEndsAt)
                ->where('reserved_by_user_id', $actor->id)
                ->where('reason', 'quick_fill')
                ->when($roleKey, fn ($query) => $query->where('role_key', $roleKey), fn ($query) => $query->whereNull('role_key'))
                ->first();

            if ($active) {
                $active->update([
                    'expires_at' => now()->addMinutes($ttlMinutes),
                    'role_key' => $roleKey,
                    'meta' => array_merge($active->meta ?? [], $meta, [
                        'slot_key' => $this->nextAvailableSlotKey($window, $roleKey),
                    ]),
                ]);

                return $active->fresh();
            }

            $remainingSlots = $this->availableSlotsForWindow($window, $roleKey);
            if ($remainingSlots <= 0) {
                throw ValidationException::withMessages([
                    'coverage' => 'Another scheduler has already reserved the remaining coverage for this window.',
                ]);
            }

            return CoverageReservation::create([
                'organization_id' => $actor->organization_id,
                'site_id' => $siteId,
                'coverage_requirement_id' => $coverageRequirementId,
                'reserved_by_user_id' => $actor->id,
                'reservation_token' => (string) Str::uuid(),
                'status' => self::STATUS_ACTIVE,
                'reason' => 'quick_fill',
                'role_key' => $roleKey,
                'window_starts_at' => $windowStartsAt,
                'window_ends_at' => $windowEndsAt,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'meta' => array_merge($meta, [
                    'slot_key' => $this->nextAvailableSlotKey($window, $roleKey),
                ]),
            ]);
        });
    }

    public function validateToken(?string $token, User $actor, array $context = []): ?CoverageReservation
    {
        if (! $token) {
            return null;
        }

        $this->expireStaleReservations();

        $reservation = CoverageReservation::query()
            ->where('reservation_token', $token)
            ->where('status', self::STATUS_ACTIVE)
            ->where('reserved_by_user_id', $actor->id)
            ->first();

        if (! $reservation || ! $reservation->expires_at || $reservation->expires_at->lte(now())) {
            throw ValidationException::withMessages([
                'coverage_reservation_token' => 'This coverage hold has expired. Re-open the gap and try again.',
            ]);
        }

        foreach ($context as $key => $expected) {
            if ($expected === null || $expected === '') {
                continue;
            }

            if (in_array($key, ['site_id', 'coverage_requirement_id'], true) && (int) $reservation->{$key} !== (int) $expected) {
                throw ValidationException::withMessages([
                    'coverage_reservation_token' => 'This coverage hold no longer matches the selected shortage window.',
                ]);
            }

            if ($key === 'window_starts_at' && ! $reservation->window_starts_at?->equalTo(Carbon::parse((string) $expected))) {
                throw ValidationException::withMessages([
                    'coverage_reservation_token' => 'This coverage hold was created for a different start time.',
                ]);
            }

            if ($key === 'window_ends_at' && ! $reservation->window_ends_at?->equalTo(Carbon::parse((string) $expected))) {
                throw ValidationException::withMessages([
                    'coverage_reservation_token' => 'This coverage hold was created for a different end time.',
                ]);
            }
        }

        return $reservation;
    }

    public function fulfill(?CoverageReservation $reservation, ?Shift $shift = null, ?ShiftOpenPosition $position = null): void
    {
        if (! $reservation) {
            return;
        }

        $reservation->update([
            'status' => self::STATUS_FULFILLED,
            'shift_id' => $shift?->id ?? $reservation->shift_id,
            'shift_open_position_id' => $position?->id ?? $reservation->shift_open_position_id,
            'expires_at' => now(),
        ]);

        $reservation->refresh();

        if ($reservation->site_id && $reservation->window_starts_at && $reservation->window_ends_at) {
            $coverageWindowKey = app(ShiftSignalService::class)->buildCoverageWindowKey([
                'site_id' => $reservation->site_id,
                'rule_id' => $reservation->coverage_requirement_id,
                'starts_at' => $reservation->window_starts_at->toIso8601String(),
                'ends_at' => $reservation->window_ends_at->toIso8601String(),
            ]);

            event(new CoverageSupplyAdded(
                $coverageWindowKey,
                (int) $reservation->site_id,
                $reservation->coverage_requirement_id ? (int) $reservation->coverage_requirement_id : null,
                $reservation->window_starts_at->toIso8601String(),
                $reservation->window_ends_at->toIso8601String(),
                $shift?->id ?? $reservation->shift_id,
                $shift?->shift_series_id,
                $reservation->reserved_by_user_id ? (int) $reservation->reserved_by_user_id : null,
                $reservation->reason,
            ));
        }
    }

    public function release(?CoverageReservation $reservation): void
    {
        if (! $reservation || $reservation->status !== self::STATUS_ACTIVE) {
            return;
        }

        $reservation->update([
            'status' => self::STATUS_RELEASED,
            'expires_at' => now(),
        ]);
    }

    public function releaseForShift(Shift $shift): void
    {
        CoverageReservation::query()
            ->where('status', self::STATUS_ACTIVE)
            ->where(function ($query) use ($shift) {
                $query->where('shift_id', $shift->id)
                    ->orWhere(function ($windowQuery) use ($shift) {
                        if (! $shift->site_id || ! $shift->starts_at || ! $shift->ends_at) {
                            $windowQuery->whereRaw('1 = 0');
                            return;
                        }

                        $windowQuery->where('site_id', $shift->site_id)
                            ->where('window_starts_at', $shift->starts_at)
                            ->where('window_ends_at', $shift->ends_at);
                    });
            })
            ->update([
                'status' => self::STATUS_RELEASED,
                'expires_at' => now(),
            ]);
    }

    public function reserveForAssignment(Shift $shift, User $actor, string $reason = 'assignment'): ?CoverageReservation
    {
        $coverage = $this->coverage->coverageStatusForShift($shift);
        if (! $coverage || ($coverage['unfilled_after_open_shifts'] ?? 0) <= 0) {
            return null;
        }

        return DB::transaction(function () use ($shift, $actor, $reason, $coverage) {
            $this->expireStaleReservations();
            Shift::query()->lockForUpdate()->find($shift->id);

            $remaining = $this->availableSlotsForWindow($coverage, null);
            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'coverage' => 'Another scheduler has already filled this coverage window.',
                ]);
            }

            return CoverageReservation::create([
                'organization_id' => $actor->organization_id,
                'site_id' => $coverage['site_id'],
                'coverage_requirement_id' => $coverage['rule_id'] ?? null,
                'shift_id' => $shift->id,
                'reserved_by_user_id' => $actor->id,
                'reservation_token' => (string) Str::uuid(),
                'status' => self::STATUS_ACTIVE,
                'reason' => $reason,
                'window_starts_at' => Carbon::parse($coverage['starts_at']),
                'window_ends_at' => Carbon::parse($coverage['ends_at']),
                'expires_at' => now()->addMinutes(5),
                'meta' => [
                    'shift_id' => $shift->id,
                    'slot_key' => $this->nextAvailableSlotKey($coverage, null),
                ],
            ]);
        });
    }

    public function reserveForCoveragePayload(User $actor, array $payload, string $reason = 'planning_fill'): ?CoverageReservation
    {
        $siteId = isset($payload['site_id']) ? (int) $payload['site_id'] : null;
        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;

        if (! $siteId || ! $startsAt || ! $endsAt) {
            return null;
        }

        $windowStartsAt = Carbon::parse((string) $startsAt);
        $windowEndsAt = Carbon::parse((string) $endsAt);
        $coverageRequirementId = ! empty($payload['coverage_rule_id']) ? (int) $payload['coverage_rule_id'] : null;
        $roleKey = ! empty($payload['role_key'])
            ? trim((string) $payload['role_key'])
            : collect($payload['coverage_roles'] ?? [])
            ->map(fn ($role) => is_string($role) ? trim($role) : null)
            ->first(fn (?string $role) => $role !== null && $role !== '');

        return DB::transaction(function () use ($actor, $siteId, $windowStartsAt, $windowEndsAt, $coverageRequirementId, $roleKey, $reason) {
            $this->expireStaleReservations();
            if ($coverageRequirementId) {
                SiteCoverageRequirement::query()->lockForUpdate()->find($coverageRequirementId);
            }

            $window = $this->findCoverageWindow($siteId, $windowStartsAt, $windowEndsAt, $coverageRequirementId);
            if (! $window) {
                return null;
            }

            $roleShortagePool = ($window['planned_role_shortages'] ?? []) !== []
                ? ($window['planned_role_shortages'] ?? [])
                : ($window['role_shortages'] ?? []);
            $hasRoleGap = $roleKey
                ? collect($roleShortagePool)->contains(fn (array $role) => ($role['key'] ?? null) === $roleKey && (int) ($role['missing'] ?? 0) > 0)
                : false;
            $hasHeadcountGap = (int) ($window['unfilled_after_open_shifts'] ?? $window['missing_staff'] ?? 0) > 0;

            if (! $hasHeadcountGap && ! $hasRoleGap) {
                return null;
            }

            $reservationRoleKey = $hasRoleGap ? $roleKey : null;
            $active = CoverageReservation::query()
                ->where('status', self::STATUS_ACTIVE)
                ->where('site_id', $siteId)
                ->when(
                    $coverageRequirementId,
                    fn ($query) => $query->where('coverage_requirement_id', $coverageRequirementId),
                    fn ($query) => $query->whereNull('coverage_requirement_id'),
                )
                ->where('window_starts_at', $windowStartsAt)
                ->where('window_ends_at', $windowEndsAt)
                ->where('reserved_by_user_id', $actor->id)
                ->where('reason', $reason)
                ->when($reservationRoleKey, fn ($query) => $query->where('role_key', $reservationRoleKey), fn ($query) => $query->whereNull('role_key'))
                ->first();

            if ($active) {
                $active->update([
                    'expires_at' => now()->addMinutes(5),
                    'meta' => array_merge($active->meta ?? [], [
                        'source' => $reason,
                        'slot_key' => $this->nextAvailableSlotKey($window, $reservationRoleKey),
                    ]),
                ]);

                return $active->fresh();
            }

            $remainingSlots = $this->availableSlotsForWindow($window, $reservationRoleKey);
            if ($remainingSlots <= 0) {
                throw ValidationException::withMessages([
                    'coverage' => 'Another scheduler has already filled or reserved this coverage window.',
                ]);
            }

            return CoverageReservation::create([
                'organization_id' => $actor->organization_id,
                'site_id' => $siteId,
                'coverage_requirement_id' => $coverageRequirementId,
                'reserved_by_user_id' => $actor->id,
                'reservation_token' => (string) Str::uuid(),
                'status' => self::STATUS_ACTIVE,
                'reason' => $reason,
                'role_key' => $reservationRoleKey,
                'window_starts_at' => $windowStartsAt,
                'window_ends_at' => $windowEndsAt,
                'expires_at' => now()->addMinutes(5),
                'meta' => [
                    'source' => $reason,
                    'slot_key' => $this->nextAvailableSlotKey($window, $reservationRoleKey),
                ],
            ]);
        });
    }

    public function expireStaleReservations(): void
    {
        CoverageReservation::query()
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '<=', now())
            ->update([
                'status' => self::STATUS_EXPIRED,
            ]);
    }

    /**
     * @param array<string, mixed> $window
     */
    protected function availableSlotsForWindow(array $window, ?string $roleKey): int
    {
        $activeReservations = CoverageReservation::query()
            ->where('status', self::STATUS_ACTIVE)
            ->where('site_id', $window['site_id'])
            ->when(
                $window['rule_id'] ?? null,
                fn ($query, $ruleId) => $query->where('coverage_requirement_id', $ruleId),
                fn ($query) => $query->whereNull('coverage_requirement_id'),
            )
            ->where('window_starts_at', Carbon::parse($window['starts_at']))
            ->where('window_ends_at', Carbon::parse($window['ends_at']))
            ->when($roleKey, fn ($query) => $query->where('role_key', $roleKey))
            ->count();

        $roleShortagePool = ($window['planned_role_shortages'] ?? []) !== []
            ? ($window['planned_role_shortages'] ?? [])
            : ($window['role_shortages'] ?? []);

        if ($roleKey && ! empty($roleShortagePool)) {
            $required = collect($roleShortagePool)
                ->firstWhere('key', $roleKey);

            return max(0, (int) ($required['missing'] ?? 0) - $activeReservations);
        }

        return max(0, (int) ($window['unfilled_after_open_shifts'] ?? $window['missing_staff'] ?? 0) - $activeReservations);
    }

    protected function findCoverageWindow(
        int $siteId,
        CarbonInterface $windowStartsAt,
        CarbonInterface $windowEndsAt,
        ?int $coverageRequirementId = null,
    ): ?array {
        return $this->coverage->findCoverageWindow($siteId, $windowStartsAt, $windowEndsAt, $coverageRequirementId);
    }

    /**
     * @param array<string, mixed> $window
     */
    protected function nextAvailableSlotKey(array $window, ?string $roleKey): ?string
    {
        $slots = collect($window['coverage_slots'] ?? []);

        $matchingSlot = $slots->first(function (array $slot) use ($roleKey) {
            if ($roleKey) {
                return ($slot['kind'] ?? null) === 'role'
                    && ($slot['role_key'] ?? null) === $roleKey
                    && in_array($slot['status'] ?? null, ['available', 'reserved'], true);
            }

            return ($slot['kind'] ?? null) === 'headcount'
                && in_array($slot['status'] ?? null, ['available', 'reserved'], true);
        });

        return $matchingSlot['slot_key'] ?? null;
    }
}
