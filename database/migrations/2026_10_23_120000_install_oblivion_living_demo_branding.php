<?php

use App\Models\AppSetting;
use App\Support\Branding\BrandHeroPhoto;
use App\Support\BrandThemeTokens;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Branding for the "Oblivion Living" client demo website (mockup v2, approved by Stephan
 * 23 Oct 2026): its name, its logos (light and dark backgrounds), one brand colour (teal
 * #0F766E; the app works out the dark-mode shades) and the website and sign-in photo.
 *
 * Runs only when config('demo.site') is true (DEMO_SITE in .env). It uses the same storage as
 * Settings › Branding: the public disk's branding/ folder and the same app settings, and the photo
 * goes through the same re-encoding as an upload.
 *
 * Nothing a person has set is ever replaced: the demo branding goes in only while the install has
 * no name or logo of its own (or already carries the demo's name, from an earlier run), and each
 * setting is written only while it is empty. So a person's own name is never paired with the demo
 * logos, and running it again changes nothing.
 *
 * The demo photos the other pages use are static files in public/demo-site/, shown only on a
 * demo server; the logo and photo sources here are read from that folder.
 */
return new class extends Migration
{
    public const NAME = 'Oblivion Living';

    public const BRAND_HEX = '#0f766e';

    public const HERO_ALT = 'A young woman in a wheelchair and her support worker laughing as they make a salad together in her kitchen';

    public function up(): void
    {
        if (! config('demo.site') || ! Schema::hasTable('app_settings')) {
            return;
        }
        $source = public_path('demo-site');

        $unbranded = $this->isEmpty('branding.name') && $this->isEmpty('branding.logo_path') && $this->isEmpty('branding.logo_dark_path');
        $ours = AppSetting::query()->where('key', 'branding.name')->value('value') === self::NAME;
        if (! $unbranded && ! $ours) {
            return;
        }

        if ($this->isEmpty('branding.name')) {
            AppSetting::updateOrCreate(['key' => 'branding.name'], ['value' => self::NAME]);
        }
        $this->installFile('branding.logo_path', $source.'/oblivion-living-logo.svg');
        $this->installFile('branding.logo_dark_path', $source.'/oblivion-living-logo-dark.svg');
        $this->installColour();

        if ($this->isEmpty(BrandHeroPhoto::PATH_SETTING) && is_file($source.'/hero-home.jpg')) {
            $path = app(BrandHeroPhoto::class)->store($source.'/hero-home.jpg');
            AppSetting::updateOrCreate(['key' => BrandHeroPhoto::PATH_SETTING], ['value' => $path]);
            if ($this->isEmpty(BrandHeroPhoto::ALT_SETTING)) {
                AppSetting::updateOrCreate(['key' => BrandHeroPhoto::ALT_SETTING], ['value' => self::HERO_ALT]);
            }
        }
    }

    public function down(): void
    {
        // Branding may have been kept or changed since; it is never removed automatically.
    }

    private function isEmpty(string $key): bool
    {
        $value = AppSetting::query()->where('key', $key)->value('value');

        return $value === null || (is_string($value) && trim($value) === '') || $value === [];
    }

    /** A logo stored the way Settings › Branding stores an upload (public disk, branding/). */
    private function installFile(string $key, string $path): void
    {
        if (! $this->isEmpty($key) || ! is_file($path)) {
            return;
        }
        $stored = Storage::disk('public')->putFile('branding', new File($path));
        if (is_string($stored) && $stored !== '') {
            AppSetting::updateOrCreate(['key' => $key], ['value' => $stored]);
        }
    }

    /**
     * The palette Settings › Branding derives from one colour (lib/derive-palette.ts
     * `derivePalette`), limited to the stored tokens. Installed only when no brand colour is set in
     * either mode; other theme values a person set (such as the corner radius) are kept.
     */
    private function installColour(): void
    {
        $light = AppSetting::query()->where('key', 'theme.light')->value('value');
        $dark = AppSetting::query()->where('key', 'theme.dark')->value('value');
        $light = is_array($light) ? $light : [];
        $dark = is_array($dark) ? $dark : [];
        if (isset($light['--primary']) || isset($dark['--primary'])) {
            return;
        }
        $hex = self::BRAND_HEX;
        $palette = BrandThemeTokens::filter([
            '--primary' => $hex,
            '--primary-foreground' => 'oklch(1 0 0)',
            '--accent' => "color-mix(in oklch, {$hex} 15%, transparent)",
            '--accent-foreground' => $hex,
            '--ring' => $hex,
            '--sidebar-primary' => $hex,
            '--sidebar-primary-foreground' => 'oklch(1 0 0)',
            '--sidebar-ring' => "color-mix(in oklch, {$hex} 70%, white 30%)",
        ]);
        AppSetting::updateOrCreate(['key' => 'theme.light'], ['value' => [...$palette, ...$light]]);
        AppSetting::updateOrCreate(['key' => 'theme.dark'], ['value' => [...$palette, ...$dark]]);
    }
};
