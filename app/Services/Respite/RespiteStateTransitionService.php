<?php

namespace App\Services\Respite;

use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteStay;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RespiteStateTransitionService
{
    /** @var array<int, string> */
    public const REQUEST_GENERIC_STATUSES = [
        'draft',
        'submitted',
        'under_review',
        'rejected',
        'waitlisted',
    ];

    /** @var array<int, string> */
    public const BOOKING_GENERIC_STATUSES = [
        'pending',
        'cancelled',
        'no_show',
        'on_hold_pending_funding',
    ];

    /** @var array<string, array<int, string>> */
    private const REQUEST_UPDATE_TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['under_review', 'rejected', 'waitlisted'],
        'under_review' => ['submitted', 'rejected', 'waitlisted'],
        'waitlisted' => ['under_review', 'rejected'],
        'approved' => [],
        'rejected' => [],
    ];

    /** @var array<string, array<int, string>> */
    private const BOOKING_UPDATE_TRANSITIONS = [
        'pending' => ['cancelled', 'on_hold_pending_funding'],
        'on_hold_pending_funding' => ['pending', 'cancelled'],
        'confirmed' => ['cancelled', 'no_show'],
        'in_progress' => [],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    public function __construct(
        private readonly RespiteStayScope $scope,
    ) {}

    public function assertRequestAccessible(Request $request, int $requestId): void
    {
        $this->scope->resolveAuthorizedBookingRequest($request, $requestId);
    }

    public function assertBookingAccessible(Request $request, int $bookingId): void
    {
        $this->scope->resolveAuthorizedBooking($request, $bookingId);
    }

    public function assertStayAccessible(Request $request, int $stayId): void
    {
        $this->scope->resolveAuthorizedStay($request, $stayId);
    }

    /**
     * @template T
     *
     * @param  Closure(RespiteBookingRequest): T  $transition
     * @return T
     */
    public function transitionRequest(Request $request, int $requestId, Closure $transition): mixed
    {
        return DB::transaction(
            fn () => $transition($this->scope->resolveAuthorizedBookingRequest($request, $requestId, true)),
            3,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(RespiteBooking): T  $transition
     * @return T
     */
    public function transitionBooking(Request $request, int $bookingId, Closure $transition): mixed
    {
        return DB::transaction(
            fn () => $transition($this->scope->resolveAuthorizedBooking($request, $bookingId, true)),
            3,
        );
    }

    /**
     * @template T
     *
     * @param  Closure(RespiteStay): T  $transition
     * @return T
     */
    public function transitionStay(Request $request, int $stayId, Closure $transition): mixed
    {
        return DB::transaction(
            fn () => $transition($this->scope->resolveAuthorizedStay($request, $stayId, true)),
            3,
        );
    }

    public function assertAuthorizedSite(Request $request, int $siteId): void
    {
        $this->scope->assertAuthorizedSiteId($request, $siteId);
    }

    public function assertRequestUpdate(string $from, ?string $to): void
    {
        if ($to === null || $to === $from) {
            if (in_array($from, ['approved', 'rejected'], true)) {
                $this->fail('The booking request is terminal and cannot be changed.');
            }

            return;
        }

        $this->assertAllowed(self::REQUEST_UPDATE_TRANSITIONS, $from, $to, 'booking request');
    }

    public function assertRequestApproval(string $from): void
    {
        if (! in_array($from, ['submitted', 'under_review'], true)) {
            $this->fail('Only a submitted or under-review booking request can be approved.');
        }
    }

    public function assertRequestPromotion(string $from): void
    {
        if ($from !== 'waitlisted') {
            $this->fail('Only a waitlisted booking request can be promoted.');
        }
    }

    public function assertRequestHasNoBooking(bool $hasBooking): void
    {
        if ($hasBooking) {
            $this->fail('A booking request with a linked respite booking cannot change state.');
        }
    }

    public function assertBookingUpdate(string $from, ?string $to): void
    {
        if ($to === null || $to === $from) {
            if (in_array($from, ['completed', 'cancelled', 'no_show'], true)) {
                $this->fail('The respite booking is terminal and cannot be changed.');
            }

            return;
        }

        $this->assertAllowed(self::BOOKING_UPDATE_TRANSITIONS, $from, $to, 'respite booking');
    }

    public function assertBookingConfirmation(string $from): void
    {
        if ($from !== 'pending') {
            $this->fail('Only a pending respite booking can be confirmed.');
        }
    }

    public function assertBookingHasNoCurrentStay(bool $hasCurrentStay, string $action): void
    {
        if ($hasCurrentStay) {
            $this->fail("A respite booking with a current stay cannot be {$action}.");
        }
    }

    public function assertStayAdmission(string $bookingStatus, bool $stayExists): void
    {
        if ($bookingStatus !== 'confirmed') {
            $this->fail('A stay can only be admitted from a confirmed respite booking.');
        }

        if ($stayExists) {
            $this->fail('This respite booking already has a stay. Start a new booking for a new episode.');
        }
    }

    public function assertStayCheckIn(string $stayStatus, string $bookingStatus): void
    {
        if ($stayStatus !== 'admitted' || $bookingStatus !== 'confirmed') {
            $this->fail('Only an admitted stay on a confirmed booking can be checked in.');
        }
    }

    public function assertStayExtension(string $stayStatus, string $bookingStatus): void
    {
        if (! in_array($stayStatus, ['active', 'extended'], true)
            || ! in_array($bookingStatus, ['confirmed', 'in_progress'], true)) {
            $this->fail('Only an active respite stay can be extended.');
        }
    }

    public function assertStayDischarge(string $stayStatus, string $bookingStatus): void
    {
        $allowed = $stayStatus === 'admitted'
            ? $bookingStatus === 'confirmed'
            : in_array($stayStatus, ['active', 'extended'], true)
                && in_array($bookingStatus, ['confirmed', 'in_progress'], true);

        if (! $allowed) {
            $this->fail('Only a current respite stay can be discharged.');
        }
    }

    public function assertStayOperational(string $stayStatus, string $bookingStatus, string $action): void
    {
        if (! in_array($stayStatus, ['active', 'extended'], true)
            || ! in_array($bookingStatus, ['confirmed', 'in_progress'], true)) {
            $this->fail("Only an active respite stay can {$action}.");
        }
    }

    /**
     * @param  array<string, array<int, string>>  $transitions
     */
    private function assertAllowed(array $transitions, string $from, string $to, string $workflow): void
    {
        if (! in_array($to, $transitions[$from] ?? [], true)) {
            $this->fail("The {$workflow} cannot move from {$from} to {$to}.");
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['status' => $message]);
    }
}
