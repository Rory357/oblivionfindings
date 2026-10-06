<?php

use App\Services\Medication\Audit\MedicationEventFingerprint;

it('keeps stored JSON values stable and detects changed facts or list order', function () {
    $original = ['previous_hash' => str_repeat('0', 64), 'facts' => ['amount' => 1.0, 'name' => 'Kōwhai', 'steps' => ['given', 'checked']]];
    $stored = json_decode(json_encode($original, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    expect(MedicationEventFingerprint::of($original))->toBe(MedicationEventFingerprint::of($stored));
    expect(MedicationEventFingerprint::of(['facts' => $stored['facts'], 'previous_hash' => $stored['previous_hash']]))->toBe(MedicationEventFingerprint::of($original));
    $stored['facts']['steps'] = ['checked', 'given'];
    expect(MedicationEventFingerprint::of($stored))->not->toBe(MedicationEventFingerprint::of($original));
});

it('rejects non JSON facts instead of silently changing an audit payload', function () {
    MedicationEventFingerprint::of(['secret' => new stdClass]);
})->throws(InvalidArgumentException::class);
