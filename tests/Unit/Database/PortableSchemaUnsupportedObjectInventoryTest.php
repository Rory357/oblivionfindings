<?php

use App\Support\Database\PortableSchemaUnsupportedObjectInventory;

function portableSchemaMigrationSources(): array
{
    $root = dirname(__DIR__, 3);
    $sources = [];

    foreach (glob($root.'/database/migrations/*_*.php') ?: [] as $path) {
        $sources[pathinfo($path, PATHINFO_FILENAME)][] = [
            'path' => $path,
            'source' => file_get_contents($path),
        ];
    }

    return $sources;
}

it('keeps every known unsupported schema object migration in the audited manifest', function (): void {
    $inventory = new PortableSchemaUnsupportedObjectInventory;
    $audit = $inventory->audit(portableSchemaMigrationSources());

    expect($audit['blockers'])->toBe([])
        ->and($audit['discovered'])->toBe(PortableSchemaUnsupportedObjectInventory::MANIFEST)
        ->and(array_sum(array_column($audit['discovered'], 'trigger')))->toBe(16);
});

it('recognises precise ddl variants and php-composed object keywords', function (): void {
    $inventory = new PortableSchemaUnsupportedObjectInventory;
    $source = <<<'PHP'
        <?php

        DB::unprepared('CRE'.'ATE OR REPLACE DEFINER=`app`@`localhost` TRI'.'GGER audit_guard BEFORE INSERT ON records FOR EACH ROW SET NEW.id = NEW.id');
        DB::statement('CREATE OR REPLACE ALGORITHM=MERGE DEFINER=`app`@`localhost` SQL SECURITY INVOKER VIEW current_records AS SELECT * FROM records');
        DB::unprepared('create definer=current_user procedure refresh_records() select 1');
        DB::unprepared('CREATE DEFINER=current_user FUNCTION record_count() RETURNS INT RETURN 1');
        PHP;

    expect($inventory->schemaObjectCounts($source))->toBe([
        'function' => 1,
        'procedure' => 1,
        'trigger' => 1,
        'view' => 1,
    ]);
});

it('does not treat domain words or php comments as schema object ddl', function (): void {
    $inventory = new PortableSchemaUnsupportedObjectInventory;
    $source = <<<'PHP'
        <?php

        // CREATE TRIGGER documentation_example
        /* CREATE VIEW historical_example */
        Schema::create('trigger_events', function (Blueprint $table): void {
            $table->string('viewed_functionality');
            $table->string('procedure_notes');
        });
        PHP;

    expect($inventory->schemaObjectCounts($source))->toBe([]);
});

it('fails manifest audit for missing duplicate unlisted or count-drifted sources', function (): void {
    $inventory = new PortableSchemaUnsupportedObjectInventory;
    $sources = portableSchemaMigrationSources();
    unset($sources['2026_08_06_000041_retain_device_relationship_history']);
    $sources['2026_08_06_000047_enforce_monitoring_evidence_lifecycle'][] = [
        'path' => 'duplicate.php',
        'source' => '<?php return true;',
    ];
    $sources['2099_01_01_000000_unlisted_view'][] = [
        'path' => 'unlisted.php',
        'source' => "<?php DB::statement('CREATE VIEW unexpected_view AS SELECT 1');",
    ];

    expect($inventory->audit($sources)['blockers'])->toBe([
        'duplicate migration source files [2026_08_06_000047_enforce_monitoring_evidence_lifecycle]',
        'manifest migrations without source files [2026_08_06_000041_retain_device_relationship_history]',
        'unsupported schema-object migrations missing from manifest [2099_01_01_000000_unlisted_view]',
        'manifest object counts do not match migration sources [2026_08_06_000041_retain_device_relationship_history, 2026_08_06_000047_enforce_monitoring_evidence_lifecycle]',
    ]);
});

it('refuses missing prefixed qualified or unsafe migration repositories', function (): void {
    $inventory = new PortableSchemaUnsupportedObjectInventory;

    expect($inventory->migrationRepositoryBlockers('migrations', '', true))->toBe([])
        ->and($inventory->migrationRepositoryBlockers('migrations', '', false))->toBe([
            'the configured migration repository is missing or cannot be verified',
        ])
        ->and($inventory->migrationRepositoryBlockers('migrations', 'app_', true))->toBe([
            'connection table prefixes are unsupported',
        ])
        ->and($inventory->migrationRepositoryBlockers('audit.migrations', '', false))->toBe([
            'schema-qualified migration repositories are unsupported',
            'the configured migration repository is missing or cannot be verified',
        ])
        ->and($inventory->migrationRepositoryBlockers('`migrations`', '', false))->toBe([
            'the configured migration repository identifier is unsupported',
            'the configured migration repository is missing or cannot be verified',
        ]);
});
