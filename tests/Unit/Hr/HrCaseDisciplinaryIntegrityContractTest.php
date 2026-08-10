<?php

function hrCaseDisciplinarySource(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);
    expect($source)->not->toBeFalse();

    return (string) $source;
}

test('case and disciplinary mutations serialize and use only model owned compatibility persistence', function () {
    $legacyPartitionScope = 'scopeFor'.'Ten'.'ant';

    foreach ([
        'app/Http/Controllers/Hr/HrCaseController.php',
        'app/Http/Controllers/Hr/DisciplinaryController.php',
    ] as $path) {
        expect(hrCaseDisciplinarySource($path))
            ->toContain('DB::transaction')
            ->toContain('lockForUpdate()')
            ->toContain('PeopleMutationLockService')
            ->not->toContain('LegacyStorageContext');
    }

    foreach ([
        'app/Domain/Hr/Models/HrCase.php',
        'app/Domain/Hr/Models/HrDisciplinaryAction.php',
    ] as $path) {
        expect(hrCaseDisciplinarySource($path))
            ->toContain('WritesLegacyStorageContext')
            ->not->toContain($legacyPartitionScope);
    }
});
