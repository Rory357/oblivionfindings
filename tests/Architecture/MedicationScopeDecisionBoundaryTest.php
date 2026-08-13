<?php

it('keeps every medication administration controller behind the authoritative scope decision', function () {
    $controllers = [
        'app/Http/Controllers/Api/MedicationsApiController.php',
        'app/Http/Controllers/ClientMedicalController.php',
        'app/Http/Controllers/Emar/GuidedRoundController.php',
        'app/Http/Controllers/Emar/WorkerMedsController.php',
        'app/Http/Controllers/MyDayMedicationsController.php',
    ];

    foreach ($controllers as $controller) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$controller);

        expect($source)
            ->toContain('MedicationScopeDecisionService')
            ->toContain('->forAdministration(')
            ->toContain('->recordAdministration(');
    }
});

it('keeps prescription medication and prn effectiveness mutations on the same scope boundary', function () {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Http/Controllers/Emar/EmarController.php',
    );

    expect($source)
        ->toContain('MedicationScopeDecisionService')
        ->toContain('->forClient(')
        ->toContain('->forPrescription(')
        ->toContain('->forMedication(')
        ->toContain('->forPrnEffectiveness(');
});

it('does not reintroduce nullable best effort shift lookup in medication write controllers', function () {
    $sources = collect([
        'app/Http/Controllers/Api/MedicationsApiController.php',
        'app/Http/Controllers/ClientMedicalController.php',
        'app/Http/Controllers/Emar/GuidedRoundController.php',
        'app/Http/Controllers/Emar/WorkerMedsController.php',
        'app/Http/Controllers/MyDayMedicationsController.php',
    ])->map(
        fn (string $path): string => (string) file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path),
    )->implode("\n");

    expect($sources)->not->toContain('activeShiftIdFor(');

    $worker = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Http/Controllers/Emar/WorkerMedsController.php',
    );
    expect($worker)
        ->not->toContain('exists:client_medication_administrations,id')
        ->not->toContain('exists:client_medications,id');
});
