<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateUiPreferenceRequest;
use Illuminate\Http\RedirectResponse;

class UiPreferenceController extends Controller
{
    public function update(UpdateUiPreferenceRequest $request): RedirectResponse
    {
        $request->user()->uiPreferences()->updateOrCreate(
            ['key' => $request->validated('key')],
            ['value' => $request->validated('value')],
        );

        return redirect()->back();
    }
}
