<?php

it('hard disables the table-only command without any filesystem publication path', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $command = file_get_contents($root.'/app/Console/Commands/DumpSchemaPortable.php');

    expect($command)
        ->toContain('Portable schema dump is deprecated and disabled before any output directory or file is created.')
        ->toContain('return self::FAILURE;')
        ->not->toContain(
            'ensureDirectoryExists',
            'tempnam(',
            'fopen(',
            'fwrite(',
            'file_put_contents(',
            'rename(',
            'chmod(',
            'GET_LOCK',
            'RELEASE_LOCK',
            'SHOW GRANTS',
            'CREATE TRIGGER',
            'SHOW CREATE TRIGGER',
        );
});

it('audits unsupported ddl across the laravel migrator source paths before refusal', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $command = file_get_contents($root.'/app/Console/Commands/DumpSchemaPortable.php');
    $inventory = file_get_contents($root.'/app/Support/Database/PortableSchemaUnsupportedObjectInventory.php');

    expect($command)
        ->toContain('app(Migrator::class)->paths()')
        ->toContain('$audit = $inventory->audit($this->migrationSources())')
        ->toContain('the audited unsupported schema-object manifest is non-empty')
        ->toContain('$this->migrationRepositoryExists($connection, $migrationTable)')
        ->toContain("\$connection->getTablePrefix() !== ''")
        ->and($inventory)
        ->toContain("'2026_08_06_000041_retain_device_relationship_history'")
        ->toContain("'trigger' => 3")
        ->toContain("'2026_08_06_000047_enforce_monitoring_evidence_lifecycle'")
        ->toContain("'trigger' => 13")
        ->toContain('unsupported schema-object migrations missing from manifest')
        ->toContain('manifest object counts do not match migration sources');
});

it('retains the pdo delimiter limitation as the reason portable output cannot be produced', function (): void {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $command = file_get_contents($root.'/app/Console/Commands/DumpSchemaPortable.php');
    $loader = file_get_contents($root.'/tests/TestCase.php');

    expect($loader)
        ->toContain("preg_match('/;\\s*$/', rtrim(\$line))")
        ->and($command)
        ->toContain('pure-PDO schema loader is not delimiter-aware')
        ->toContain('table-only serializer and PDO loader do not support triggers, views, procedures, or functions');
});
