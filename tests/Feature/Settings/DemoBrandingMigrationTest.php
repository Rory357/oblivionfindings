<?php

use App\Models\AppSetting;
use App\Support\Branding\BrandHeroPhoto;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * The "Oblivion Living" demo branding (client demo website, 23 Oct 2026): installed only on a
 * demo server (config('demo.site')), idempotent, and never over branding a person has set.
 */

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Storage::fake('public');
});

function runDemoBrandingMigration(): void
{
    (require database_path('migrations/2026_10_23_120000_install_oblivion_living_demo_branding.php'))->up();
}

/** @return array<string, mixed> */
function demoBrandingSettings(): array
{
    return AppSetting::query()
        ->whereIn('key', ['branding.name', 'branding.logo_path', 'branding.logo_dark_path', 'theme.light', 'theme.dark', BrandHeroPhoto::PATH_SETTING, BrandHeroPhoto::ALT_SETTING])
        ->pluck('value', 'key')
        ->all();
}

it('does nothing when the demo switch is off', function () {
    config(['demo.site' => false]);

    runDemoBrandingMigration();

    expect(demoBrandingSettings())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('demoSite', false)->where('branding.heroUrl', null));
});

it('installs the Oblivion Living name, logos, colour and photo the way Settings › Branding stores them', function () {
    config(['demo.site' => true]);

    runDemoBrandingMigration();

    $settings = demoBrandingSettings();
    expect($settings['branding.name'])->toBe('Oblivion Living');
    foreach (['branding.logo_path', 'branding.logo_dark_path'] as $key) {
        expect($settings[$key])->toStartWith('branding/')->toEndWith('.svg');
        Storage::disk('public')->assertExists($settings[$key]);
    }
    expect(Storage::disk('public')->get($settings['branding.logo_dark_path']))->toContain('Oblivion');
    foreach (['theme.light', 'theme.dark'] as $mode) {
        expect($settings[$mode]['--primary'])->toBe('#0f766e')
            ->and($settings[$mode]['--ring'])->toBe('#0f766e')
            ->and($settings[$mode])->toHaveKey('--accent');
    }
    $hero = $settings[BrandHeroPhoto::PATH_SETTING];
    expect($hero)->toStartWith('branding/hero-')->toEndWith('.jpg');
    expect(getimagesizefromstring(Storage::disk('public')->get($hero))[2])->toBe(IMAGETYPE_JPEG);
    expect($settings[BrandHeroPhoto::ALT_SETTING])->toContain('support worker');

    $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('branding.name', 'Oblivion Living')
        ->where('branding.heroUrl', Storage::disk('public')->url($hero))
        ->where('demoSite', true));
    // The other pages' photos are static files the site serves.
    expect(public_path('demo-site/support-respite.jpg'))->toBeFile();
});

it('changes nothing when it runs again', function () {
    config(['demo.site' => true]);
    runDemoBrandingMigration();
    $first = demoBrandingSettings();
    $files = Storage::disk('public')->allFiles('branding');

    runDemoBrandingMigration();

    expect(demoBrandingSettings())->toBe($first)
        ->and(Storage::disk('public')->allFiles('branding'))->toBe($files)
        ->and($files)->toHaveCount(3);
});

it('never overwrites branding a person has set, nor pairs their name with the demo logos', function () {
    config(['demo.site' => true]);
    Storage::disk('public')->put('branding/mine.jpg', 'their photo');
    AppSetting::create(['key' => 'branding.name', 'value' => 'Kōwhai Living']);
    AppSetting::create(['key' => 'theme.light', 'value' => ['--primary' => '#a16207', '--radius' => '0.5rem']]);
    AppSetting::create(['key' => BrandHeroPhoto::PATH_SETTING, 'value' => 'branding/mine.jpg']);
    $before = demoBrandingSettings();

    runDemoBrandingMigration();

    expect(demoBrandingSettings())->toBe($before)
        ->and(Storage::disk('public')->allFiles('branding'))->toBe(['branding/mine.jpg']);
});

it('fills only the empty settings on an unbranded install, keeping a colour a person chose', function () {
    config(['demo.site' => true]);
    AppSetting::create(['key' => 'theme.light', 'value' => ['--primary' => '#a16207', '--radius' => '0.5rem']]);

    runDemoBrandingMigration();

    $settings = demoBrandingSettings();
    expect($settings['branding.name'])->toBe('Oblivion Living')
        // MySQL reorders JSON object keys, so compare without order.
        ->and($settings['theme.light'])->toEqual(['--primary' => '#a16207', '--radius' => '0.5rem'])
        ->and($settings)->not->toHaveKey('theme.dark')
        ->and($settings[BrandHeroPhoto::PATH_SETTING])->toStartWith('branding/hero-');
});
