<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;

function clockedOutAttendanceSession(): HrAttendanceSession
{
    $session = new class extends HrAttendanceSession
    {
        protected $dateFormat = 'Y-m-d H:i:s';
    };
    $session->setRawAttributes([
        'clock_in_at' => now()->subHours(8)->format('Y-m-d H:i:s'),
        'clock_out_at' => now()->format('Y-m-d H:i:s'),
    ]);

    return $session;
}

test('routine clock out defers completion without manufacturing a handover waiver', function () {
    $data = CompleteShiftData::fromClockOutSession(clockedOutAttendanceSession());

    expect($data->handoverWaiverReason)->toBeNull()
        ->and($data->autoWaiveHandover)->toBeFalse()
        ->and($data->deferCompletionUntilHandoverSubmitted)->toBeTrue();
});

test('forced clock out carries only its explicit waiver reason and never defers completion', function () {
    $session = clockedOutAttendanceSession();

    $withReason = CompleteShiftData::fromClockOutSession(
        $session,
        true,
        'Manager approved the documented exception.',
    );
    $withoutReason = CompleteShiftData::fromClockOutSession($session, true);

    expect($withReason->handoverWaiverReason)->toBe('Manager approved the documented exception.')
        ->and($withReason->autoWaiveHandover)->toBeFalse()
        ->and($withReason->deferCompletionUntilHandoverSubmitted)->toBeFalse()
        ->and($withoutReason->handoverWaiverReason)->toBeNull()
        ->and($withoutReason->autoWaiveHandover)->toBeFalse()
        ->and($withoutReason->deferCompletionUntilHandoverSubmitted)->toBeFalse();
});
