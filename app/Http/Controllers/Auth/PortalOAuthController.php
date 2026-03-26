<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class PortalOAuthController extends Controller
{
    public function redirectMicrosoft()
    {
        return Socialite::driver('microsoft')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callbackMicrosoft()
    {
        $m = Socialite::driver('microsoft')->stateless()->user();

        $email = strtolower(
            $m->getEmail()
                ?? ($m->user['mail'] ?? null)
                ?? ($m->user['userPrincipalName'] ?? null)
                ?? ''
        );

        abort_unless($email !== '', 401, 'No email returned from Microsoft.');

        return $this->handlePortalLogin($email, $m->getName());
    }

    public function redirectGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callbackGoogle()
    {
        $g = Socialite::driver('google')->stateless()->user();

        $email = strtolower($g->getEmail() ?? '');
        abort_unless($email !== '', 401, 'No email returned from Google.');

        return $this->handlePortalLogin($email, $g->getName());
    }

    private function handlePortalLogin(string $email, ?string $name): \Illuminate\Http\RedirectResponse
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            // Create pending portal user (not approved until staff activates)
            $user = User::create([
                'name' => $name ?: Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
            ]);

            // Auto-assign portal role
            $portalRole = Role::where('name', 'portal')->first();
            if ($portalRole) {
                $user->roles()->syncWithoutDetaching([$portalRole->id]);
            }

            return redirect()
                ->route('portal.login')
                ->with('success', 'Your account has been created and is awaiting approval by staff.');
        }

        // Existing user — check if they have portal access
        Auth::login($user, remember: true);

        return redirect()->intended('/portal');
    }
}
