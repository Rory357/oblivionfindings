<?php

it('keeps UniFi and Milesight adapters and controllers behind the governed secret boundary', function () {
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    $adapterFiles = [
        $root.'/app/Services/Integration/Adapters/UnifiAdapter.php',
        $root.'/app/Services/Integration/Adapters/MilesightAdapter.php',
    ];
    $controllerFiles = [
        $root.'/app/Domain/SecurityDevices/Http/Controllers/Integrations/UnifiController.php',
        $root.'/app/Domain/SecurityDevices/Http/Controllers/Integrations/MilesightController.php',
    ];

    foreach ($adapterFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)
            ->toContain('IntegrationSecretMaterialService')
            ->not->toContain('Crypt::decryptString')
            ->not->toContain('->secret_encrypted');
    }
    foreach ($controllerFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)
            ->toContain('IntegrationSecretManager')
            ->not->toContain('Crypt::encryptString')
            ->not->toContain("'secret_encrypted' =>");
    }

    $siteController = file_get_contents($root.'/app/Http/Controllers/Sites/SiteIntegrationController.php');
    expect($siteController)
        ->toContain('IntegrationSecretManager')
        ->toContain('LegacyIntegrationSiteSecretWriter')
        ->not->toContain('Crypt::encryptString')
        ->not->toContain("'secret_encrypted' =>");

    $reference = file_get_contents($root.'/app/Models/Integration/IntegrationSecretReference.php');
    expect($reference)
        ->toContain("'secret_manager_reference' => 'encrypted'")
        ->toContain("'secret_manager_reference'")
        ->toContain("'secret_manager_reference_hash'")
        ->toContain("'secret_manager_fingerprint'");
});
