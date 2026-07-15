<?php

namespace App\Providers;

use App\Domain\Finance\Events\JournalPosted;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Roadmap\Events\InitiativeScored;
use App\Domain\Roadmap\Events\QuarterlyPlanPublished;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Events\FleetSignalEmitted;
use App\Events\FleetWanderingAlertTriggered;
use App\Listeners\Finance\AllocatePayrollCosts;
use App\Listeners\Finance\LogJournalPosted;
use App\Listeners\Governance\LogQuarterlyPlanPublished;
use App\Listeners\Roadmap\LogInitiativeScored;
use App\Models\AssetMaintenanceLog;
use App\Models\AssetValue;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\ClientAssessment;
use App\Models\ClientBowelEntry;
use App\Models\ClientConsent;
use App\Models\ClientDocument;
use App\Models\ClientExcursionRequest;
use App\Models\ClientFluidEntry;
use App\Models\ClientFundTransaction;
use App\Models\ClientIncident;
use App\Models\ClientLeaveRequest;
use App\Models\ClientLedgerEntry;
use App\Models\ClientNote;
use App\Models\ClientPathPlan;
use App\Models\ClientRoutine;
use App\Models\ClientSeizureEntry;
use App\Models\EmergencyDrill;
use App\Models\FirstAidRecord;
use App\Models\FleetFuelLog;
use App\Models\FleetIncident;
use App\Models\FleetWorkOrder;
use App\Models\FundingClaim;
use App\Models\HouseLedgerEntry;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\RestraintEvent;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingInvestigation;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SubstanceExposureRecord;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Observers\AssetMaintenanceLogObserver;
use App\Observers\AssetValueObserver;
use App\Observers\ClientConsentObserver;
use App\Observers\ClientFundTransactionObserver;
use App\Observers\ClientIncidentObserver;
use App\Observers\ClientLedgerEntryObserver;
use App\Observers\ClientNoteObserver;
use App\Observers\DeviceEventObserver;
use App\Observers\EmergencyDrillObserver;
use App\Observers\FirstAidObserver;
use App\Observers\FleetFuelLogObserver;
use App\Observers\FleetIncidentObserver;
use App\Observers\FleetWorkOrderObserver;
use App\Observers\FundingClaimObserver;
use App\Observers\HouseLedgerEntryObserver;
use App\Observers\HrCourseEnrollmentObserver;
use App\Observers\HrEmployeeProfileObserver;
use App\Observers\HrLeaveRequestObserver;
use App\Observers\ProjectsToTimelineObserver;
use App\Observers\RestraintEventObserver;
use App\Observers\SafeguardingConcernObserver;
use App\Observers\SafeguardingInvestigationObserver;
use App\Observers\ShiftObserver;
use App\Observers\SiteChecklistRunObserver;
use App\Observers\SiteHazardObserver;
use App\Observers\SiteInspectionRecordObserver;
use App\Observers\SiteObserver;
use App\Observers\SubstanceExposureRecordObserver;
use App\Observers\TimesheetMileageObserver;
use App\Observers\WorkplaceInjuryObserver;
use App\Services\AuditLogger;
use App\Services\Catering\DeliveryProviders\DeliveryProviderManager;
use App\Services\Integration\Adapters\MilesightAdapter;
use App\Services\Integration\Adapters\QueclinkAdapter;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Notifications\ExpoPushProvider;
use App\Services\Notifications\FailingPushProvider;
use App\Services\Notifications\FailingSmsProvider;
use App\Services\Notifications\PushProvider;
use App\Services\Notifications\SmsProvider;
use App\Services\Notifications\TwilioSmsProvider;
use App\Services\Notifications\WebPushProvider;
use App\Services\UserSiteAccessService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Google\GoogleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // On Windows + Herd, the `mysql` client binary isn't on PATH by
        // default, but Laravel's MigrateCommand shells out to it when
        // loading a schema dump. Prepend the standard install dirs to
        // PATH so `php artisan migrate` finds it. No-op on Linux/Mac
        // (the binary is already on PATH there) and harmless if no
        // candidate dir exists.
        if (PHP_OS_FAMILY === 'Windows' && $this->app->runningInConsole()) {
            $this->prependMysqlClientToPath();
        }

        $this->app->singleton(IntegrationAdapterRegistry::class, function () {
            $registry = new IntegrationAdapterRegistry;
            $registry->register('unifi', UnifiAdapter::class);
            $registry->register(
                QueclinkAdapter::PROVIDER_SLUG,
                QueclinkAdapter::class,
            );
            $registry->register(
                MilesightAdapter::PROVIDER_SLUG,
                MilesightAdapter::class,
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
                'expo' => $this->app->make(ExpoPushProvider::class),
                null, '' => new FailingPushProvider('Push provider is not configured.'),
                default => new FailingPushProvider('Unsupported push provider: '.$provider),
            };
        });

        $this->app->bind(ExpoPushProvider::class, fn () => new ExpoPushProvider(
            config('services.push.expo.endpoint'),
            config('services.push.expo.access_token'),
        ));

        $this->app->bind(WebPushProvider::class);

        $this->app->singleton(DeliveryProviderManager::class);
        $this->app->scoped(UserSiteAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'client' => Client::class,
            'staff' => User::class,
            // IT activity trail subjects (it_ticket_events.subject_type) and
            // attachment parents (it_attachments.attachable_type) — stable
            // short keys so DB rows survive class moves.
            'it_ticket' => ItTicket::class,
            'it_provisioning_request' => ItProvisioningRequest::class,
            'it_ticket_comment' => ItTicketComment::class,
        ]);

        Shift::observe(ShiftObserver::class);
        ClientConsent::observe(ClientConsentObserver::class);
        ClientNote::observe(ClientNoteObserver::class);
        ClientBowelEntry::observe(ProjectsToTimelineObserver::class);
        ClientFluidEntry::observe(ProjectsToTimelineObserver::class);
        ClientSeizureEntry::observe(ProjectsToTimelineObserver::class);
        ClientRoutine::observe(ProjectsToTimelineObserver::class);
        ClientAppointment::observe(ProjectsToTimelineObserver::class);
        ClientAssessment::observe(ProjectsToTimelineObserver::class);
        ClientDocument::observe(ProjectsToTimelineObserver::class);
        ClientLeaveRequest::observe(ProjectsToTimelineObserver::class);
        ClientExcursionRequest::observe(ProjectsToTimelineObserver::class);
        ClientPathPlan::observe(ProjectsToTimelineObserver::class);
        Site::observe(SiteObserver::class);
        SiteHazard::observe(SiteHazardObserver::class);
        SiteChecklistRun::observe(SiteChecklistRunObserver::class);
        DeviceEvent::observe(
            DeviceEventObserver::class,
        );

        // H&S → Control Room bridge observers
        ClientIncident::observe(ClientIncidentObserver::class);
        ClientIncident::observe(ProjectsToTimelineObserver::class);
        SafeguardingConcern::observe(SafeguardingConcernObserver::class);
        SafeguardingInvestigation::observe(SafeguardingInvestigationObserver::class);
        FleetIncident::observe(FleetIncidentObserver::class);
        WorkplaceInjury::observe(WorkplaceInjuryObserver::class);
        SubstanceExposureRecord::observe(SubstanceExposureRecordObserver::class);
        SiteInspectionRecord::observe(SiteInspectionRecordObserver::class);
        RestraintEvent::observe(RestraintEventObserver::class);
        EmergencyDrill::observe(EmergencyDrillObserver::class);
        FirstAidRecord::observe(FirstAidObserver::class);
        HrEmployeeProfile::observe(HrEmployeeProfileObserver::class);
        HrLeaveRequest::observe(HrLeaveRequestObserver::class);

        // Financial event observers — operational costs → GL
        FleetFuelLog::observe(FleetFuelLogObserver::class);
        FleetWorkOrder::observe(FleetWorkOrderObserver::class);
        AssetMaintenanceLog::observe(AssetMaintenanceLogObserver::class);
        // Capitalisation capture: high-value asset valuations → fixed-asset register
        AssetValue::observe(AssetValueObserver::class);
        // HrExpenseClaim GL posting is handled solely by
        // ExpenseService::approveClaim() → PostExpenseJournalJob (per-category DR /
        // CR 2000 Accounts Payable). The former HrExpenseClaimObserver posted a
        // SECOND, conflicting journal (DR 6500 / CR 2310) on the same approval,
        // double-booking the expense — removed. See
        // docs/compensation-hub-redesign/EXPENSE_GL_DOUBLE_POST.md.
        HrCourseEnrollment::observe(HrCourseEnrollmentObserver::class);
        Timesheet::observe(TimesheetMileageObserver::class);
        HouseLedgerEntry::observe(HouseLedgerEntryObserver::class);
        ClientLedgerEntry::observe(ClientLedgerEntryObserver::class);
        FundingClaim::observe(FundingClaimObserver::class);
        ClientFundTransaction::observe(ClientFundTransactionObserver::class);

        // Register Socialite providers (Microsoft + Google)
        Event::listen(
            SocialiteWasCalled::class,
            [MicrosoftExtendSocialite::class, 'handle']
        );
        Event::listen(
            SocialiteWasCalled::class,
            [GoogleExtendSocialite::class, 'handle']
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
                        clientName: $client ? trim(($client->first_name ?? '').' '.($client->last_name ?? '')) : null,
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
            JournalPosted::class,
            LogJournalPosted::class
        );
        Event::listen(
            JournalPosted::class,
            AllocatePayrollCosts::class
        );
        Event::listen(
            InitiativeScored::class,
            LogInitiativeScored::class
        );
        Event::listen(
            QuarterlyPlanPublished::class,
            LogQuarterlyPlanPublished::class
        );

        // Treat password setup/reset as email verification if user is not verified yet.
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            $user = $event->user;
            if (! $user || $user->email_verified_at) {
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

    /**
     * Mirror of `tests/TestCase::configureMysqlClientPath()` for runtime
     * CLI use. Locates `mysql.exe` in the standard install dirs and
     * prepends its directory to PATH so `php artisan migrate` can load
     * the schema dump on Herd Windows installs.
     */
    private function prependMysqlClientToPath(): void
    {
        $candidates = array_filter([
            getenv('MYSQL_BINARY') ?: null,
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin',
            'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin',
            'C:\\Program Files\\MariaDB 11.4\\bin',
            'C:\\Program Files\\MariaDB 11.3\\bin',
        ]);

        $currentPath = (string) (getenv('PATH') ?: '');

        foreach ($candidates as $candidate) {
            $directory = is_dir($candidate) ? $candidate : dirname($candidate);
            if (! is_dir($directory)) {
                continue;
            }
            if (str_contains(strtolower($currentPath), strtolower($directory))) {
                return; // already on PATH
            }
            putenv('PATH='.$directory.PATH_SEPARATOR.$currentPath);
            $_ENV['PATH'] = $directory.PATH_SEPARATOR.$currentPath;
            $_SERVER['PATH'] = $directory.PATH_SEPARATOR.$currentPath;

            return;
        }
    }
}
