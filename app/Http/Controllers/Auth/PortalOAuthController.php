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
    /**
     * Portal OAuth is intentionally separate from staff OAuth because it creates
     * and admits family/client portal users into the /portal surface only.
     */
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

    /**
     * Portal OAuth is intentionally separate from staff OAuth because it creates
     * and admits family/client portal users into the /portal surface only.
     */
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

            // Auto-assign the seeded family portal role.
            $portalRole = Role::where('name', 'next_of_kin')->first();
            if ($portalRole) {
                $user->roles()->syncWithoutDetaching([$portalRole->id]);
                $user->forceFill(['role' => 'next_of_kin'])->save();
            }

            return redirect()
                ->route('portal.login')
                ->with('success', 'Your account has been created and is awaiting approval by staff.');
        }

        if (! $user->approved_at) {
            return redirect()
                ->route('portal.login')
                ->with('success', 'Your account is awaiting approval by staff.');
        }

        abort_unless(
            $user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true),
            403,
            'This account does not have portal access.'
        );

        // Existing approved portal user.
        Auth::login($user, remember: true);

        return redirect()->intended('/portal');
    }
}
