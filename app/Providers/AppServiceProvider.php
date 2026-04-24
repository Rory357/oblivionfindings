<?php

namespace App\Providers;

use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Models\AssetMaintenanceLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\EmergencyDrill;
use App\Models\ClientNote;
use App\Models\FleetFuelLog;
use App\Models\FleetIncident;
use App\Models\FleetWorkOrder;
use App\Models\RestraintEvent;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteChecklistRun;
use App\Models\ClientLedgerEntry;
use App\Models\HouseLedgerEntry;
use App\Models\Timesheet;
use App\Models\WorkplaceInjury;
use App\Observers\AssetMaintenanceLogObserver;
use App\Observers\ClientIncidentObserver;
use App\Observers\EmergencyDrillObserver;
use App\Observers\ClientLedgerEntryObserver;
use App\Observers\ClientNoteObserver;
use App\Observers\FleetFuelLogObserver;
use App\Observers\FleetIncidentObserver;
use App\Observers\HouseLedgerEntryObserver;
use App\Observers\FleetWorkOrderObserver;
use App\Observers\HrCourseEnrollmentObserver;
use App\Observers\HrExpenseClaimObserver;
use App\Observers\RestraintEventObserver;
use App\Observers\SafeguardingConcernObserver;
use App\Observers\ShiftObserver;
use App\Observers\SiteObserver;
use App\Observers\SiteHazardObserver;
use App\Observers\SiteChecklistRunObserver;
use App\Observers\TimesheetMileageObserver;
use App\Observers\WorkplaceInjuryObserver;
use App\Events\FleetSignalEmitted;
use App\Events\FleetWanderingAlertTriggered;
use App\Services\AuditLogger;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Notifications\ExpoPushProvider;
use App\Services\Notifications\FailingSmsProvider;
use App\Services\Notifications\FailingPushProvider;
use App\Services\Notifications\PushProvider;
use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\TwilioSmsProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            $registry->register(
                \App\Services\Integration\Adapters\QueclinkAdapter::PROVIDER_SLUG,
                \App\Services\Integration\Adapters\QueclinkAdapter::class,
            );
            $registry->register(
                \App\Services\Integration\Adapters\MilesightAdapter::PROVIDER_SLUG,
                \App\Services\Integration\Adapters\MilesightAdapter::class,
            );

            return $registry;
        });

        $this->app->bind(SmsProvider::class, function () {
            $provider = config('services.sms.provider');

            return match ($provider) {
                'twilio' => new TwilioSmsProvider(
                    config('services.sms.twilio.account_sid'),
                    config('services.sms.twilio.auth_token'),
                    config('services.sms.twilio.from'),
                ),
                null, '' => new FailingSmsProvider('SMS provider is not configured.'),
                default => new FailingSmsProvider('Unsupported SMS provider: '.$provider),
            };
        });

        $this->app->bind(PushProvider::class, function () {
            $provider = config('services.push.provider');

            return match ($provider) {
                'expo' => new ExpoPushProvider(
                    config('services.push.expo.endpoint'),
                    config('services.push.expo.access_token'),
                ),
                null, '' => new FailingPushProvider('Push provider is not configured.'),
                default => new FailingPushProvider('Unsupported push provider: '.$provider),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'client' => Client::class,
            'staff' => User::class,
        ]);

        Shift::observe(ShiftObserver::class);
        ClientNote::observe(ClientNoteObserver::class);
        Site::observe(SiteObserver::class);
        SiteHazard::observe(SiteHazardObserver::class);
        SiteChecklistRun::observe(SiteChecklistRunObserver::class);
        \App\Domain\SecurityDevices\Models\DeviceEvent::observe(
            \App\Observers\DeviceEventObserver::class,
        );

        // H&S → Control Room bridge observers
        ClientIncident::observe(ClientIncidentObserver::class);
        SafeguardingConcern::observe(SafeguardingConcernObserver::class);
        FleetIncident::observe(FleetIncidentObserver::class);
        WorkplaceInjury::observe(WorkplaceInjuryObserver::class);
        RestraintEvent::observe(RestraintEventObserver::class);
        EmergencyDrill::observe(EmergencyDrillObserver::class);

        // Financial event observers — operational costs → GL
        FleetFuelLog::observe(FleetFuelLogObserver::class);
        FleetWorkOrder::observe(FleetWorkOrderObserver::class);
        AssetMaintenanceLog::observe(AssetMaintenanceLogObserver::class);
        HrExpenseClaim::observe(HrExpenseClaimObserver::class);
        HrCourseEnrollment::observe(HrCourseEnrollmentObserver::class);
        Timesheet::observe(TimesheetMileageObserver::class);
        HouseLedgerEntry::observe(HouseLedgerEntryObserver::class);
        ClientLedgerEntry::observe(ClientLedgerEntryObserver::class);

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

            // Broadcast wandering alert when a geofence breach involves a resident-linked tracker
            if (in_array($event->signal->signal_type, ['geofence.breach', 'vehicle.sos'])) {
                $asset = $event->signal->asset;
                if ($asset && $asset->client_id) {
                    $client = $asset->client;
                    $payload = $event->signal->payload ?? [];

                    broadcast(new FleetWanderingAlertTriggered(
                        alertId: $event->signal->id,
                        alertType: $event->signal->signal_type,
                        severity: $event->signal->severity_hint ?? 'medium',
                        clientName: $client ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) : null,
                        clientId: $asset->client_id,
                        latitude: $payload['lat'] ?? $payload['latitude'] ?? null,
                        longitude: $payload['lng'] ?? $payload['longitude'] ?? null,
                        geofenceName: $payload['geofence_name'] ?? null,
                        triggeredAt: optional($event->signal->occurred_at)->toISOString() ?? now()->toISOString(),
                    ));
                }
            }
        });

        // Cross-domain event listeners
        Event::listen(
            \App\Domain\Finance\Events\JournalPosted::class,
            \App\Listeners\Finance\LogJournalPosted::class
        );
        Event::listen(
            \App\Domain\Finance\Events\JournalPosted::class,
            \App\Listeners\Finance\AllocatePayrollCosts::class
        );
        Event::listen(
            \App\Domain\Roadmap\Events\InitiativeScored::class,
            \App\Listeners\Roadmap\LogInitiativeScored::class
        );
        Event::listen(
            \App\Domain\Roadmap\Events\QuarterlyPlanPublished::class,
            \App\Listeners\Governance\LogQuarterlyPlanPublished::class
        );

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

        // Password reset (strict)
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinutes(15, 3)->by($request->ip());
        });

        // Registration
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perHour(5)->by($request->ip());
        });
    }
}
