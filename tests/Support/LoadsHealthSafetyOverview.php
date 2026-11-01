<?php

namespace Tests\Support;

use App\Http\Controllers\HealthSafety\HealthSafetyDashboardController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/**
 * Since step C the /health-safety Overview sends its heavy parts in background requests (Inertia::defer). Tests that
 * check the whole page ask for every Overview prop in ONE Inertia request, which the controller builds in one locked
 * read with one final proof, exactly as the whole page used to be. The answer is wrapped as the page view so
 * assertInertia() and inertiaProps() work unchanged; a refused or withheld answer (403/404/500) is returned as it is.
 */
trait LoadsHealthSafetyOverview
{
    /** @param  array<string, string>  $headers  Extra headers; with X-Inertia-Partial-Data the request is sent as given. */
    protected function getOverview(string $uri = '/health-safety', array $headers = []): TestResponse
    {
        if (isset($headers['X-Inertia-Partial-Data'])) {
            return $this->get($uri, $headers);
        }

        $response = $this->get($uri, [...$headers,
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'health-safety/dashboard',
            'X-Inertia-Partial-Data' => implode(',', [
                ...HealthSafetyDashboardController::OVERVIEW_ALWAYS,
                ...HealthSafetyDashboardController::OVERVIEW_FIRST_PAINT,
                ...array_merge(...array_values(HealthSafetyDashboardController::OVERVIEW_DEFERRED)),
            ]),
        ]);
        if (! $response->isOk() || isset($headers['X-Inertia'])) {
            return $response;
        }

        $page = new Response('', $response->getStatusCode());
        $page->original = view('app', ['page' => $response->json()]);

        return TestResponse::fromBaseResponse($page);
    }
}
