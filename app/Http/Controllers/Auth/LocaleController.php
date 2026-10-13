<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The English / Te reo Māori switch on the sign-in pages. Signed-out visitors
 * have no profile, so the choice lives in a cookie; signed-in people keep
 * using their profile setting (HandleInertiaRequests prefers it).
 */
class LocaleController extends Controller
{
    public const COOKIE = 'locale';

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('locales.available', [])))],
        ]);

        return back()->withCookie(cookie(
            self::COOKIE,
            $data['locale'],
            60 * 24 * 365,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }
}
