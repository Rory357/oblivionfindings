<?php

test('H&S terminal mutation has one canonical aggregate owner', function (): void {
    $root = dirname(__DIR__, 2);
    $app = $root.DIRECTORY_SEPARATOR.'app';
    $servicePath = $app.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'HealthSafety'
        .DIRECTORY_SEPARATOR.'HsEventClosureService.php';
    $controller = (string) file_get_contents(
        $app.DIRECTORY_SEPARATOR.'Http'.DIRECTORY_SEPARATOR.'Controllers'
        .DIRECTORY_SEPARATOR.'HealthSafety'.DIRECTORY_SEPARATOR.'HsEventController.php',
    );
    $legacyService = (string) file_get_contents(
        $app.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'HealthSafety'
        .DIRECTORY_SEPARATOR.'HsEventService.php',
    );
    $canonicalService = (string) file_get_contents($servicePath);
    $unexpectedWriters = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $app,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getRealPath();
        if ($path === false || $path === realpath($servicePath)) {
            continue;
        }
        $source = (string) file_get_contents($path);
        if (str_contains($source, "'status' => HsEvent::STATUS_CLOSED,")) {
            $unexpectedWriters[] = str_replace('\\', '/', substr($path, strlen($app) + 1));
        }
    }

    sort($unexpectedWriters);

    expect($unexpectedWriters)->toBe([])
        ->and($legacyService)
        ->not->toContain('function closeEvent(')
        ->not->toContain('function closureGate(')
        ->and($controller)
        ->toContain('$this->closures->closeEvent(')
        ->not->toContain("'override_reason'")
        ->and($canonicalService)
        ->toContain('HsEvent::query()->lockForUpdate()->findOrFail($event->id)')
        ->toContain("'status' => HsEvent::STATUS_CLOSED,")
        ->toContain('AuditLogger::logOrFail(');
});

test('H&S close routes and schema enforce explicit authority and immutable provenance', function (): void {
    $root = dirname(__DIR__, 2);
    $routes = (string) file_get_contents($root.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'health-safety.php');
    $migration = (string) file_get_contents(
        $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'
        .DIRECTORY_SEPARATOR.'2026_08_14_000061_create_hs_closure_exception_authority.php',
    );
    $seeder = (string) file_get_contents(
        $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'seeders'.DIRECTORY_SEPARATOR.'RbacSeeder.php',
    );

    expect($routes)
        ->toContain("Route::middleware('permission:healthSafety.events.close')")
        ->toContain("Route::middleware('permission:healthSafety.closureExceptions.request')")
        ->toContain("Route::middleware('permission:healthSafety.closureExceptions.approve')")
        ->and($migration)
        ->toContain('CREATE TRIGGER hs_events_close_path_guard')
        ->toContain('@hs_canonical_close_event_id')
        ->toContain('Closed H&S event provenance is immutable')
        ->toContain('H&S closure exception provenance is append-only')
        ->toContain("where('worksafe_site_preserved', true)")
        ->not->toContain("where('worksafe_site_preserved', false)")
        ->and($seeder)
        ->toContain('$restrictedHsClosureAuthority')
        ->toContain("'healthSafety.closureExceptions.approve',")
        ->toContain('// Explicit product policy: Compliance Lead is the independent,');
});

test('historical cross-module lifecycle integrations cannot close H&S events', function (): void {
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR;
    $firstAid = (string) file_get_contents($root.'Observers'.DIRECTORY_SEPARATOR.'FirstAidObserver.php');
    $safeguarding = (string) file_get_contents(
        $root.'Http'.DIRECTORY_SEPARATOR.'Controllers'.DIRECTORY_SEPARATOR.'SafeguardingConcernController.php',
    );

    expect($firstAid)
        ->not->toContain("'status' => HsEvent::STATUS_CLOSED")
        ->toContain('healthSafety.event.firstAidSupersessionRecorded')
        ->and($safeguarding)
        ->not->toContain("'status' => HsEvent::STATUS_CLOSED");
});
