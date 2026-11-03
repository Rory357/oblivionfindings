<?php

namespace App\Providers;

use App\Services\Chat\ChatCallingSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Lays the calling settings saved on Settings › Chat over the config each time a class that
 * reads them is resolved. Requests that never touch calling don't load them at all.
 */
final class ChatCallingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChatCallingSettings::class);
    }

    public function boot(): void
    {
        foreach (ChatCallingSettings::READERS as $reader) {
            $this->app->resolving($reader, fn () => $this->app->make(ChatCallingSettings::class)->apply());
        }
    }
}
