<?php

use App\Support\Release\ItSecurityDesktopReleaseFixtureMutationGuard;

function validDesktopFixtureMutationContext(): array
{
    $database = 'oblivion_acceptance';

    return [
        'environment' => 'staging',
        'platform' => 'Linux',
        'enabled' => true,
        'environment_class' => 'approved_non_production',
        'database_driver' => 'mysql',
        'database_name' => $database,
        'database_name_sha256' => hash('sha256', $database),
        'checkout' => '/var/www/oblivionfindings',
    ];
}

it('authorises only an exact non-production database revision and action confirmation', function (): void {
    $revision = str_repeat('a', 40);
    $calls = [];
    $guard = new ItSecurityDesktopReleaseFixtureMutationGuard(
        function (string $checkout, string $expectedRevision) use (&$calls, $revision): bool {
            $calls[] = [$checkout, $expectedRevision];

            return $checkout === '/var/www/oblivionfindings' && $expectedRevision === $revision;
        },
    );

    $report = $guard->assess(
        'prepare',
        $revision,
        ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken('prepare', $revision),
        validDesktopFixtureMutationContext(),
    );

    expect($report)->toMatchArray([
        'state' => 'authorised',
        'action' => 'prepare',
        'release_revision' => $revision,
        'gap_codes' => [],
        'fixture_write_authorized' => true,
        'v10_release_evidence' => false,
    ])->and(array_values(array_unique($report['checks'])))->toBe([true])
        ->and($calls)->toBe([['/var/www/oblivionfindings', $revision]]);

    $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    expect($encoded)
        ->not->toContain('oblivion_acceptance')
        ->not->toContain('/var/www/oblivionfindings')
        ->not->toContain(ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken('prepare', $revision));
});

it('fails closed for every environment database checkout and confirmation boundary', function (
    string $failure,
    string $expectedGap,
): void {
    $revision = str_repeat('b', 40);
    $action = 'prepare';
    $confirmation = ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken($action, $revision);
    $context = validDesktopFixtureMutationContext();
    $checkoutValid = true;

    match ($failure) {
        'unsupported action' => $action = 'repair',
        'production' => $context['environment'] = 'production',
        'staging on unapproved platform' => $context['platform'] = 'Windows',
        'disabled' => $context['enabled'] = false,
        'string enabled' => $context['enabled'] = 'true',
        'unapproved class' => $context['environment_class'] = 'staging',
        'wrong driver' => $context['database_driver'] = 'sqlite',
        'wrong database pin' => $context['database_name_sha256'] = str_repeat('0', 64),
        'invalid revision' => $revision = 'main',
        'dirty or wrong checkout' => $checkoutValid = false,
        'wrong confirmation' => $confirmation = 'CONFIRM',
    };

    $report = (new ItSecurityDesktopReleaseFixtureMutationGuard(
        static fn (): bool => $checkoutValid,
    ))->assess($action, $revision, $confirmation, $context);

    expect($report['state'])->toBe('refused')
        ->and($report['fixture_write_authorized'])->toBeFalse()
        ->and($report['v10_release_evidence'])->toBeFalse()
        ->and($report['gap_codes'])->toContain($expectedGap);
})->with([
    'unsupported action' => ['unsupported action', 'release_fixture_mutation_action_not_allowed'],
    'production' => ['production', 'release_fixture_environment_not_approved'],
    'staging on unapproved platform' => ['staging on unapproved platform', 'release_fixture_environment_not_approved'],
    'disabled' => ['disabled', 'release_fixture_mutation_not_enabled'],
    'string enabled is not explicit' => ['string enabled', 'release_fixture_mutation_not_enabled'],
    'unapproved environment class' => ['unapproved class', 'release_fixture_environment_class_not_approved'],
    'wrong database driver' => ['wrong driver', 'release_fixture_database_driver_not_approved'],
    'wrong database pin' => ['wrong database pin', 'release_fixture_database_pin_mismatch'],
    'invalid revision' => ['invalid revision', 'release_fixture_revision_invalid'],
    'dirty or wrong checkout' => ['dirty or wrong checkout', 'release_fixture_checkout_not_verified'],
    'wrong confirmation' => ['wrong confirmation', 'release_fixture_confirmation_mismatch'],
]);

it('uses a different exact confirmation for every mutable fixture action and never treats a guard pass as V10 evidence', function (): void {
    $revision = str_repeat('c', 40);
    $guard = new ItSecurityDesktopReleaseFixtureMutationGuard(static fn (): bool => true);

    $wrongAction = $guard->assess(
        'cleanup',
        $revision,
        ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken('prepare', $revision),
        validDesktopFixtureMutationContext(),
    );
    $cleanup = $guard->assess(
        'cleanup',
        $revision,
        ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken('cleanup', $revision),
        validDesktopFixtureMutationContext(),
    );
    $reset = $guard->assess(
        'reset',
        $revision,
        ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken('reset', $revision),
        validDesktopFixtureMutationContext(),
    );
    $withdraw = $guard->assess(
        'withdraw-tracking-consent',
        $revision,
        ItSecurityDesktopReleaseFixtureMutationGuard::confirmationToken('withdraw-tracking-consent', $revision),
        validDesktopFixtureMutationContext(),
    );

    expect($wrongAction['state'])->toBe('refused')
        ->and($wrongAction['gap_codes'])->toContain('release_fixture_confirmation_mismatch')
        ->and($cleanup['state'])->toBe('authorised')
        ->and($cleanup['fixture_write_authorized'])->toBeTrue()
        ->and($reset['state'])->toBe('authorised')
        ->and($withdraw['state'])->toBe('authorised')
        ->and($cleanup['v10_release_evidence'])->toBeFalse();
});
