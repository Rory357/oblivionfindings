<?php

namespace App\Providers;

use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Events\CoverageSupplyAdded;
use App\Events\RosterPeriodPublished;
use App\Listeners\Care\NotifyOnBedExit;
use App\Listeners\Care\NotifyOnFallDetected;
use App\Listeners\Care\NotifyOnMedicationCabinetOpen;
use App\Listeners\It\CreateOrUpdateMonitoringTicket;
use App\Listeners\It\RecordItEmailDelivery;
use App\Listeners\ResolveCoverageAlertForAddedSupply;
use App\Listeners\Rostering\RecordRosterPeriodPublishedAudit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Laravel event → listener bindings.
 *
 * Most app-level listeners (Finance, Roadmap, Governance, Fleet) are
 * currently registered imperatively inside AppServiceProvider::boot().
 * This provider captures the DeviceSignalPublished fan-out in the
 * standard `$listen` array — that's the documented extension point in
 * the DeviceSignalPublished event contract.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event → listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        DeviceSignalPublished::class => [
            NotifyOnFallDetected::class,
            NotifyOnBedExit::class,
            NotifyOnMedicationCabinetOpen::class,
            CreateOrUpdateMonitoringTicket::class,
        ],
        CoverageSupplyAdded::class => [
            ResolveCoverageAlertForAddedSupply::class,
        ],
        RosterPeriodPublished::class => [
            RecordRosterPeriodPublishedAudit::class,
        ],
        NotificationSending::class => [
            RecordItEmailDelivery::class,
        ],
        NotificationSent::class => [
            RecordItEmailDelivery::class,
        ],
        NotificationFailed::class => [
            RecordItEmailDelivery::class,
        ],
        ScheduledTaskStarting::class => [
            ItAutomationRunRecorder::class.'@starting',
        ],
        ScheduledTaskFinished::class => [
            ItAutomationRunRecorder::class.'@finished',
        ],
        ScheduledTaskFailed::class => [
            ItAutomationRunRecorder::class.'@failed',
        ],
        ScheduledTaskSkipped::class => [
            ItAutomationRunRecorder::class.'@skipped',
        ],
    ];

    /**
     * Laravel registers its core event provider through Application::configure(),
     * so the framework already installs the verification listener once. This
     * application provider must not install the same fallback a second time.
     */
    protected function configureEmailVerification(): void
    {
        // Handled by Laravel's core event provider.
    }

    /**
     * Determine whether events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
