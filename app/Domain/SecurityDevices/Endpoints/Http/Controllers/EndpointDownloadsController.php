<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseUnavailable;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Support\SecurityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class EndpointDownloadsController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        try {
            $catalogue = EndpointReleaseCatalogue::configured()->catalogue();
            $artifacts = array_values(array_filter($catalogue['artifacts'], fn (array $item): bool => $this->allowed($actor, $item)));
        } catch (EndpointReleaseUnavailable) {
            $catalogue = null;
            $artifacts = [];
        }

        return $this->privateResponse(Inertia::render('security-devices/agent-downloads', [
            'artifacts' => array_map(fn (array $item): array => [
                ...$item,
                'download_url' => '/security-devices/agent-downloads/'.$item['id'].'/'.$item['sha256'],
            ], $artifacts),
            'catalogue_available' => $catalogue !== null,
            'catalogue_expires_at' => $catalogue['expires_at'] ?? null,
            'can_manage_computers' => $actor->canDo('securityDevices.commands.admin') && $actor->canDo('securityDevices.devices.view'),
            'can_manage_collectors' => $actor->canDo('securityDevices.integrations.manage') && $actor->canDo('securityDevices.integrations.view'),
        ])->toResponse($request));
    }

    public function download(Request $request, string $artifact, string $digest): StreamedResponse
    {
        $actor = $this->actor($request);
        abort_unless($request->isSecure(), 400);
        $releases = EndpointReleaseCatalogue::configured();
        try {
            $catalogue = $releases->catalogue();
            $match = collect($catalogue['artifacts'])->first(fn (array $item): bool => $item['id'] === $artifact && hash_equals($item['sha256'], $digest));
            abort_unless($match && $this->allowed($actor, $match), 404);
            $download = $releases->download($artifact, $digest);
            // Recheck after copying; discard the snapshot if authority has changed.
            try {
                abort_unless($this->allowed($this->actor($request), $download['artifact']), 404);
            } catch (Throwable $exception) {
                fclose($download['stream']);
                throw $exception;
            }
        } catch (EndpointReleaseUnavailable) {
            abort(503, 'This download is unavailable. Refresh Agent downloads and try again.');
        }
        $stream = $download['stream'];

        return $this->privateResponse(response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $download['artifact']['filename'], [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $download['artifact']['size'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]));
    }

    private function actor(Request $request): User
    {
        return DB::transaction(function () use ($request): User {
            // Generic binaries have no Site records. Enrolment remains separately
            // authorised against the canonical computer/collector and approved Sites.
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUser((int) $request->user()->id, ['*']);
            abort_unless($actor->approved_at && $actor->email_verified_at, 403);
            abort_if($actor->external_clinical_account, 403);
            foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
                abort_if($actor->role === $role || $actor->hasRole($role), 403);
            }
            abort_if(SecurityPolicy::forceTwoFactor() && ! $actor->two_factor_confirmed_at, 403);
            abort_unless($actor->canDo('securityDevices.viewAny') && (
                $actor->canDo('securityDevices.commands.admin') || $actor->canDo('securityDevices.integrations.manage')
            ), 403);

            return $actor;
        });
    }

    private function allowed(User $actor, array $artifact): bool
    {
        return (! in_array('computer', $artifact['roles'], true) || $actor->canDo('securityDevices.commands.admin'))
            && (! in_array('site', $artifact['roles'], true) || $actor->canDo('securityDevices.integrations.manage'));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
