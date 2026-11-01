<?php

namespace App\Domain\SecurityDevices\Access\Http;

use App\Domain\SecurityDevices\Access\AccessSetup;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Screens\Setup\SetupPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * S&D Phase H (pg-sdr-15m, pop-up 91): connect a Site's UniFi Access console through its hub, save
 * its token, trust its certificate, confirm every door opens from the inside, and the two
 * Doors & access switches. Another Site's console is 404.
 */
final class AccessSetupController extends Controller
{
    public function __construct(
        private readonly AccessSetup $setup,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'address' => ['required', 'string', 'max:45'],
            'port' => ['required', 'integer'],
        ]);
        $console = $this->setup->connect($user, (int) $data['site_id'], trim($data['address']), (int) $data['port']);

        return response()->json(['console' => $this->setup->console($user, $console), 'message' => 'Saved. The hub reads the console on its next round.'], 201);
    }

    public function show(Request $request, int $console): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['console' => $this->setup->console($user, $this->console($user, $console))]);
    }

    public function token(Request $request, int $console): JsonResponse
    {
        $user = $this->user($request);
        $model = $this->console($user, $console);
        $data = $request->validate(['token' => ['required', 'string', 'max:4096']]);
        $this->setup->saveToken($user, $model, (string) $data['token']);

        return response()->json(['message' => 'Token saved. Oblivion won’t show it again.']);
    }

    public function trust(Request $request, int $console): JsonResponse
    {
        $user = $this->user($request);
        $model = $this->console($user, $console);
        $data = $request->validate(['fingerprint' => ['required', 'string', 'max:200']]);
        $this->setup->trustCertificate($user, $model, $data['fingerprint']);

        return response()->json(['message' => 'Certificate trusted. The hub reads the doors and people on its next round.']);
    }

    public function confirmDoors(Request $request, int $console): JsonResponse
    {
        $user = $this->user($request);
        $this->setup->confirmDoors($user, $this->console($user, $console));

        return response()->json(['message' => 'Thanks. Every door is confirmed to open from the inside.']);
    }

    public function settings(Request $request, SecurityDevicesSettings $settings): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'leavers' => ['sometimes', 'boolean'],
            'changes' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $changes = array_filter([
            SecurityDevicesSettings::ACCESS_LEAVERS => $data['leavers'] ?? null,
            SecurityDevicesSettings::ACCESS_CHANGES => $data['changes'] ?? null,
        ], fn ($value): bool => $value !== null);
        try {
            $settings->update($user, $changes, $data['reason'] ?? null);
        } catch (AuthorizationException) {
            abort(403);
        }

        return response()->json(['message' => 'Saved.']);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && SetupPresenter::canSeeAccess($user), 403);

        return $user;
    }

    private function console(User $user, int $id): AccessConsole
    {
        $console = AccessConsole::query()->findOrFail($id);
        abort_unless($this->access->canViewAllSites($user) || in_array((int) $console->site_id, $this->access->accessibleSiteIds($user), true), 404);

        return $console;
    }
}
