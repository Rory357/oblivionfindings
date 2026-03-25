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
        $brandingTagline = AppSetting::query()->where('key', 'branding.tagline')->value('value');
        $brandingReportSubtitle = AppSetting::query()->where('key', 'branding.report_subtitle')->value('value');
        $logoPath = AppSetting::query()->where('key', 'branding.logo_path')->value('value');
        $faviconPath = AppSetting::query()->where('key', 'branding.favicon_path')->value('value');

        // Email & Report branding
        $emailHeaderColour = AppSetting::query()->where('key', 'branding.email_header_colour')->value('value');
        $emailFooterText = AppSetting::query()->where('key', 'branding.email_footer_text')->value('value');
        $reportLogoPosition = AppSetting::query()->where('key', 'branding.report_logo_position')->value('value');
        $reportFont = AppSetting::query()->where('key', 'branding.report_font')->value('value');
        $reportIncludeCompanyDetails = AppSetting::query()->where('key', 'branding.report_include_company_details')->value('value');

        // Terminology data
        $terminologyDefaults = config('labels', []);
        $terminologyOverrides = AppSetting::query()
            ->where('key', 'like', 'labels.%')
            ->get(['key', 'value'])
            ->mapWithKeys(fn($row) => [str_replace('labels.', '', $row->key) => $row->value])
            ->toArray();

        return inertia('settings/branding', [
            'allowedVars' => $this->allowedVars,
            'theme' => [
                'light' => is_array($themeLight) ? $themeLight : [],
                'dark' => is_array($themeDark) ? $themeDark : [],
            ],
            'branding' => [
                'name' => is_string($brandingName) ? $brandingName : null,
                'tagline' => is_string($brandingTagline) ? $brandingTagline : null,
                'report_subtitle' => is_string($brandingReportSubtitle) ? $brandingReportSubtitle : null,
                'logoUrl' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
                'faviconUrl' => $faviconPath ? Storage::disk('public')->url($faviconPath) : null,
                'email_header_colour' => is_string($emailHeaderColour) ? $emailHeaderColour : null,
                'email_footer_text' => is_string($emailFooterText) ? $emailFooterText : null,
                'report_logo_position' => is_string($reportLogoPosition) ? $reportLogoPosition : 'left',
                'report_font' => is_string($reportFont) ? $reportFont : 'default',
                'report_include_company_details' => $reportIncludeCompanyDetails === '1' || $reportIncludeCompanyDetails === true,
            ],
            'terminology' => [
                'defaults' => $terminologyDefaults,
                'overrides' => $terminologyOverrides,
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
            'branding.tagline' => ['nullable', 'string', 'max:120'],
            'branding.report_subtitle' => ['nullable', 'string', 'max:120'],
            'branding.email_header_colour' => ['nullable', 'string', 'max:20'],
            'branding.email_footer_text' => ['nullable', 'string', 'max:500'],
            'branding.report_logo_position' => ['nullable', 'string', 'in:left,centre,right'],
            'branding.report_font' => ['nullable', 'string', 'in:default,serif,sans-serif'],
            'branding.report_include_company_details' => ['nullable', 'boolean'],

            'theme' => ['nullable', 'array'],
            'theme.light' => ['nullable', 'array'],
            'theme.dark' => ['nullable', 'array'],

            'logo' => ['nullable', 'file', 'image', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
            'favicon' => ['nullable', 'file', 'image', 'max:512'],
            'remove_favicon' => ['nullable', 'boolean'],
        ]);

        // Save branding text fields
        $textFields = ['name', 'tagline', 'report_subtitle', 'email_header_colour', 'email_footer_text', 'report_logo_position', 'report_font'];
        foreach ($textFields as $field) {
            $value = trim((string) data_get($data, "branding.{$field}", ''));
            $dbKey = "branding.{$field}";
            if ($value === '') {
                AppSetting::query()->where('key', $dbKey)->delete();
            } else {
                AppSetting::updateOrCreate(['key' => $dbKey], ['value' => $value]);
            }
        }

        // Save boolean field
        $includeDetails = data_get($data, 'branding.report_include_company_details');
        if ($includeDetails !== null) {
            AppSetting::updateOrCreate(
                ['key' => 'branding.report_include_company_details'],
                ['value' => $includeDetails ? '1' : '0']
            );
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
            AppSetting::updateOrCreate(['key' => 'branding.logo_path'], ['value' => $path]);
        }

        // Favicon upload / removal
        $removeFavicon = (bool) ($data['remove_favicon'] ?? false);
        $existingFaviconPath = AppSetting::query()->where('key', 'branding.favicon_path')->value('value');

        if ($removeFavicon && $existingFaviconPath) {
            Storage::disk('public')->delete($existingFaviconPath);
            AppSetting::query()->where('key', 'branding.favicon_path')->delete();
        }

        if ($request->hasFile('favicon')) {
            if ($existingFaviconPath) {
                Storage::disk('public')->delete($existingFaviconPath);
            }
            $path = $request->file('favicon')->store('branding', 'public');
            AppSetting::updateOrCreate(['key' => 'branding.favicon_path'], ['value' => $path]);
        }

        return redirect()->back()->with('success', 'Branding updated.');
    }
}
