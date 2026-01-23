<?php

namespace App\Providers;

use App\Models\ClientNote;
use App\Models\Shift;
use App\Observers\ClientNoteObserver;
use App\Observers\ShiftObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
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
        Shift::observe(ShiftObserver::class);
        ClientNote::observe(ClientNoteObserver::class);
    }
}
