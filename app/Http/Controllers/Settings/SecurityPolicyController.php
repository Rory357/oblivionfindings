<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityPolicyController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/security', [
            'settings' => AppSetting::query()
                ->whereIn('key', [
                    'password_min_length',
                    'password_require_uppercase',
                    'password_require_numbers',
                    'password_require_symbols',
                    'password_expiry_days',
                    'session_timeout_minutes',
                    'max_login_attempts',
                    'lockout_duration_minutes',
                    'force_2fa',
                ])
                ->pluck('value', 'key'),
            'twoFaStats' => [
                'enabled' => User::query()->whereNotNull('two_factor_confirmed_at')->count(),
                'total' => User::query()->count(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'password_min_length' => ['required', 'integer', 'min:6', 'max:128'],
            'password_require_uppercase' => ['required', 'boolean'],
            'password_require_numbers' => ['required', 'boolean'],
            'password_require_symbols' => ['required', 'boolean'],
            'password_expiry_days' => ['required', 'integer', 'min:0'],
            'session_timeout_minutes' => ['required', 'integer', 'min:1'],
            'max_login_attempts' => ['required', 'integer', 'min:1'],
            'lockout_duration_minutes' => ['required', 'integer', 'min:1'],
            'force_2fa' => ['required', 'boolean'],
        ]);

        $settings = [
            'password_min_length' => (int) $request->input('password_min_length'),
            'password_require_uppercase' => $request->boolean('password_require_uppercase'),
            'password_require_numbers' => $request->boolean('password_require_numbers'),
            'password_require_symbols' => $request->boolean('password_require_symbols'),
            'password_expiry_days' => (int) $request->input('password_expiry_days'),
            'session_timeout_minutes' => (int) $request->input('session_timeout_minutes'),
            'max_login_attempts' => (int) $request->input('max_login_attempts'),
            'lockout_duration_minutes' => (int) $request->input('lockout_duration_minutes'),
            'force_2fa' => $request->boolean('force_2fa'),
        ];

        collect($settings)->each(
            fn ($value, $key) => AppSetting::updateOrCreate(['key' => $key], ['value' => $value])
        );

        AuditLogger::log('settings.security.updated', null, [
            'changes' => $settings,
            'changed_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Security settings updated.');
    }
}
