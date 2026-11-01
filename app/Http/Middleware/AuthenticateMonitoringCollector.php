<?php

namespace App\Http\Middleware;

use App\Domain\Monitoring\Services\CollectorTransportAuthenticator;
use App\Domain\SecurityDevices\Hubs\Exceptions\HubRemoved;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuthenticateMonitoringCollector
{
    /**
     * What a hub may do before an admin approves it: say it's alive and report its version,
     * so Oblivion can show "Connected, waiting for approval". Nothing else: no work, no
     * uploads, no saved logins (S&D Phase B, hub approval).
     */
    private const BEFORE_APPROVAL = [
        'api.monitoring.collectors.heartbeat',
        'api.monitoring.collectors.update-report',
    ];

    public function __construct(private CollectorTransportAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $collector = $this->authenticator->authenticate($request);
        } catch (HubRemoved) {
            // Only a caller holding the hub's own key gets this far: tell it to wipe its logins.
            return new JsonResponse(['state' => 'removed', 'message' => 'This hub has been removed. Its saved logins must be wiped.'], 410);
        } catch (Throwable) {
            return new JsonResponse(['message' => 'Collector authentication failed.'], 401);
        }
        if ($collector->approved_at === null && ! in_array($request->route()?->getName(), self::BEFORE_APPROVAL, true)) {
            return new JsonResponse(['state' => 'waiting_for_approval', 'message' => 'This hub is waiting for an admin to approve it.'], 409);
        }
        $request->attributes->set('monitoring_collector', $collector);

        return $next($request);
    }
}
