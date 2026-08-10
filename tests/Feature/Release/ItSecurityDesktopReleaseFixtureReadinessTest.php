<?php

use App\Support\Release\ItSecurityDesktopReleaseFixtureReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('fails closed with a value-free report when the deployed fixture pack is absent', function (): void {
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower(ltrim((string) $query->sql));
    });

    $exit = Artisan::call('it-security:verify-desktop-release-fixtures', ['--json' => true]);
    $reportJson = trim(Artisan::output());
    $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($report)->toMatchArray([
            'schema_version' => 1,
            'evidence_class' => ItSecurityDesktopReleaseFixtureReadiness::EVIDENCE_CLASS,
            'state' => 'not_ready',
            'v10_release_evidence' => false,
        ])
        ->and(array_keys($report['sections']))->toBe([
            'sites',
            'actors',
            'people',
            'devices',
            'assets',
            'it_and_control_room',
        ])
        ->and($report['gap_codes'])->toContain(
            'release_sites_missing',
            'release_actor_missing',
            'release_client_missing',
            'release_staff_missing',
            'release_device_missing',
            'release_vehicle_missing',
            'release_catalog_fixture_missing',
            'release_control_room_fixture_missing',
        )
        ->and($report['gap_codes'])->not->toContain('fixture_readiness_query_failed');

    foreach ([
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS),
        ...ItSecurityDesktopReleaseFixtureReadiness::SITES,
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::CLIENTS),
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::STAFF),
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES),
    ] as $protectedFixtureLabel) {
        expect($reportJson)->not->toContain($protectedFixtureLabel);
    }

    expect(collect($statements)->filter(
        fn (string $statement): bool => preg_match(
            '/^(insert|update|delete|replace|create|alter|drop|truncate)\b/',
            $statement,
        ) === 1,
    )->all())->toBe([]);
});
