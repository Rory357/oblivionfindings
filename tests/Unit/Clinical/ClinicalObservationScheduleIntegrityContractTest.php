<?php

function clinicalObservationScheduleSource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
    expect($source)->not->toBeFalse();

    return (string) $source;
}

test('both observation surfaces pass an opaque schedule id to the canonical command', function () {
    foreach ([
        'app/Http/Controllers/Clinical/Concerns/RecordsClinicalRecords.php',
        'app/Http/Controllers/Clinical/ShiftClinicalController.php',
    ] as $path) {
        expect(clinicalObservationScheduleSource($path))
            ->toContain("'protocol_schedule_id' => ['nullable', 'integer']")
            ->not->toContain('exists:clinical_protocol_schedules,id');
    }
});

test('the canonical observation command owns locked transactional schedule completion', function () {
    $source = clinicalObservationScheduleSource(
        'app/Domain/Clinical/Services/ClinicalObservationService.php',
    );

    expect(substr_count($source, 'public function record('))->toBe(1)
        ->and($source)
        ->toContain('DB::transaction')
        ->toContain('resolvePendingProtocolSchedule(')
        ->toContain("->whereHas('schedules'")
        ->toContain('$protocol->schedules()')
        ->toContain('lockForUpdate()')
        ->toContain("'protocol_schedule_id' => \$schedule?->id")
        ->not->toContain('ClinicalProtocolSchedule::find(');
});
