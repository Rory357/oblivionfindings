<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

class TerminologyController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.terminology.manage'), 403);

        $defaults = config('labels');
        $overrides = AppSetting::query()
            ->where('key', 'like', 'labels.%')
            ->get(['key', 'value'])
            ->mapWithKeys(fn($row) => [str_replace('labels.', '', $row->key) => $row->value])
            ->toArray();

        return inertia('settings/terminology', [
            'defaults' => $defaults,
            'overrides' => $overrides,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('settings.terminology.manage'), 403);

        $data = $request->validate([
            'labels' => ['required', 'array'],
            'labels.*' => ['nullable', 'string', 'max:80'],
        ]);

        foreach ($data['labels'] as $key => $value) {
            $dbKey = 'labels.' . $key;

            // If blank or same as default, remove override
            $default = config('labels.' . $key);
            if ($value === null || trim($value) === '' || $value === $default) {
                AppSetting::query()->where('key', $dbKey)->delete();
                continue;
            }

            AppSetting::updateOrCreate(
                ['key' => $dbKey],
                ['value' => trim($value)]
            );
        }

        return redirect()->back()->with('success', 'Terminology updated.');
    }
}
