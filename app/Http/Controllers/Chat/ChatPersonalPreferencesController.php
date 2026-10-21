<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatPersonalPreferences;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ChatPersonalPreferencesController extends Controller
{
    public function page()
    {
        return Inertia::render('chat/preferences');
    }

    public function show(Request $request, ChatPersonalPreferences $preferences)
    {
        return response()->json($preferences->read($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, ChatPersonalPreferences $preferences)
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'values' => ['required', 'array:text_size,spacing,sending,quiet_enabled,show_previews,theme,sound_enabled', 'min:1'],
            'values.text_size' => ['sometimes', 'required', 'in:normal,large,extra_large'],
            'values.spacing' => ['sometimes', 'required', 'in:comfortable,compact'],
            'values.sending' => ['sometimes', 'required', 'in:enter_sends,enter_newline'],
            'values.theme' => ['sometimes', 'required', 'in:light,dark,system'],
            'values.quiet_enabled' => ['sometimes', 'boolean'],
            'values.show_previews' => ['sometimes', 'boolean'],
            'values.sound_enabled' => ['sometimes', 'boolean'],
        ]);
        foreach (['quiet_enabled', 'show_previews', 'sound_enabled'] as $key) {
            if (array_key_exists($key, $data['values'])) {
                $data['values'][$key] = (bool) $data['values'][$key];
            }
        }

        return response()->json($preferences->update($request->user(), $data['values'], $data['version']))->header('Cache-Control', 'private, no-store');
    }
}
