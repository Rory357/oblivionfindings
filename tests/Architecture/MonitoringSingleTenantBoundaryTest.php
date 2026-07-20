<?php

use App\Domain\Monitoring\Data\RuntimeEnvelope;

it('keeps the new monitoring delivery boundary single tenant', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $runtimeEnvelope = new ReflectionClass(RuntimeEnvelope::class);
    $legacyRuntimeFiles = [
        $root.'/app/Domain/Monitoring/Models/Monitor.php',
        $root.'/app/Domain/Monitoring/Models/MonitoringCollector.php',
        $root.'/app/Domain/Monitoring/Models/MonitoringProfile.php',
        $root.'/app/Domain/Monitoring/Models/MonitorObservation.php',
        $root.'/app/Domain/Monitoring/Services/MonitoringObservationIngestor.php',
    ];
    $legacyMigration = $root.'/database/migrations/2026_07_18_100001_create_monitoring_foundation_tables.php';
    $legacySchemaTest = $root.'/tests/Feature/Monitoring/MonitoringSchemaTest.php';

    $runtimeFiles = collect([
        ...monitoringPhpFiles($root.'/app/Domain/Monitoring'),
        ...monitoringPhpFiles($root.'/database/migrations'),
        ...monitoringPhpFiles($root.'/tests/Feature/Monitoring'),
        ...monitoringPhpFiles($root.'/tests/Unit/Monitoring'),
        $root.'/config/features.php',
    ])->filter(function (string $file) use ($legacyRuntimeFiles, $legacyMigration, $legacySchemaTest): bool {
        if (in_array($file, [...$legacyRuntimeFiles, $legacyMigration, $legacySchemaTest], true)) {
            return false;
        }

        return ! str_contains($file, '/database/migrations/')
            || str_contains(basename($file), 'monitoring');
    })->unique()->values();

    expect($runtimeEnvelope->hasProperty('tenantId'))->toBeFalse();

    foreach ($runtimeFiles as $file) {
        $contents = file_get_contents($file);

        if ($file === $root.'/tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php') {
            $contents = str_replace([
                "Schema::hasColumn('monitoring_outbox', 'tenant_id')",
                "Schema::hasColumn('monitoring_inbox', 'tenant_id')",
                "Schema::hasColumn('monitoring_consumer_checkpoints', 'tenant_id')",
                "Schema::hasColumn('monitoring_dead_letters', 'tenant_id')",
            ], 'allowed_single_tenant_schema_absence_assertion', $contents);
        }

        expect($contents)
            ->not->toContain('tenant_id')
            ->not->toContain('tenantId')
            ->not->toContain('forTenant');
    }

    expect(file_get_contents($root.'/README.md'))
        ->toContain('single-tenant application')
        ->toContain('docs/architecture/single-tenant-application.md');
    expect(file_get_contents($root.'/config/features.php'))
        ->not->toContain('multi_tenant')
        ->not->toContain('FEATURE_SITES_MULTI_TENANT');
    expect(file_get_contents($root.'/docs/architecture/single-tenant-application.md'))
        ->toContain('It is not a multi-tenant SaaS product.')
        ->toContain('Enforce access through roles and permissions, approved sites, canonical record ownership, direct-object denial, and privacy rules.');
});

/** @return list<string> */
function monitoringPhpFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files, SORT_STRING);

    return $files;
}
