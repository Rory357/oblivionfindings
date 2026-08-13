<?php

test('normalized signal alert provenance is owned only by the canonical processor', function (): void {
    $appPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    $servicePath = $appPath.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'ControlRoom'
        .DIRECTORY_SEPARATOR.'SignalProcessingService.php';
    $signalModelPath = $appPath.DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR.'ControlRoom'
        .DIRECTORY_SEPARATOR.'Signal.php';
    $service = (string) file_get_contents($servicePath);

    expect($service)
        ->toContain("'origin_signal_id' => \$signal->id")
        ->toContain('Signal::query()->whereKey($signal->id)->lockForUpdate()')
        ->toContain('lockedOriginAlertForSignal($signal)')
        ->toContain('stageAlertNotificationsAfterCommit(')
        ->not->toContain('notifyAlert($alert, $rule, $queue)');

    $unexpectedWriters = [];
    $externalLifecycleWriters = [];
    $appFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $appPath,
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($appFiles as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getRealPath();

        if ($path === false) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $relativePath = str_replace('\\', '/', substr($path, strlen($appPath) + 1));

        if ($path !== realpath($servicePath) && str_contains($source, "'origin_signal_id' =>")) {
            $unexpectedWriters[] = $relativePath;
        }

        if (! in_array($path, [realpath($servicePath), realpath($signalModelPath)], true)
            && (str_contains($source, '->markProcessed(') || str_contains($source, '->markCorrelated('))) {
            $externalLifecycleWriters[] = $relativePath;
        }
    }

    sort($unexpectedWriters);
    sort($externalLifecycleWriters);

    expect($unexpectedWriters)->toBe([]);
    expect($externalLifecycleWriters)->toBe([]);
});

test('signal provenance schema carries native uniqueness and durable ambiguity review', function (): void {
    $migration = (string) file_get_contents(
        dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'
        .DIRECTORY_SEPARATOR.'2026_08_14_000053_enforce_control_room_signal_alert_provenance.php',
    );

    expect($migration)
        ->toContain("unique('origin_signal_id', 'cr_alerts_origin_signal_uq')")
        ->toContain("on('control_room_signals')")
        ->toContain('restrictOnDelete()')
        ->toContain("Schema::create('control_room_signal_alert_provenance_reviews'")
        ->toContain("'selection_rule' => 'active_alert_then_lowest_alert_id'")
        ->toContain("'duplicate_context_origin_claim'")
        ->not->toContain("DB::table('control_room_alerts')->delete()")
        ->not->toContain("DB::table('control_room_alerts')->whereIn('id'");
});
