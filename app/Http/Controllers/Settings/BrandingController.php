<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    /**
     * Allowed CSS variables that admins can override.
     */
    private array $allowedVars = [
        '--primary', '--primary-foreground',
        '--secondary', '--secondary-foreground',
        '--accent', '--accent-foreground',
        '--background', '--foreground',
        '--card', '--card-foreground',
        '--popover', '--popover-foreground',
        '--border', '--input', '--ring',
        '--sidebar', '--sidebar-foreground',
        '--sidebar-primary', '--sidebar-primary-foreground',
        '--sidebar-accent', '--sidebar-accent-foreground',
        '--sidebar-border', '--sidebar-ring',
        '--radius',
    ];

    public function edit(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.branding.manage'), 403);

        $themeLight = AppSetting::query()->where('key', 'theme.light')->value('value') ?? [];
        $themeDark = AppSetting::query()->where('key', 'theme.dark')->value('value') ?? [];
        $brandingName = AppSetting::query()->where('key', 'branding.name')->value('value');
        $logoPath = AppSetting::query()->where('key', 'branding.logo_path')->value('value');

        return inertia('settings/branding', [
            'allowedVars' => $this->allowedVars,
            'theme' => [
                'light' => is_array($themeLight) ? $themeLight : [],
                'dark' => is_array($themeDark) ? $themeDark : [],
            ],
            'branding' => [
                'name' => is_string($brandingName) ? $brandingName : null,
                'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.branding.manage'), 403);

        $data = $request->validate([
            'branding' => ['nullable', 'array'],
            'branding.name' => ['nullable', 'string', 'max:80'],

            'theme' => ['nullable', 'array'],
            'theme.light' => ['nullable', 'array'],
            'theme.dark' => ['nullable', 'array'],

            'logo' => ['nullable', 'file', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        // Save branding name
        $name = trim((string) data_get($data, 'branding.name', ''));
        if ($name === '') {
            AppSetting::query()->where('key', 'branding.name')->delete();
        } else {
            AppSetting::updateOrCreate(['key' => 'branding.name'], ['value' => $name]);
        }

        // Save theme tokens (light/dark)
        $light = (array) data_get($data, 'theme.light', []);
        $dark = (array) data_get($data, 'theme.dark', []);

        $filter = function (array $vars) {
            $out = [];
            foreach ($vars as $k => $v) {
                if (!is_string($k) || !in_array($k, $this->allowedVars, true)) {
                    continue;
                }
                if (!is_string($v)) {
                    continue;
                }
                $val = trim($v);
                if ($val === '') {
                    continue;
                }
                $out[$k] = $val;
            }
            return $out;
        };

        $lightFiltered = $filter($light);
        $darkFiltered = $filter($dark);

        if (count($lightFiltered) === 0) {
            AppSetting::query()->where('key', 'theme.light')->delete();
        } else {
            AppSetting::updateOrCreate(['key' => 'theme.light'], ['value' => $lightFiltered]);
        }

        if (count($darkFiltered) === 0) {
            AppSetting::query()->where('key', 'theme.dark')->delete();
        } else {
            AppSetting::updateOrCreate(['key' => 'theme.dark'], ['value' => $darkFiltered]);
        }

        // Logo upload / removal
        $removeLogo = (bool) ($data['remove_logo'] ?? false);
        $existingLogoPath = AppSetting::query()->where('key', 'branding.logo_path')->value('value');

        if ($removeLogo && $existingLogoPath) {
            Storage::disk('public')->delete($existingLogoPath);
            AppSetting::query()->where('key', 'branding.logo_path')->delete();
        }

        if ($request->hasFile('logo')) {
            // Remove old
            if ($existingLogoPath) {
                Storage::disk('public')->delete($existingLogoPath);
            }

            $path = $request->file('logo')->store('branding', 'public');
            // Store the disk path (render with Storage::disk('public')->url())
            AppSetting::updateOrCreate(['key' => 'branding.logo_path'], ['value' => $path]);
        }

        return redirect()->back()->with('success', 'Branding updated.');
    }
}
