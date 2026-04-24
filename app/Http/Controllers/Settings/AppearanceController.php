<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Persist per-user appearance preferences (theme, accent colour, font size,
 * sidebar density, reduce motion, and regional defaults). Org-level defaults
 * live on `app_settings` with `org.*` keys.
 */
class AppearanceController extends Controller
{
    private const ALLOWED_THEMES = ['light', 'dark', 'system'];

    private const ALLOWED_DENSITIES = ['comfortable', 'compact'];

    private const ALLOWED_FIRST_DAYS = ['monday', 'sunday'];

    private const ALLOWED_DATE_FORMATS = ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'];

    private const ALLOWED_TIME_FORMATS = ['12', '24'];

    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user, 403);

        return Inertia::render('settings/appearance', [
            'appearance' => $this->resolveAppearance($user),
            'orgDefaults' => $this->orgDefaults(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $data = $request->validate([
            'theme' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_THEMES)],
            'accent_colour' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => ['nullable', 'integer', 'between:10,24'],
            'sidebar_density' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_DENSITIES)],
            'reduce_motion' => ['nullable', 'boolean'],
            'first_day_of_week' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_FIRST_DAYS)],
            'date_format' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_DATE_FORMATS)],
            'time_format' => ['nullable', 'string', 'in:' . implode(',', self::ALLOWED_TIME_FORMATS)],
        ]);

        // Only update columns that were explicitly submitted — null means "leave
        // alone" here, not "reset to default". A dedicated reset endpoint handles
        // restoration when the user clicks "Reset to brand default".
        $payload = array_filter(
            $data,
            fn ($value) => $value !== null && $value !== '',
        );

        if ($payload !== []) {
            $user->fill($payload)->save();
        }

        return redirect()
            ->back()
            ->with('success', 'Appearance preferences saved.');
    }

    /**
     * Reset appearance overrides — the user falls back to the org / brand default.
     */
    public function reset(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $user->forceFill([
            'accent_colour' => null,
            'theme' => 'system',
            'font_size' => 14,
            'sidebar_density' => 'comfortable',
            'reduce_motion' => false,
        ])->save();

        return redirect()->back()->with('success', 'Appearance reset to defaults.');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAppearance($user): array
    {
        $org = $this->orgDefaults();

        return [
            'theme' => $user->theme ?? 'system',
            'accent_colour' => $user->accent_colour, // null → brand default
            'font_size' => (int) ($user->font_size ?? 14),
            'sidebar_density' => $user->sidebar_density ?? 'comfortable',
            'reduce_motion' => (bool) ($user->reduce_motion ?? false),
            'first_day_of_week' => $user->first_day_of_week ?? $org['first_day_of_week'],
            'date_format' => $user->date_format ?? $org['date_format'],
            'time_format' => $user->time_format ?? $org['time_format'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function orgDefaults(): array
    {
        return [
            'first_day_of_week' => (string) (AppSetting::query()
                ->where('key', 'org.first_day_of_week')
                ->value('value') ?? 'monday'),
            'date_format' => (string) (AppSetting::query()
                ->where('key', 'org.date_format')
                ->value('value') ?? 'DD/MM/YYYY'),
            'time_format' => (string) (AppSetting::query()
                ->where('key', 'org.time_format')
                ->value('value') ?? '24'),
        ];
    }
}
