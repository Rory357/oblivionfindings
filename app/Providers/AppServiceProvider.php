<?php

namespace App\Providers;

use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteChecklistRun;
use App\Observers\ClientNoteObserver;
use App\Observers\ShiftObserver;
use App\Observers\SiteObserver;
use App\Observers\SiteHazardObserver;
use App\Observers\SiteChecklistRunObserver;
use App\Events\FleetSignalEmitted;
use App\Services\AuditLogger;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(IntegrationAdapterRegistry::class, function () {
            $registry = new IntegrationAdapterRegistry();
            $registry->register('unifi', UnifiAdapter::class);

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Shift::observe(ShiftObserver::class);
        ClientNote::observe(ClientNoteObserver::class);
        Site::observe(SiteObserver::class);
        SiteHazard::observe(SiteHazardObserver::class);
        SiteChecklistRun::observe(SiteChecklistRunObserver::class);

        // Register Socialite providers (Microsoft + Google)
        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            [\SocialiteProviders\Microsoft\MicrosoftExtendSocialite::class, 'handle']
        );
        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            [\SocialiteProviders\Google\GoogleExtendSocialite::class, 'handle']
        );

        Event::listen(FleetSignalEmitted::class, function (FleetSignalEmitted $event) {
            AuditLogger::log('fleet.signal.emitted', $event->signal->asset, [
                'signal_id' => $event->signal->id,
                'signal_type' => $event->signal->signal_type,
                'severity' => $event->signal->severity_hint,
                'occurred_at' => optional($event->signal->occurred_at)->toISOString(),
            ]);
        });

        // Treat password setup/reset as email verification if user is not verified yet.
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            $user = $event->user;
            if (!$user || $user->email_verified_at) {
                return;
            }

            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
        });

        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiting for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Standard API rate limit
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Strict rate limit for expensive AI/RAG operations
        RateLimiter::for('ai-queries', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Too many AI queries. Please wait before trying again.',
                    ], 429, $headers);
                });
        });

        // QR code generation (prevent DoS)
        RateLimiter::for('qr-generation', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // File uploads
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Authentication endpoints
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
