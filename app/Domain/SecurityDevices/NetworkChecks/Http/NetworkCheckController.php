<?php

namespace App\Domain\SecurityDevices\NetworkChecks\Http;

use App\Domain\SecurityDevices\NetworkChecks\NetworkChecks;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pop-up 97, IT's side: the pop-up's data, asking, following a session, and raising an IT job.
 * The service checks the permission (IT: devices.view + integrations.manage) and the Site on every
 * call; another Site is a 404.
 */
final class NetworkCheckController extends Controller
{
    public function panel(Request $request, int $site, NetworkChecks $checks): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(NetworkChecks::mayAsk($user), 403);
        $session = $request->query('session');

        return $this->json($checks->panel($user, $this->site($site), is_string($session) && preg_match('/\A[0-9a-fA-F-]{36}\z/', $session) === 1 ? $session : null));
    }

    public function ask(Request $request, int $site, NetworkChecks $checks): JsonResponse
    {
        $user = $this->user($request);
        abort_unless(NetworkChecks::mayAsk($user), 403);
        $data = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
            'device_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $session = $checks->ask($user, $this->site($site), (string) $data['question'], isset($data['device_id']) ? (int) $data['device_id'] : null);

        return $this->json(['session' => $checks->present($session->fresh() ?? $session, $user)], 201);
    }

    public function show(Request $request, string $uuid, NetworkChecks $checks): JsonResponse
    {
        $user = $this->user($request);

        return $this->json(['session' => $checks->present($checks->find($user, $uuid), $user)]);
    }

    public function ticket(Request $request, string $uuid, NetworkChecks $checks): JsonResponse
    {
        $user = $this->user($request);

        return $this->json(['ticket' => $checks->raiseTicket($user, $checks->find($user, $uuid))]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function site(int $id): Site
    {
        return Site::query()->whereKey($id)->firstOrFail(['id', 'name']);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}
