<?php

namespace App\Providers;

use App\Domain\Obli\ObliProviderHttp;
use App\Domain\Obli\ObliTextProvider;
use GuzzleHttp\Client;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ObliServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ObliTextProvider::class, fn () => new ObliProviderHttp(new Client));
    }

    public function boot(): void
    {
        RateLimiter::for('obli-api', fn (Request $request) => [
            Limit::perMinute(1000)->by('obli-ip:'.$request->ip()),
            Limit::perMinute(120)->by('obli-session:'.hash('sha256', $request->bearerToken() ?? $request->ip())),
        ]);
        RateLimiter::for('obli-token', fn (Request $request) => Limit::perMinute(60)->by('obli-token:'.$request->ip()));
        RateLimiter::for('obli-chat', fn (Request $request) => Limit::perMinute(10)->by('obli-chat:'.hash('sha256', $request->bearerToken() ?? $request->ip())));
        RateLimiter::for('obli-ticket', fn (Request $request) => Limit::perMinute(20)->by('obli-ticket:'.hash('sha256', $request->bearerToken() ?? $request->ip())));
    }
}
