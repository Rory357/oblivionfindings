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
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PortalOAuthController extends Controller
{
    /**
     * Portal OAuth is intentionally separate from staff OAuth because it creates
     * and admits family/client portal users into the /portal surface only.
     */
    public function redirectMicrosoft()
    {
        return app(SsoAuthenticationService::class)->redirect(request(), 'microsoft', 'portal');
    }

    public function callbackMicrosoft()
    {
        return $this->callback('microsoft');
    }

    private function callback(string $provider): RedirectResponse
    {
        try {
            $authentication = app(SsoAuthenticationService::class)->callback(request(), $provider, 'portal');
        } catch (InvalidStateException) {
            return redirect()->route('portal.login')->withErrors([$provider => 'This sign-in attempt expired or changed. Start sign-in again.']);
        } catch (GuzzleException) {
            return redirect()->route('portal.login')->withErrors([$provider => 'The provider could not complete sign-in. Try again shortly.']);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 503) {
                throw $exception;
            }

            return redirect()->route('portal.login')->withErrors([$provider => 'This provider needs configuration review by an administrator.']);
        }
        $m = $authentication['user'];

        $email = strtolower(
            $m->getEmail()
                ?? ($m->user['mail'] ?? null)
                ?? ($m->user['userPrincipalName'] ?? null)
                ?? ''
        );

        abort_unless($email !== '', 401, 'No email returned from Microsoft.');

        if ($authentication['link_user_id']) {
            DB::transaction(function () use ($provider, $authentication, $email): void {
                app(SsoGroupMappingLockService::class)->lockMappingSet();
                app(SsoAuthenticationService::class)->assertCurrent($provider, 'portal', $authentication['configuration_fingerprint']);
                $user = app(AuthorizationEvidenceLockService::class)->lockForUsers([(int) $authentication['link_user_id']], [])->get((int) $authentication['link_user_id']);
                abort_unless($user && $user->approved_at && ($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true)), 403);
                app(SsoAuthenticationService::class)->assertLinkCurrent($user, $provider, $authentication['link_binding']);
                abort_unless(strtolower(trim((string) $user->email)) === $email, 403, 'Link the provider account with your profile email, or ask staff to review the email first.');
                $subject = (string) $authentication['user']->getId();
                $existing = Identity::query()->where('provider', $provider)->where('provider_user_id', $subject)->lockForUpdate()->first();
                abort_if($existing && (int) $existing->user_id !== (int) $user->id, 403, 'This provider identity belongs to another account.');
                $identity = $user->identities()->updateOrCreate(['provider' => $provider, 'provider_user_id' => $subject], ['email' => $email]);
                AuditLogger::logOrFail('settings.sso.identity_linked', null, ['actor_id' => (int) $user->id, 'identity_id' => (int) $identity->id, 'provider' => $provider, 'audience' => 'portal']);
            }, 3);

            return redirect('/settings/profile')->with('success', ucfirst($provider).' account linked.');
        }

        return $this->handlePortalLogin($email, $m->getName(), $provider, $authentication);
    }

    /**
     * Portal OAuth is intentionally separate from staff OAuth because it creates
     * and admits family/client portal users into the /portal surface only.
     */
    public function redirectGoogle()
    {
        return app(SsoAuthenticationService::class)->redirect(request(), 'google', 'portal');
    }

    public function callbackGoogle()
    {
        return $this->callback('google');
    }

    private function handlePortalLogin(string $email, ?string $name, string $provider, array $authentication): RedirectResponse
    {
        [$user, $created, $loggedIn] = DB::transaction(function () use ($email, $name, $provider, $authentication): array {
            app(SsoGroupMappingLockService::class)->lockMappingSet();
            app(SsoAuthenticationService::class)->assertCurrent($provider, 'portal', $authentication['configuration_fingerprint']);
            $provisioning = app(SsoConfigurationService::class)->provisioning();
            app(EmployeeIntakeService::class)->acquireIntakeLock('email:'.$email);
            $userId = User::query()->where('email', $email)->value('id');
            $subject = (string) $authentication['user']->getId();
            $identity = Identity::query()->where('provider', $provider)->where('provider_user_id', $subject)->first();
            abort_if($identity && (int) $identity->user_id !== (int) $userId, 403, 'The linked account has changed. Ask staff to review the identity link.');
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
                $identity = Identity::query()->where('provider', $provider)->where('provider_user_id', $subject)->lockForUpdate()->first();
                abort_if($identity && (int) $identity->user_id !== (int) $user->id, 403);
                abort_if(! $identity && $user->approved_at, 403, 'Sign in using an existing method, then connect this provider from your profile. Staff can help recover your existing sign-in.');
                $lockedPortalRole = Role::query()->whereKey($portalRoleId)->lockForUpdate()->first();
                abort_unless(
                    $lockedPortalRole instanceof Role && (string) $lockedPortalRole->name === 'next_of_kin',
                    409,
                    'The portal access role changed. Please retry sign-in.',
                );
                if (! $user->approved_at) {
                    abort_unless($user->hasRole('client', 'next_of_kin') || in_array($user->role, ['client', 'next_of_kin'], true), 403, 'This account does not have portal access.');
                    if (! $identity) {
                        abort_unless($provisioning['auto_link_existing'], 403, 'Pending account linking is disabled. Ask staff to review the account.');
                        $identity = $user->identities()->create(['provider' => $provider, 'provider_user_id' => $subject, 'email' => $email]);
                        AuditLogger::logOrFail('settings.sso.pending_identity_linked', null, ['identity_id' => (int) $identity->id, 'subject_user_id' => (int) $user->id, 'provider' => $provider]);
                    }

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
            abort_unless($provisioning['portal_auto_create'], 403, 'New portal accounts must be prepared by staff.');
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
            $user->identities()->create(['provider' => $provider, 'provider_user_id' => $subject, 'email' => $email]);

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

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            request()->session()->put(['login.id' => $user->id, 'login.remember' => true, 'url.intended' => '/portal']);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended('/portal');
    }
}
