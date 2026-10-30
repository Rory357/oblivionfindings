<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\EnsureUserIsApproved;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LockoutResponse as AppLockoutResponse;
use App\Http\Responses\LogoutResponse as AppLogoutResponse;
use App\Http\Responses\PasswordResetLinkRequestedResponse;
use App\Http\Responses\PasswordResetResponse as AppPasswordResetResponse;
use App\Models\User;
use App\Services\SsoConfigurationService;
use App\Support\SecurityPolicy;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LockoutResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify authentication behavior.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateThrough(function (Request $request) {
            return array_filter([
                config('fortify.limiters.login') ? EnsureLoginIsNotThrottled::class : null,
                CanonicalizeUsername::class,
                Features::enabled(Features::twoFactorAuthentication()) ? RedirectIfTwoFactorAuthenticatable::class : null,
                AttemptToAuthenticate::class,
                EnsureUserIsApproved::class,
                PrepareAuthenticatedSession::class,
            ]);
        });

        // The logout response runs after the user is gone; remember which
        // "You've signed out" page (staff or family) they should see.
        Event::listen(Logout::class, function (Logout $event): void {
            if ($event->user instanceof User && app()->bound('request')) {
                request()->attributes->set(
                    AppLogoutResponse::AUDIENCE_ATTRIBUTE,
                    $event->user->isPortalAccount() ? 'family' : 'staff',
                );
            }
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        $this->app->singleton(RegisterResponse::class, \App\Http\Responses\RegisterResponse::class);
        $this->app->singleton(LoginResponse::class, \App\Http\Responses\LoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, \App\Http\Responses\TwoFactorLoginResponse::class);
        $this->app->singleton(LogoutResponse::class, AppLogoutResponse::class);
        $this->app->singleton(LockoutResponse::class, AppLockoutResponse::class);
        // Same "check your email" either way: the form must not reveal who has an account.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestedResponse::class);
        $this->app->bind(PasswordResetResponse::class, AppPasswordResetResponse::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'ssoProviders' => app(SsoConfigurationService::class)->availability('staff'),
            'ssoRouting' => app(SsoConfigurationService::class)->staffRouting(),
            'retryAfter' => $request->session()->get('loginRetryAfter'),
            'notStaffEmail' => $request->session()->get('ssoNotStaffEmail'),
            'awaitingApproval' => (bool) $request->session()->get('awaitingApproval', false),
            'passwordChanged' => (bool) $request->session()->get('passwordChanged', false),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'audience' => $this->audienceForEmail((string) $request->email),
            'passwordRules' => SecurityPolicy::passwordRequirements(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
            'sentTo' => $request->session()->get('resetLinkSentTo'),
            'audience' => $request->query('for') === 'family' ? 'family' : 'staff',
            'ssoRouting' => app(SsoConfigurationService::class)->staffRouting(),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
            'email' => $request->user()?->email,
            'audience' => $this->audienceFor($request->user()),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/register'));

        Fortify::twoFactorChallengeView(fn (Request $request) => Inertia::render('auth/two-factor-challenge', [
            'audience' => $this->audienceFor(User::query()->find($request->session()->get('login.id'))),
        ]));

        Fortify::confirmPasswordView(fn (Request $request) => Inertia::render('auth/confirm-password', [
            'audience' => $this->audienceFor($request->user()),
        ]));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinutes(
                SecurityPolicy::lockoutDurationMinutes(),
                SecurityPolicy::maxLoginAttempts()
            )->by($throttleKey)->response(function (Request $request, array $headers) {
                // The sign-in page shows the wait as a countdown; other
                // clients keep the plain 429.
                if (! $request->header('X-Inertia')) {
                    return response(AppLockoutResponse::message(), 429, $headers);
                }

                return back()
                    ->withErrors([Fortify::username() => AppLockoutResponse::message()])
                    ->with('loginRetryAfter', (int) ($headers['Retry-After'] ?? 60));
            });
        });
    }

    /** Family portal accounts see the family version of shared auth screens. */
    private function audienceFor(mixed $user): string
    {
        return $user instanceof User && $user->isPortalAccount() ? 'family' : 'staff';
    }

    private function audienceForEmail(string $email): string
    {
        return $this->audienceFor(User::query()->where('email', strtolower(trim($email)))->first());
    }
}
