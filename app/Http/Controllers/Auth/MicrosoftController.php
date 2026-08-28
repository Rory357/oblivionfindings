<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class MicrosoftController extends Controller
{
    public function redirect()
    {
        // If ?link=1, store the current user ID so callback links the identity
        if (request()->query('link') == '1' && auth()->check()) {
            session(['oauth_link_user' => auth()->id()]);
        }

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

        // If linking to an existing user (came from ?link=1)
        $linkUserId = session()->pull('oauth_link_user');
        if ($linkUserId) {
            DB::transaction(function () use ($linkUserId, $m, $email): void {
                $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                    [(int) $linkUserId],
                    [],
                );
                /** @var User|null $linkUser */
                $linkUser = $lockedUsers->get((int) $linkUserId);
                abort_unless($linkUser instanceof User && $linkUser->approved_at !== null, 403);
                $linkUser->identities()->updateOrCreate(
                    ['provider' => 'microsoft', 'provider_user_id' => $m->getId()],
                    [
                        'email' => $email,
                        'access_token' => $m->token,
                        'refresh_token' => $m->refreshToken,
                        'token_expires_at' => $m->expiresIn ? now()->addSeconds($m->expiresIn) : null,
                    ],
                );
            }, 3);

            return redirect('/settings/profile')->with('success', 'Microsoft account linked.');
        }

        // Org-only rule: domain must match
        $orgDomain = strtolower(env('ORG_DOMAIN', ''));
        if ($orgDomain === '') {
            return redirect()
                ->route('login')
                ->withErrors(['microsoft' => 'Microsoft SSO is not configured.']);
        }

        $domain = Str::after($email, '@');
        abort_unless($domain === $orgDomain, 403, 'Microsoft SSO is restricted to the organization.');

        [$user, $loggedIn] = DB::transaction(function () use ($email, $m): array {
            app(EmployeeIntakeService::class)->acquireIntakeLock('email:'.$email);
            $userId = User::query()->where('email', $email)->value('id');
            $defaultRoleId = (int) Role::query()->where('name', 'support_worker')->value('id');
            abort_unless($defaultRoleId > 0, 503, 'The default staff access role is unavailable.');
            if ($userId) {
                $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                    [(int) $userId],
                    [],
                    [$defaultRoleId],
                );
                /** @var User|null $user */
                $user = $lockedUsers->get((int) $userId);
                abort_unless($user instanceof User, 404);
                abort_unless(strtolower(trim((string) $user->email)) === $email, 409, 'The account email changed. Please retry sign-in.');
                $lockedDefaultRole = Role::query()->whereKey($defaultRoleId)->lockForUpdate()->first();
                abort_unless(
                    $lockedDefaultRole instanceof Role && (string) $lockedDefaultRole->name === 'support_worker',
                    409,
                    'The default staff access role changed. Please retry sign-in.',
                );
            } else {
                $user = User::query()->create([
                    'name' => $m->getName() ?: Str::before($email, '@'),
                    'email' => $email,
                    'password' => bcrypt(Str::random(32)),
                    'role' => 'support_worker',
                ]);
                $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                    [(int) $user->id],
                    [],
                    [$defaultRoleId],
                );
                /** @var User $user */
                $user = $lockedUsers->get((int) $user->id);
                $lockedDefaultRole = Role::query()->whereKey($defaultRoleId)->lockForUpdate()->first();
                abort_unless(
                    $lockedDefaultRole instanceof Role && (string) $lockedDefaultRole->name === 'support_worker',
                    409,
                    'The default staff access role changed. Please retry sign-in.',
                );
                $user->roles()->syncWithoutDetaching([$defaultRoleId]);
            }
            if (! $user->role) {
                $user->forceFill(['role' => 'support_worker'])->save();
            }

            $user->identities()->updateOrCreate(
                ['provider' => 'microsoft', 'provider_user_id' => $m->getId()],
                [
                    'email' => $email,
                    'access_token' => $m->token,
                    'refresh_token' => $m->refreshToken,
                    'token_expires_at' => $m->expiresIn ? now()->addSeconds($m->expiresIn) : null,
                ]
            );

            if ($user->approved_at) {
                return [$user, true];
            }

            return [$user, false];
        }, 3);

        if (! $loggedIn) {
            return redirect()
                ->route('login')
                ->with('success', 'Thanks for signing up! Your account is awaiting approval.');
        }

        Auth::login($user, remember: true);

        return redirect()->intended('/dashboard');
    }
}
