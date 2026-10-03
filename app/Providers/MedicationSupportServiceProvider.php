<?php

namespace App\Providers;

use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Services\Medication\Support\SupportReviewSources;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class MedicationSupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen('eloquent.created: '.ClientMedication::class, fn ($order) => app(SupportReviewSources::class)->order($order));
        Event::listen('eloquent.updated: '.ClientMedication::class, fn ($order) => app(SupportReviewSources::class)->order($order));
        Event::listen('eloquent.created: '.MedicationError::class, fn ($error) => app(SupportReviewSources::class)->error($error));
        Event::listen('eloquent.saved: '.ClientMedicationAdministration::class, fn ($record) => app(SupportReviewSources::class)->administration($record));
    }
}
