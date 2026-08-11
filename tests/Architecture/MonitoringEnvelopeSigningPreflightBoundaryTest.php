<?php

use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('keeps deployment and D16 release acceptance fail-closed on value-free envelope-signing readiness', function (): void {
    $command = (string) file_get_contents(base_path('app/Console/Commands/VerifyMonitoringEnvelopeSigning.php'));
    $deploy = (string) file_get_contents(base_path('scripts/deploy-server.sh'));
    $runbook = (string) file_get_contents(base_path('docs/runbooks/it-security-desktop-release-acceptance.md'));

    expect($command)
        ->toContain('EnvelopeSigner', 'activeKeyId()', '->sign(', '->verify(', 'No signing key material was emitted.')
        ->not->toContain('MONITORING_SIGNING_KEYS', 'MONITORING_SIGNING_KEY_ID')
        ->and($deploy)->toContain('php artisan monitoring:verify-envelope-signing --json')
        ->and($runbook)->toContain('monitoring:verify-envelope-signing --json', 'No signing key material is emitted.');
});
