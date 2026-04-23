<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

class ModuleSettingsController extends Controller
{
    private const MODULE_DEFAULTS = [
        'operations' => true,
        'hr' => true,
        'fleet' => true,
        'governance' => true,
        'incidents' => true,
        'emar' => false,
        'sites' => true,
        'reporting' => true,
        'control-room' => false,
    ];

    private const BETA_DEFAULTS = [
        'ai-docs' => false,
        'family-portal' => false,
        'calendar-sync' => false,
        'custom-forms' => false,
        'advanced-analytics' => false,
    ];

    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);

        return inertia('settings/modules', [
            'module_states' => $this->loadStates('settings.modules.module_states', self::MODULE_DEFAULTS),
            'beta_feature_states' => $this->loadStates('settings.modules.beta_feature_states', self::BETA_DEFAULTS),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()?->canDo('settings.access.manage'), 403);

        $validated = $request->validate([
            'module_states' => ['required', 'array'],
            'beta_feature_states' => ['required', 'array'],
        ]);

        $moduleStates = $this->filterStates($validated['module_states'], self::MODULE_DEFAULTS);
        $betaStates = $this->filterStates($validated['beta_feature_states'], self::BETA_DEFAULTS);

        AppSetting::updateOrCreate(
            ['key' => 'settings.modules.module_states'],
            ['value' => $moduleStates],
        );

        AppSetting::updateOrCreate(
            ['key' => 'settings.modules.beta_feature_states'],
            ['value' => $betaStates],
        );

        return back()->with('success', 'Module settings updated.');
    }

    /**
     * @param array<string, bool> $defaults
     * @return array<string, bool>
     */
    private function loadStates(string $key, array $defaults): array
    {
        $stored = AppSetting::query()->where('key', $key)->value('value');

        if (! is_array($stored)) {
            return $defaults;
        }

        return $this->filterStates($stored, $defaults);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, bool> $defaults
     * @return array<string, bool>
     */
    private function filterStates(array $input, array $defaults): array
    {
        $filtered = [];

        foreach ($defaults as $id => $default) {
            $filtered[$id] = array_key_exists($id, $input)
                ? filter_var($input[$id], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default
                : $default;
        }

        return $filtered;
    }
}
