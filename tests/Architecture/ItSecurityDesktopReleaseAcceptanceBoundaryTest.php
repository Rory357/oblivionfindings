<?php

it('keeps the final IT and Security release matrix deployed desktop role Site privacy and restore complete', function (): void {
    $root = dirname(__DIR__, 2);
    $runbook = (string) file_get_contents($root.'/docs/runbooks/it-security-desktop-release-acceptance.md');
    $rbac = (string) file_get_contents($root.'/database/seeders/RbacSeeder.php');
    $playwrightConfig = (string) file_get_contents($root.'/playwright.config.ts');
    $package = json_decode(
        (string) file_get_contents($root.'/package.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $expectedDesktopOnlySpecs = [
        'device-profile-acceptance',
        'facilities-workspace-acceptance',
        'healthcare-workspace-acceptance',
        'it-service-management-acceptance',
        'it-service-management-navigation',
        'native-monitoring-runtime-acceptance',
        'network-it-workspace-acceptance',
        'operations-workspaces-acceptance',
        'security-devices-accessibility',
        'security-devices-estate-operations',
        'security-devices-navigation',
        'security-devices-workspace-shell',
        'security-workspace-acceptance',
        'tracking-workspace-acceptance',
    ];

    expect($runbook)->toContain(
        'desktop/web only',
        '`1440 x 900`',
        '`1280 x 800`',
        'Oblivion Findings is one application',
        'Security & Devices owns the canonical Device register',
        'Control Room owns operational alert correlation',
        'IT owns technical work',
        'Do not use `admin@test.com`, the `admin` role, impersonation',
        'Do not repair acceptance by granting `admin`',
        '`release-requester@acceptance.invalid`',
        '`release-it-manager@acceptance.invalid`',
        '`release-it-reviewer@acceptance.invalid`',
        '`release-control-room@acceptance.invalid`',
        '`release-auditor@acceptance.invalid`',
        '`release-denied@acceptance.invalid`',
        '`release-source-denied@acceptance.invalid`',
        '`RELEASE Site Alpha`',
        '`RELEASE Site Hidden`',
        '`RELEASE Hidden Device`',
        '`it.organisationWide`',
        '`securityDevices.devices.viewAllSites`',
    )->and($rbac)->toContain(
        "['name' => 'it_manager'",
        "['name' => 'support_worker'",
        "['name' => 'coordinator'",
        "['name' => 'auditor'",
        "['name' => 'finance'",
    );

    expect(preg_match_all('/^\| D\d{2} \|/m', $runbook))->toBe(18)
        ->and($runbook)->toContain(
            '/it?tab=tickets',
            '/it?tab=provisioning',
            '/it/problems/{problem}',
            '/it/changes/{change}',
            '/it/major-incidents/{major-incident}',
            '/security-devices/network-it?tab=map',
            '/security-devices/security?tab=cctv',
            '/security-devices/healthcare?tab=client-devices',
            '/security-devices/tracking?tab=personal-safety',
            '/security-devices/facilities-iot?tab=environment',
            '/security-devices/monitoring',
            '/security-devices/runtime-health',
            '/security-devices/discovery?tab=scopes',
            '/security-devices/maintenance?tab=due',
            '/security-devices/integrations/unifi',
            '/security-devices/integrations/milesight',
            '/security-devices/integrations/queclink',
            '/sites/{alpha-site}?tab=technology',
            '/operations/clients/{alpha-client}?tab=healthcare_devices',
            '/operations/clients/{alpha-client}?tab=location',
            '/hr/people/{alpha-employee-profile}?tab=assets',
            '/fleet-assets/vehicles/{alpha-vehicle}?tab=technology',
            '/control-room/alerts/{alpha-alert}',
            '/fleet-assets/resident-tracking/history/{alpha-client}',
        );

    expect($runbook)->toContain(
        'A Hidden-Site direct object is concealed as `404`',
        'A missing parent-module permission returns `403`',
        'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
        'There is no uncaught exception, `console.error`',
        'Browser source, Inertia page props, Fetch/XHR bodies',
        'Withdraw personal-tracking consent',
        'Never place a production identifier into this runbook',
        '[Protocol and policy release acceptance](monitoring/protocol-policy-release-acceptance.md)',
        '[Runtime or regional outage](monitoring/runtime-and-regional-outage.md)',
        '[Collector outage and revocation](monitoring/collector-outage-and-revocation.md)',
        '[Monitoring storage restore](monitoring/storage-restore.md)',
        '**restored-environment browser evidence**',
        'A rendered trend, snapshot row, or green UI badge does not by itself prove',
    );

    $deployedProof = strpos($runbook, '## Deployed browser proof');
    $localEvidence = strpos($runbook, '## Local automated evidence');

    expect($deployedProof)
        ->not->toBeFalse()
        ->and($localEvidence)->toBeGreaterThan($deployedProof)
        ->and($runbook)->toContain(
            'This runbook does not close a release from local tests',
            '`--skip-monitoring-supervisor` skips only the configuration installation',
            'must observe three consecutive samples of all eight worker groups and all three',
            'listeners with their exact process counts in `RUNNING` state',
            'command must reference the exact deployed `artisan` path',
            'queue or listener command. This check runs after the final queue restart',
            'stale, wrong-release, partially restarted, or inaccessible runtime blocks the',
            'Local Unit, Feature, Architecture, React, type, lint/format, client-build, SSR-build, and local Dusk results are prerequisite regression evidence only',
            'Never relabel a local Dusk result as deployed browser proof',
            'V10 remains open unless D01-D18 pass at both viewports against the deployed release',
        );

    $inventoryMatch = [];
    expect(preg_match(
        '/const itSecurityDesktopOnlySpecs = \[(.*?)\];/s',
        $playwrightConfig,
        $inventoryMatch,
    ))->toBe(1);

    $inventoryNames = [];
    expect(preg_match_all("/'([^']+)'/", $inventoryMatch[1], $inventoryNames))->toBe(14)
        ->and($inventoryNames[1])->toBe($expectedDesktopOnlySpecs);

    foreach ($expectedDesktopOnlySpecs as $desktopOnlySpec) {
        expect(is_file($root.'/tests/e2e/'.$desktopOnlySpec.'.spec.ts'))->toBeTrue();
    }

    expect($package['scripts']['visual:test:it-security'] ?? null)
        ->toBe('playwright test -c playwright.config.ts --project=it-security-desktop-1440 --project=it-security-desktop-1280')
        ->and($runbook)->toContain(
            'npm run visual:test:it-security',
            'The retained legacy mobile project remains outside IT/Security acceptance.',
        )
        ->and($playwrightConfig)->toContain(
            "name: 'it-security-desktop-1440'",
            "name: 'it-security-desktop-1280'",
            "name: 'chromium-desktop'",
            "name: 'chromium-mobile'",
            "devices['Pixel 7']",
        )
        ->and(substr_count($playwrightConfig, 'testMatch: itSecurityDesktopOnlyTestMatch'))->toBe(2)
        ->and(substr_count($playwrightConfig, 'testIgnore: itSecurityDesktopOnlyTestMatch'))->toBe(2)
        ->and(preg_match(
            "/name: 'chromium-desktop',\\s+testIgnore: itSecurityDesktopOnlyTestMatch,\\s+use:/s",
            $playwrightConfig,
        ))->toBe(1)
        ->and(preg_match(
            "/name: 'it-security-desktop-1440',\\s+testMatch: itSecurityDesktopOnlyTestMatch,\\s+use:.*?viewport: \\{ width: 1440, height: 900 \\}/s",
            $playwrightConfig,
        ))->toBe(1)
        ->and(preg_match(
            "/name: 'it-security-desktop-1280',\\s+testMatch: itSecurityDesktopOnlyTestMatch,\\s+use:.*?viewport: \\{ width: 1280, height: 800 \\}/s",
            $playwrightConfig,
        ))->toBe(1)
        ->and(preg_match(
            "/name: 'chromium-mobile',\\s+testIgnore: itSecurityDesktopOnlyTestMatch,\\s+use: \\{ \.\.\.devices\['Pixel 7'\] \\}/s",
            $playwrightConfig,
        ))->toBe(1);

    foreach ([
        'device-profile-acceptance.spec.ts',
        'it-service-management-navigation.spec.ts',
        'security-devices-navigation.spec.ts',
    ] as $desktopOnlySpec) {
        $source = (string) file_get_contents($root.'/tests/e2e/'.$desktopOnlySpec);

        expect($source)->not->toMatch('/\\bmobile\\b/i');
    }

    $nativeMonitoringSource = (string) file_get_contents(
        $root.'/tests/e2e/native-monitoring-runtime-acceptance.spec.ts',
    );

    expect($nativeMonitoringSource)
        ->not->toContain('page.setViewportSize(')
        ->toContain(
            "projectName === 'it-security-desktop-1440'",
            "projectName === 'it-security-desktop-1280'",
            'approvedDesktopViewport(testInfo.project.name)',
        );
});
