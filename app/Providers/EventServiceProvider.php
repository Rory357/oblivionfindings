<?php

namespace App\Providers;

use App\Domain\SecurityDevices\Events\DeviceSignalPublished;
use App\Listeners\Care\NotifyOnBedExit;
use App\Listeners\Care\NotifyOnFallDetected;
use App\Listeners\Care\NotifyOnMedicationCabinetOpen;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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
        ],
    ];

    /**
     * Determine whether events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
