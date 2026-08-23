<?php

namespace Tests\Unit\Services;

use App\Services\Respite\RespiteStateTransitionService;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RespiteStateTransitionServiceTest extends TestCase
{
    #[DataProvider('requestTransitionMatrix')]
    public function test_request_transition_matrix(string $from, string $to, bool $allowed): void
    {
        $states = $this->states();

        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }

        $states->assertRequestUpdate($from, $to);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('bookingTransitionMatrix')]
    public function test_booking_transition_matrix(string $from, string $to, bool $allowed): void
    {
        $states = $this->states();

        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }

        $states->assertBookingUpdate($from, $to);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('stayTransitionMatrix')]
    public function test_stay_transition_matrix(
        string $transition,
        string $stayStatus,
        string $bookingStatus,
        bool $allowed,
    ): void {
        $states = $this->states();

        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }

        match ($transition) {
            'check_in' => $states->assertStayCheckIn($stayStatus, $bookingStatus),
            'extend' => $states->assertStayExtension($stayStatus, $bookingStatus),
            'discharge' => $states->assertStayDischarge($stayStatus, $bookingStatus),
            'bed_hold' => $states->assertStayOperational($stayStatus, $bookingStatus, 'record a bed hold'),
        };
        $this->addToAssertionCount(1);
    }

    #[DataProvider('dedicatedTransitionMatrix')]
    public function test_dedicated_transition_matrix(string $transition, string $status, bool $allowed): void
    {
        $states = $this->states();

        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }

        match ($transition) {
            'request_approval' => $states->assertRequestApproval($status),
            'request_promotion' => $states->assertRequestPromotion($status),
            'booking_confirmation' => $states->assertBookingConfirmation($status),
        };
        $this->addToAssertionCount(1);
    }

    #[DataProvider('stayAdmissionMatrix')]
    public function test_stay_admission_matrix(string $bookingStatus, bool $stayExists, bool $allowed): void
    {
        $states = $this->states();

        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }

        $states->assertStayAdmission($bookingStatus, $stayExists);
        $this->addToAssertionCount(1);
    }

    public static function requestTransitionMatrix(): iterable
    {
        $states = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'waitlisted'];
        $allowed = [
            'draft' => ['draft', 'submitted'],
            'submitted' => ['submitted', 'under_review', 'rejected', 'waitlisted'],
            'under_review' => ['submitted', 'under_review', 'rejected', 'waitlisted'],
            'approved' => [],
            'rejected' => [],
            'waitlisted' => ['under_review', 'rejected', 'waitlisted'],
        ];

        foreach ($states as $from) {
            foreach ($states as $to) {
                yield "{$from} to {$to}" => [$from, $to, in_array($to, $allowed[$from], true)];
            }
        }
    }

    public static function bookingTransitionMatrix(): iterable
    {
        $states = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show', 'on_hold_pending_funding'];
        $allowed = [
            'pending' => ['pending', 'cancelled', 'on_hold_pending_funding'],
            'confirmed' => ['confirmed', 'cancelled', 'no_show'],
            'in_progress' => ['in_progress'],
            'completed' => [],
            'cancelled' => [],
            'no_show' => [],
            'on_hold_pending_funding' => ['pending', 'cancelled', 'on_hold_pending_funding'],
        ];

        foreach ($states as $from) {
            foreach ($states as $to) {
                yield "{$from} to {$to}" => [$from, $to, in_array($to, $allowed[$from], true)];
            }
        }
    }

    public static function stayTransitionMatrix(): iterable
    {
        $stayStates = ['admitted', 'active', 'extended', 'discharged'];
        $bookingStates = ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'];

        foreach (['check_in', 'extend', 'discharge', 'bed_hold'] as $transition) {
            foreach ($stayStates as $stayStatus) {
                foreach ($bookingStates as $bookingStatus) {
                    $allowed = match ($transition) {
                        'check_in' => $stayStatus === 'admitted' && $bookingStatus === 'confirmed',
                        'extend' => in_array($stayStatus, ['active', 'extended'], true)
                            && in_array($bookingStatus, ['confirmed', 'in_progress'], true),
                        'discharge' => ($stayStatus === 'admitted' && $bookingStatus === 'confirmed')
                            || (in_array($stayStatus, ['active', 'extended'], true)
                                && in_array($bookingStatus, ['confirmed', 'in_progress'], true)),
                        'bed_hold' => in_array($stayStatus, ['active', 'extended'], true)
                            && in_array($bookingStatus, ['confirmed', 'in_progress'], true),
                    };

                    yield "{$transition}: {$stayStatus} / {$bookingStatus}" => [
                        $transition,
                        $stayStatus,
                        $bookingStatus,
                        $allowed,
                    ];
                }
            }
        }
    }

    public static function dedicatedTransitionMatrix(): iterable
    {
        $requests = ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'waitlisted'];
        foreach ($requests as $status) {
            yield "request approval: {$status}" => [
                'request_approval',
                $status,
                in_array($status, ['submitted', 'under_review'], true),
            ];
            yield "request promotion: {$status}" => [
                'request_promotion',
                $status,
                $status === 'waitlisted',
            ];
        }

        foreach (['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show', 'on_hold_pending_funding'] as $status) {
            yield "booking confirmation: {$status}" => [
                'booking_confirmation',
                $status,
                $status === 'pending',
            ];
        }
    }

    public static function stayAdmissionMatrix(): iterable
    {
        foreach (['pending', 'confirmed', 'in_progress', 'completed', 'cancelled'] as $bookingStatus) {
            foreach ([false, true] as $stayExists) {
                yield "{$bookingStatus} / existing stay ".($stayExists ? 'yes' : 'no') => [
                    $bookingStatus,
                    $stayExists,
                    $bookingStatus === 'confirmed' && ! $stayExists,
                ];
            }
        }
    }

    private function states(): RespiteStateTransitionService
    {
        return new RespiteStateTransitionService($this->createStub(RespiteStayScope::class));
    }
}
