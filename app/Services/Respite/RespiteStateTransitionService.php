<?php

namespace App\Services\Respite;

use Illuminate\Validation\ValidationException;

class RespiteStateTransitionService
{
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

    public function assertRequestMutable(string $status): void
    {
        if (in_array($status, ['approved', 'rejected'], true)) {
            $this->fail('The booking request is terminal and cannot be changed.');
        }
    }

    public function assertRequestUpdate(string $from, string $to): void
    {
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

    public function assertBookingMutable(string $status): void
    {
        if (in_array($status, ['completed', 'cancelled', 'no_show'], true)) {
            $this->fail('The respite booking is terminal and cannot be changed.');
        }
    }

    public function assertBookingUpdate(string $from, string $to): void
    {
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
            $this->fail('This respite booking already has a stay.');
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

    public function assertStayOperational(string $stayStatus, string $action): void
    {
        if (! in_array($stayStatus, ['active', 'extended'], true)) {
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
