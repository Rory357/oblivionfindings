<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\Site;
use App\Services\Medication\Controlled\ControlledStockHolding;
use App\Services\Medication\Support\SupportReviewSources;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class MedicationSupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen('App\Events\MedicationReconciliationApplied', fn ($event) => app(SupportReviewSources::class)->reconciliation($event->clientId, $event->reconciliationId));
        Event::listen('eloquent.created: '.ClientMedication::class, fn ($order) => app(SupportReviewSources::class)->order($order));
        Event::listen('eloquent.updated: '.ClientMedication::class, fn ($order) => app(SupportReviewSources::class)->order($order));
        Event::listen('eloquent.created: '.MedicationError::class, fn ($error) => app(SupportReviewSources::class)->error($error));
        Event::listen('eloquent.saved: '.ClientMedicationAdministration::class, fn ($record) => app(SupportReviewSources::class)->administration($record));

        // EA-012 (Lane C2): controlled-drug stock stays accountable. A person
        // or house that still holds some can't be archived or closed, from any
        // screen; the message lists what's held and where to clear it.
        Event::listen('eloquent.deleting: '.Client::class, fn (Client $client) => app(ControlledStockHolding::class)->assertClientCanBeArchived($client));
        Event::listen('eloquent.deleting: '.Site::class, fn (Site $site) => app(ControlledStockHolding::class)->assertSiteCanClose($site));
        Event::listen('eloquent.updating: '.Site::class, function (Site $site): void {
            $closing = ($site->isDirty('archived') && (bool) $site->archived)
                || ($site->isDirty('archived_at') && $site->archived_at !== null)
                || ($site->isDirty('is_active') && ! (bool) $site->is_active);
            if ($closing) {
                app(ControlledStockHolding::class)->assertSiteCanClose($site);
            }
        });
    }
}
