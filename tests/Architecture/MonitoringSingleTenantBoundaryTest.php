<?php

use App\Domain\Monitoring\Data\RuntimeEnvelope;

it('keeps the new monitoring delivery boundary single tenant', function () {
    $root = dirname(__DIR__, 2);
    $runtimeEnvelope = new ReflectionClass(RuntimeEnvelope::class);
    $runtimeFiles = [
        $root.'/app/Domain/Monitoring/Data/RuntimeEnvelope.php',
        $root.'/app/Domain/Monitoring/Services/RuntimeEnvelopeCodec.php',
        $root.'/app/Domain/Monitoring/Models/MonitoringOutbox.php',
        $root.'/app/Domain/Monitoring/Models/MonitoringInbox.php',
        $root.'/app/Domain/Monitoring/Models/MonitoringDeadLetter.php',
        $root.'/app/Domain/Monitoring/Models/MonitoringConsumerCheckpoint.php',
        $root.'/database/migrations/2026_07_21_100001_create_monitoring_delivery_tables.php',
        $root.'/tests/Feature/Monitoring/RuntimeEnvelopePersistenceTest.php',
    ];

    expect($runtimeEnvelope->hasProperty('tenantId'))->toBeFalse();

    foreach ($runtimeFiles as $file) {
        $contents = file_get_contents($file);

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
