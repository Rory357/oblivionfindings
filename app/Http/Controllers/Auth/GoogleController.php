<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        // If ?link=1, store the current user ID so callback links the identity
        if (request()->query('link') == '1' && auth()->check()) {
            session(['oauth_link_user' => auth()->id()]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        $g = Socialite::driver('google')->stateless()->user();

        $email = strtolower($g->getEmail() ?? '');
        abort_unless($email !== '', 401, 'No email returned from Google.');

        // If linking to an existing user (came from ?link=1)
        $linkUserId = session()->pull('oauth_link_user');
        if ($linkUserId) {
            $linkUser = User::findOrFail($linkUserId);
            $linkUser->identities()->updateOrCreate(
                ['provider' => 'google', 'provider_user_id' => $g->getId()],
                [
                    'email' => $email,
                    'access_token' => $g->token,
                    'refresh_token' => $g->refreshToken,
                    'token_expires_at' => $g->expiresIn ? now()->addSeconds($g->expiresIn) : null,
                ]
            );
            return redirect('/settings/profile')->with('success', 'Google account linked.');
        }

        // Create the user if they don't exist yet.
        // IMPORTANT: We do NOT log them in. They must be approved first.
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $g->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
            ]);
        }

        // Store identity for the user
        $user->identities()->updateOrCreate(
            ['provider' => 'google', 'provider_user_id' => $g->getId()],
            [
                'email' => $email,
                'access_token' => $g->token,
                'refresh_token' => $g->refreshToken,
                'token_expires_at' => $g->expiresIn ? now()->addSeconds($g->expiresIn) : null,
            ]
        );

        // Always send them back to login with your existing "awaiting approval" message
        return redirect()
            ->route('login')
            ->with('success', 'Thanks for signing up! Your account is awaiting approval.');
    }
}
