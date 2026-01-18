<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('microsoft')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback()
    {
        $m = Socialite::driver('microsoft')->stateless()->user();

        $email = strtolower(
            $m->getEmail()
                ?? ($m->user['mail'] ?? null)
                ?? ($m->user['userPrincipalName'] ?? null)
                ?? ''
        );

        abort_unless($email !== '', 401, 'No email returned from Microsoft.');

        // Org-only rule: domain must match
        $orgDomain = strtolower(env('ORG_DOMAIN', ''));
        abort_unless($orgDomain !== '', 500, 'ORG_DOMAIN is not set.');

        $domain = Str::after($email, '@');
        abort_unless($domain === $orgDomain, 403, 'Microsoft SSO is restricted to the organization.');

        // Create or find user
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $m->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'role' => 'support_worker', // legacy column used in some UI checks
            ]);

            // Attach RBAC role for hasRole()/canDo()
            $defaultRole = Role::where('name', 'support_worker')->first();
            if ($defaultRole) {
                $user->roles()->syncWithoutDetaching([$defaultRole->id]);
            }
        } else {
            // Ensure legacy role is set (optional but helps your existing checks)
            if (!$user->role) {
                $user->forceFill(['role' => 'support_worker'])->save();
            }
        }

        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }
}
