<?php

use App\Http\Controllers\Hr\DirectoryController;

function directoryPhotoMethodSource(string $method): string
{
    $reflection = new ReflectionMethod(DirectoryController::class, $method);
    $lines = file($reflection->getFileName());

    return implode('', array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

test('directory photos have a dedicated private storage and migration boundary', function () {
    $root = dirname((new ReflectionClass(DirectoryController::class))->getFileName(), 5);
    $service = $root.'/app/Domain/Hr/Services/HrProfilePhotoStorageService.php';
    $command = $root.'/app/Console/Commands/MigrateHrProfilePhotosToPrivate.php';

    expect($service)->toBeFile()
        ->and($command)->toBeFile();

    $serviceSource = file_get_contents($service);
    $commandSource = file_get_contents($command);

    expect($serviceSource)
        ->toContain("PRIVATE_DISK = 'private'")
        ->toContain("LEGACY_DISK = 'public'")
        ->toContain('migrateToPrivate')
        ->toContain('rollbackToPublic')
        ->toContain('publicResidueCount')
        ->and($commandSource)
        ->toContain('hr:profile-photos:migrate-private')
        ->toContain('{--rollback')
        ->toContain('public_residue')
        ->toContain("'skipped'")
        ->toContain('HrProfilePhotoStorageService::MISSING')
        ->toContain('HrProfilePhotoStorageService::INVALID');
});

test('profile photo writes use one database attempt and cannot delete a committed new file', function () {
    $upload = directoryPhotoMethodSource('uploadPhoto');

    expect($upload)
        ->toContain('HrProfilePhotoStorageService')
        ->toContain('}, 1);')
        ->toContain('$committed')
        ->toContain('if (! $committed)')
        ->not->toContain('}, 3);')
        ->not->toContain("Storage::disk('public')");
});

test('directory cards resolve only the employee photo used by the modal', function () {
    $show = directoryPhotoMethodSource('show');

    expect(substr_count($show, "'profile_photo_url'"))->toBe(1);
});
