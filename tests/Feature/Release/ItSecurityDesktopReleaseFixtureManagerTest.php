<?php

use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\Site;
use App\Models\User;
use App\Support\Release\ItSecurityDesktopReleaseFixtureManager;
use App\Support\Release\ItSecurityDesktopReleaseFixtureReadiness;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    Storage::fake('private');
    config()->set('it.desktop_release_fixtures.actor_password', 'release-only-password');
    config()->set('it.desktop_release_fixtures.reviewer_totp_secret', 'JBSWY3DPEHPK3PXP');
});

it('prepares one complete pack reuses it idempotently and removes only owned records', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $firstRevision = str_repeat('a', 40);
    $secondRevision = str_repeat('b', 40);
    $unrelatedSite = Site::factory()->create(['name' => 'Unrelated retained Site']);
    $unrelatedUser = User::factory()->create(['email' => 'unrelated-retained@example.test']);

    $plan = $manager->plan('prepare', $firstRevision);
    $created = $manager->execute('prepare', $firstRevision);
    $pack = ItSecurityDesktopReleaseFixturePack::query()->sole();
    $ownedRecordCount = count($pack->manifest['records']);
    $actorCount = User::query()->whereIn(
        'email',
        array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS),
    )->count();
    $deviceCount = Device::query()->whereIn(
        'name',
        array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES),
    )->count();

    expect($plan)->toMatchArray([
        'state' => 'ready',
        'mode' => 'dry_run',
        'operation' => 'create',
        'fixture_mutation_applied' => false,
        'v10_release_evidence' => false,
    ])->and($created)->toMatchArray([
        'state' => 'ready',
        'mode' => 'execute',
        'operation' => 'created',
        'fixture_mutation_applied' => true,
        'v10_release_evidence' => false,
    ])->and($created['record_count'])->toBe($ownedRecordCount)
        ->and($ownedRecordCount)->toBeGreaterThan(40)
        ->and(app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess()['state'])->toBe('ready')
        ->and(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(1);
    Storage::disk('private')->assertExists('it-security-release-fixtures/release-network-evidence.txt');

    $reused = $manager->execute('prepare', $secondRevision);

    expect($reused)->toMatchArray([
        'state' => 'ready',
        'operation' => 'reused',
        'release_revision' => $secondRevision,
        'fixture_mutation_applied' => true,
    ])->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(ItSecurityDesktopReleaseFixturePack::query()->value('release_revision'))->toBe($secondRevision)
        ->and(User::query()->whereIn('email', array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS))->count())->toBe($actorCount)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe($deviceCount);

    $cleaned = $manager->execute('cleanup', $secondRevision);

    expect($cleaned)->toMatchArray([
        'state' => 'ready',
        'operation' => 'deleted_owned',
        'record_count' => $ownedRecordCount,
        'fixture_mutation_applied' => true,
    ])->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(0)
        ->and(User::query()->whereIn('email', array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS))->count())->toBe(0)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe(0)
        ->and(Site::query()->whereKey($unrelatedSite->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($unrelatedUser->id)->exists())->toBeTrue();
    Storage::disk('private')->assertMissing('it-security-release-fixtures/release-network-evidence.txt');
});

it('refuses a reserved identity before writing any owned pack record', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('c', 40);
    $reserved = Site::factory()->create(['name' => 'RELEASE Site Alpha']);

    $plan = $manager->plan('prepare', $revision);
    $executed = $manager->execute('prepare', $revision);

    expect($plan['state'])->toBe('failed')
        ->and($plan['gap_codes'])->toBe(['release_fixture_reserved_identity_present'])
        ->and($executed['state'])->toBe('failed')
        ->and($executed['fixture_mutation_applied'])->toBeFalse()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(0)
        ->and(Site::query()->whereKey($reserved->id)->exists())->toBeTrue()
        ->and(User::query()->whereIn('email', array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS))->count())->toBe(0);
});

it('refuses cleanup when an owned file is missing or the manifest is corrupt and preserves every record', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('d', 40);
    $manager->execute('prepare', $revision);
    $pack = ItSecurityDesktopReleaseFixturePack::query()->sole();
    $deviceCount = Device::query()->whereIn(
        'name',
        array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES),
    )->count();
    Storage::disk('private')->delete('it-security-release-fixtures/release-network-evidence.txt');

    $missingFileReport = $manager->execute('cleanup', $revision);

    expect($missingFileReport['state'])->toBe('failed')
        ->and($missingFileReport['gap_codes'])->toBe(['release_fixture_owned_file_mismatch'])
        ->and($missingFileReport['fixture_mutation_applied'])->toBeFalse()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe($deviceCount);

    Storage::disk('private')->put(
        'it-security-release-fixtures/release-network-evidence.txt',
        "Non-sensitive desktop release acceptance evidence.\n",
    );
    $pack->update(['manifest_sha256' => str_repeat('0', 64)]);

    $report = $manager->execute('cleanup', $revision);

    expect($report['state'])->toBe('failed')
        ->and($report['gap_codes'])->toBe(['release_fixture_pack_integrity_failed'])
        ->and($report['fixture_mutation_applied'])->toBeFalse()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe($deviceCount);
    Storage::disk('private')->assertExists('it-security-release-fixtures/release-network-evidence.txt');
});
