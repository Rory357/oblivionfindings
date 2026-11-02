<?php

namespace App\Domain\SecurityDevices\Hubs\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorEnrollmentService;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Domain\SecurityDevices\Hubs\Exceptions\HubAwaitingApproval;
use App\Domain\SecurityDevices\Hubs\Exceptions\HubRemoved;
use App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy;
use App\Domain\SecurityDevices\Hubs\Services\HubVaultKeyService;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hub-facing calls added in S&D Phase B. Every call is authenticated by the hub's certificate
 * and signature (the monitoring.collector middleware), and only an approved hub gets past it,
 * except for the update report and deregister.
 */
final class HubRuntimeController extends Controller
{
    /** The key for the hub's encrypted login store, sealed to the hub's own key. */
    public function vaultKey(Request $request, HubVaultKeyService $vault): JsonResponse
    {
        try {
            return response()->json($vault->sealedFor($this->collector($request)), 200, $this->noStore());
        } catch (HubAwaitingApproval) {
            return response()->json(['state' => 'waiting_for_approval'], 409, $this->noStore());
        } catch (HubRemoved) {
            return response()->json(['state' => 'removed'], 410, $this->noStore());
        }
    }

    /** What happened when the hub tried an update: installed, rolled back or failed. */
    public function updateReport(Request $request, HubUpdatePolicy $updates): JsonResponse
    {
        $report = $request->input('report');
        if (! is_array($report)) {
            return response()->json(['message' => 'Hub request is invalid.'], 422);
        }
        try {
            $collector = $updates->recordReport($this->collector($request), $report);
        } catch (DomainException) {
            return response()->json(['message' => 'Hub update report is invalid.'], 422);
        }

        return response()->json(['recorded' => true, 'update' => $updates->instructionFor($collector)]);
    }

    /**
     * The hub is being uninstalled (Windows Settings › Apps, or the installer's Remove): it
     * asks to be removed, so Oblivion shows "hub removed" and stops trusting it. Same removal
     * as an admin's, including destroying the login-store key.
     */
    public function deregister(Request $request, CollectorEnrollmentService $enrollments): JsonResponse
    {
        $collector = $this->collector($request);
        $enrollments->revoke($collector, null);
        AuditLogger::logOrFail('security_devices.hub.uninstalled', $collector, [
            'site_id' => (int) $collector->site_id,
        ]);

        return response()->json(['state' => 'removed'], 200, $this->noStore());
    }

    /**
     * The release file the hub was told to install. The hub checks the package's own
     * signature before it swaps anything in; this only serves what the signed release list
     * names, for this hub's platform.
     */
    public function updateDownload(Request $request, HubUpdatePolicy $updates): Response
    {
        $collector = $this->collector($request);
        $id = $request->input('artifact_id');
        $digest = $request->input('sha256');
        $target = $updates->instructionFor($collector)['target'];
        if (! is_string($id) || ! is_string($digest) || $target === null) {
            return response()->json(['message' => 'No update is available for this hub.'], 409);
        }
        $allowed = collect($target['artifacts'])->first(fn (array $artifact): bool => $artifact['id'] === $id && hash_equals($artifact['sha256'], $digest));
        if ($allowed === null) {
            return response()->json(['message' => 'No update is available for this hub.'], 409);
        }
        try {
            $download = EndpointReleaseCatalogue::configured()->download($id, $digest);
        } catch (EndpointReleaseUnavailable) {
            return response()->json(['message' => 'The update file is unavailable.'], 409);
        }
        $stream = $download['stream'];

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, (string) $download['artifact']['filename'], [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $download['artifact']['size'],
            'X-Content-Type-Options' => 'nosniff',
            ...$this->noStore(),
        ]);
    }

    private function collector(Request $request): MonitoringCollector
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401);

        return $collector;
    }

    /** @return array<string, string> */
    private function noStore(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache'];
    }
}
