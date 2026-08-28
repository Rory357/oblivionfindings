<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    private function handlePortalLogin(string $email, ?string $name): RedirectResponse
    {
        [$user, $created, $loggedIn] = DB::transaction(function () use ($email, $name): array {
            app(EmployeeIntakeService::class)->acquireIntakeLock('email:'.$email);
            $userId = User::query()->where('email', $email)->value('id');
            $portalRoleId = (int) Role::query()->where('name', 'next_of_kin')->value('id');
            abort_unless($portalRoleId > 0, 503, 'The portal access role is unavailable.');
            if ($userId) {
                $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                    [(int) $userId],
                    [],
                    [$portalRoleId],
                );
                /** @var User|null $user */
                $user = $lockedUsers->get((int) $userId);
                abort_unless($user instanceof User, 404);
                abort_unless(strtolower(trim((string) $user->email)) === $email, 409, 'The account email changed. Please retry sign-in.');
                $lockedPortalRole = Role::query()->whereKey($portalRoleId)->lockForUpdate()->first();
                abort_unless(
                    $lockedPortalRole instanceof Role && (string) $lockedPortalRole->name === 'next_of_kin',
                    409,
                    'The portal access role changed. Please retry sign-in.',
                );
                if (! $user->approved_at) {
                    return [$user, false, false];
                }
                abort_unless(
                    $user->hasRole('client', 'next_of_kin')
                        || in_array($user->role, ['client', 'next_of_kin'], true),
                    403,
                    'This account does not have portal access.',
                );

                return [$user, false, true];
            }

            // Create pending portal user (not approved until staff activates)
            // while the shared email mutex and requested Role are both held.
            $user = User::query()->create([
                'name' => $name ?: Str::before($email, '@'),
                'email' => $email,
                'password' => bcrypt(Str::random(32)),
                'role' => 'next_of_kin',
            ]);
            $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                [(int) $user->id],
                [],
                [$portalRoleId],
            );
            /** @var User $user */
            $user = $lockedUsers->get((int) $user->id);
            $lockedPortalRole = Role::query()->whereKey($portalRoleId)->lockForUpdate()->first();
            abort_unless(
                $lockedPortalRole instanceof Role && (string) $lockedPortalRole->name === 'next_of_kin',
                409,
                'The portal access role changed. Please retry sign-in.',
            );
            $user->roles()->syncWithoutDetaching([$portalRoleId]);

            return [$user, true, false];
        }, 3);

        if ($created) {
            return redirect()
                ->route('portal.login')
                ->with('success', 'Your account has been created and is awaiting approval by staff.');
        }

        if (! $loggedIn) {
            return redirect()
                ->route('portal.login')
                ->with('success', 'Your account is awaiting approval by staff.');
        }

        Auth::login($user, remember: true);

        return redirect()->intended('/portal');
    }
}
