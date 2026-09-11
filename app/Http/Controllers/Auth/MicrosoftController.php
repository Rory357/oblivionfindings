<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Http\Controllers\Controller;
use App\Models\Identity;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\SsoAuthenticationService;
use App\Services\SsoConfigurationService;
use App\Services\SsoGroupMappingLockService;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MicrosoftController extends Controller
{
    public function redirect()
    {
        return app(SsoAuthenticationService::class)->redirect(request(), $this->provider());
    }

    protected function provider(): string
    {
        return 'microsoft';
    }

    public function callback()
    {
        $provider = $this->provider();
        try {
            $authentication = app(SsoAuthenticationService::class)->callback(request(), $provider);
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([$provider => 'This sign-in attempt expired or changed. Start sign-in again.']);
        } catch (GuzzleException) {
            return redirect()->route('login')->withErrors([$provider => 'The provider could not complete sign-in. Try again shortly.']);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 503) {
                throw $exception;
            }

            return redirect()->route('login')->withErrors([$provider => 'This provider needs configuration review by an administrator.']);
        }
        $m = $authentication['user'];

        $email = strtolower(
            $m->getEmail()
                ?? ($m->user['mail'] ?? null)
                ?? ($m->user['userPrincipalName'] ?? null)
                ?? ''
        );

        abort_unless($email !== '', 401, 'No email returned from Microsoft.');

        // If linking to an existing user (came from ?link=1)
        $linkUserId = $authentication['link_user_id'];
        if ($linkUserId) {
            DB::transaction(function () use ($linkUserId, $m, $email, $provider, $authentication): void {
                app(SsoGroupMappingLockService::class)->lockMappingSet();
                app(SsoAuthenticationService::class)->assertCurrent($provider, 'staff', $authentication['configuration_fingerprint']);
                $lockedUsers = app(AuthorizationEvidenceLockService::class)->lockForUsers(
                    [(int) $linkUserId],
                    [],
                );
                /** @var User|null $linkUser */
                $linkUser = $lockedUsers->get((int) $linkUserId);
                abort_unless($linkUser instanceof User && $linkUser->approved_at !== null, 403);
                abort_if($linkUser->hasRole('client', 'next_of_kin') || in_array($linkUser->role, ['client', 'next_of_kin'], true), 403);
                app(SsoAuthenticationService::class)->assertLinkCurrent($linkUser, $provider, $authentication['link_binding']);
                $existingIdentity = Identity::query()->where('provider', $provider)->where('provider_user_id', $m->getId())->lockForUpdate()->first();
                abort_if($existingIdentity && (int) $existingIdentity->user_id !== (int) $linkUser->id, 403, 'This provider identity is already linked to another account.');
                $linkedIdentity = $linkUser->identities()->updateOrCreate(
                    ['provider' => $provider, 'provider_user_id' => $m->getId()],
                    [
                        'email' => $email,
                        'access_token' => $m->token,
                        'refresh_token' => $m->refreshToken,
                        'token_expires_at' => $m->expiresIn ? now()->addSeconds($m->expiresIn) : null,
                    ],
                );
                AuditLogger::logOrFail('settings.sso.identity_linked', null, ['actor_id' => (int) $linkUser->id, 'identity_id' => (int) $linkedIdentity->id, 'provider' => $provider]);
            }, 3);

            return redirect('/settings/profile')->with('success', ucfirst($provider).' account linked.');
        }

        // Org-only rule: domain must match
        $orgDomain = app(SsoConfigurationService::class)->provider($provider)['domain'];
        if ($orgDomain === '') {
            return redirect()
                ->route('login')
                ->withErrors([$provider => ucfirst($provider).' SSO is not configured.']);
        }

        $domain = Str::after($email, '@');
        abort_unless($domain === $orgDomain, 403, 'Microsoft SSO is restricted to the organization.');

        [$user, $loggedIn] = DB::transaction(function () use ($email, $m, $provider, $authentication): array {
            app(SsoGroupMappingLockService::class)->lockMappingSet();
            app(SsoAuthenticationService::class)->assertCurrent($provider, 'staff', $authentication['configuration_fingerprint']);
            $provisioning = app(SsoConfigurationService::class)->provisioning();
            app(EmployeeIntakeService::class)->acquireIntakeLock('email:'.$email);
            $userId = User::query()->where('email', $email)->value('id');
            $identity = Identity::query()->where('provider', $provider)->where('provider_user_id', $m->getId())->first();
            abort_if($identity && (int) $identity->user_id !== (int) $userId, 403, 'The linked account has changed. Ask an administrator to review the identity link.');
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
                $identity = Identity::query()->where('provider', $provider)->where('provider_user_id', $m->getId())->lockForUpdate()->first();
                abort_if($identity && (int) $identity->user_id !== (int) $user->id, 403);
                abort_if($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true), 403, 'Use the portal sign-in for this account.');
                // Mutable email cannot attach a new subject to approved access.
                abort_if(! $identity && ($user->approved_at || ! $provisioning['auto_link_existing']), 403, 'Sign in using an existing method and link this provider from your profile first.');
                $lockedDefaultRole = Role::query()->whereKey($defaultRoleId)->lockForUpdate()->first();
                abort_unless(
                    $lockedDefaultRole instanceof Role && (string) $lockedDefaultRole->name === 'support_worker',
                    409,
                    'The default staff access role changed. Please retry sign-in.',
                );
            } else {
                abort_unless($provisioning['auto_create_staff'], 403, 'New staff accounts must be prepared by an administrator.');
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
                ['provider' => $provider, 'provider_user_id' => $m->getId()],
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

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            request()->session()->put(['login.id' => $user->id, 'login.remember' => true, 'url.intended' => '/dashboard']);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended('/dashboard');
    }
}
