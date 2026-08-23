<?php

it('keeps certification and first aid hero claims behind the semantic resolver', function (): void {
    $snapshot = file_get_contents(base_path('app/Support/HazardComplianceSnapshot.php'));
    $hero = file_get_contents(base_path('resources/js/pages/health-safety/components/hs-hero-kit.tsx'));
    $siteCompliance = file_get_contents(base_path('resources/js/pages/sites/compliance/Index.tsx'));

    expect($snapshot)
        ->not->toContain('nga_paerewa_certified')
        ->not->toContain('first_aid_ok')
        ->and($hero)
        ->not->toContain('ngaPaerewaCertified = true')
        ->not->toContain('firstAidOk = true')
        ->toContain("'Evidence unknown'")
        ->toContain("'First aid · Cover unknown'")
        ->and($siteCompliance)
        ->toContain('displayedCertificationStatus')
        ->toContain('assurance.certification_status')
        ->toContain("'Evidence unknown'");

    foreach ([
        'app/Http/Controllers/HealthSafety/EmergencyDrillController.php',
        'app/Http/Controllers/HealthSafety/HazardousSubstanceController.php',
        'app/Http/Controllers/HealthSafety/RestraintController.php',
    ] as $path) {
        expect(file_get_contents(base_path($path)))->not->toContain('nga_paerewa_certified');
    }
});

it('keeps missing clinical assurance KPIs neutral and explicit', function (): void {
    $clinicalShell = file_get_contents(
        base_path('resources/js/pages/health-clinical/components/health-clinical-shell.tsx'),
    );

    expect($clinicalShell)
        ->not->toContain('restraint_register_current ?? true')
        ->not->toContain('clients_on_watch ?? 0')
        ->not->toContain('events_unreviewed ?? 0')
        ->toContain('restraintCurrent === null')
        ->toContain('clientsOnWatch === null')
        ->toContain('eventsUnreviewed === null')
        ->toContain("ngaStatus === 'certified' ? ShieldCheck : AlertTriangle")
        ->toContain('signOff === null || signOff > 0')
        ->toContain("'Deterioration watch · Evidence unknown'")
        ->toContain("'Sign-off · Evidence unknown'")
        ->toContain("'Restraint register · Evidence unknown'");
});
