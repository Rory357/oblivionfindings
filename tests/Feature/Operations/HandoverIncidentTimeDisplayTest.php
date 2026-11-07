<?php

use App\Services\Operations\HandoverPresenter;

// .com 7 Nov 2026: the handover pop-up listed a shift incident as
// "behaviour draft low 2026-05-02T03:30:17.000000Z". The stored UTC instant
// now reads in NZ time, and plain text items are left exactly as written.
it('shows a shift incident time in NZ time, not as a raw ISO timestamp', function () {
    config(['app.worker_timezone' => 'Pacific/Auckland']);

    $lines = app(HandoverPresenter::class)->listToDisplayStrings([
        ['id' => 52, 'type' => 'behaviour', 'status' => 'draft', 'severity' => 'low', 'occurred_at' => '2026-05-02T03:30:17.000000Z'],
        ['id' => 53, 'type' => 'fall', 'status' => 'open', 'severity' => 'high', 'occurred_at' => '2026-11-06T20:05:00+00:00'],
        ['id' => 54, 'type' => 'medication', 'status' => 'open', 'severity' => 'medium', 'occurred_at' => null],
        ['label' => 'Near fall overnight', 'occurred_at' => '2026-05-02T03:30:17.000000Z'],
        'Checked in at 2026-05-02T03:30 with whānau',
    ]);

    expect($lines)->toBe([
        'behaviour draft low · Sat 2 May 2026, 3:30 pm',
        'fall open high · Sat 7 Nov 2026, 9:05 am',
        'medication open medium',
        'Near fall overnight',
        'Checked in at 2026-05-02T03:30 with whānau',
    ])->and(implode(' ', $lines))->not->toContain('.000000Z');
});
