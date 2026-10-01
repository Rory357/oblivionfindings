<?php

/*
 * P01 foundation C3: the dose-slot outcome is written from the
 * administration model's own events, so it can only be bypassed by a write
 * that skips them. These guards pin every place an administration is
 * persisted, and the only writers of the slot table.
 */

function doseSlotAppSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    $sources = [];
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }
    }
    ksort($sources);

    return $sources;
}

it('syncs the slot outcome from every administration model event that can change evidence', function (): void {
    $model = (string) file_get_contents(dirname(__DIR__, 2).'/app/Models/ClientMedicationAdministration.php');

    expect($model)->toContain(
        'app(DoseSlotOutcomeWriter::class)->syncFor($administration)',
        'static::saving($lockOrder);',
        'static::deleting($lockOrder);',
        'static::restoring($lockOrder);',
        'static::forceDeleting($lockOrder);',
        'static::saved($sync);',
        'static::deleted($sync);',
        'static::restored($sync);',
        'static::forceDeleted($sync);',
        'app(DoseSlotOutcomeWriter::class)->lockOrderOf($administration)',
    );
});

it('locks the order row before any administration row in the outcome writer', function (): void {
    $writer = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Medication/DoseSlots/DoseSlotOutcomeWriter.php');
    $sync = substr($writer, (int) strpos($writer, 'public function syncFor('));
    $rootRead = substr($sync, (int) strpos($sync, '$root = '), (int) strpos($sync, '?? ($rootId') - (int) strpos($sync, '$root = '));
    $orderLock = strpos($sync, "whereKey(\$root->client_medication_id)->lockForUpdate()");
    $evidenceLock = strpos($sync, '$this->lockEvidence(');

    expect($rootRead)->not->toContain('lockForUpdate')
        ->and($orderLock)->toBeInt()
        ->and($evidenceLock)->toBeInt()
        ->and($orderLock < $evidenceLock)->toBeTrue();
});

it('persists administrations only through the recording service and the two correction paths', function (): void {
    $creators = [];
    $replicators = [];
    $rawWrites = [];
    foreach (doseSlotAppSources() as $path => $source) {
        if (str_contains($source, 'new ClientMedicationAdministration;')
            && preg_match('/new ClientMedicationAdministration;\s*\n\s*\$\w+->(?!setAttribute\(\$\w+->getKeyName\(\))/', $source) === 1) {
            $creators[] = $path;
        }
        if (preg_match('/ClientMedicationAdministration::(?:query\(\)\s*->\s*)?(?:create|forceCreate|insert|insertOrIgnore|upsert|updateOrCreate|firstOrCreate)\(/', $source) === 1) {
            $creators[] = $path;
        }
        if (preg_match('/\$\w*[aA]dministration\w*->replicate\(/', $source) === 1) {
            $replicators[] = $path;
        }
        if (preg_match("/table\\(\\s*'client_medication_administrations'\\s*\\)[^;]*->(?:insert|update|delete|upsert|truncate)\\(/s", $source) === 1) {
            $rawWrites[] = $path;
        }
    }

    expect(array_values(array_unique($creators)))->toBe(['app/Services/EnhancedMarService.php'])
        ->and($replicators)->toBe([
            'app/Http/Controllers/Api/MedicationsApiController.php',
            'app/Http/Controllers/MedicationAdministrationCorrectionController.php',
        ])
        ->and($rawWrites)->toBe([]);
});

it('writes the slot table only from the generator and the outcome writer', function (): void {
    $writers = [];
    foreach (doseSlotAppSources() as $path => $source) {
        if (preg_match("/MedicationDoseSlot::|table\\(\\s*'medication_dose_slots'\\s*\\)/", $source) === 1
            && preg_match('/->(?:insert|insertOrIgnore|upsert|update|delete|create|forceCreate|save)\(/', $source) === 1) {
            $writers[] = $path;
        }
    }

    expect($writers)->toBe([
        'app/Services/Medication/DoseSlots/DoseSlotGenerator.php',
        'app/Services/Medication/DoseSlots/DoseSlotOutcomeWriter.php',
    ]);
});
