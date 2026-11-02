<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Http\Controllers\Integrations\IntuneController;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController;
use App\Domain\SecurityDevices\Screens\Setup\ConnectServicePresenter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pop-up 15, Connect a service (S&D rebuild, Phase E). The wizard reads its state here and runs each
 * step through the service's own settings action, unchanged: the same validation, the same
 * encrypted save (IntegrationSecretManager), the same connection test, site matching, device sync
 * and Intune import, with their audit. This only answers in JSON so the wizard can stay open.
 *
 * Needs securityDevices.integrations.manage, as each service's settings page does; the actions
 * check it again themselves. Nothing returned includes a saved key or secret.
 */
final class ConnectServiceController extends Controller
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> service → step → the settings action */
    private const array STEPS = [
        ConnectServicePresenter::UNIFI => [
            'key' => [UnifiController::class, 'saveKey'],
            'test' => [UnifiController::class, 'testKey'],
            'sites' => [UnifiController::class, 'syncSites'],
            'match' => [UnifiController::class, 'mapSite'],
            'bring-in' => [UnifiController::class, 'syncDevices'],
        ],
        ConnectServicePresenter::INTUNE => [
            'key' => [IntuneController::class, 'saveKey'],
            'test' => [IntuneController::class, 'testKey'],
            'find' => [IntuneController::class, 'discover'],
            'bring-in' => [IntuneController::class, 'importDevices'],
        ],
    ];

    public function __construct(private readonly ConnectServicePresenter $presenter) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->viewer($request);

        return response()->json($this->presenter->present($user))->header('Cache-Control', 'no-store');
    }

    /**
     * Run one step. `outcome` is "done", "warning" (it ran, with a caveat) or "failed" (the action
     * refused or the service didn't answer); the wizard words it. Field errors come back as 422.
     */
    public function run(Request $request, string $service, string $step): JsonResponse
    {
        $user = $this->viewer($request);
        $target = self::STEPS[$service][$step] ?? null;
        abort_if($target === null, 404);

        app()->call([app($target[0]), $target[1]]);

        // What the action flashed in this request (an older page's message may still be in the session).
        $session = $request->session();
        $flashed = (array) $session->get('_flash.new', []);
        $failed = in_array('error', $flashed, true) && $session->get('error') !== null;
        $warned = in_array('warning', $flashed, true) && $session->get('warning') !== null;
        // The wizard says what happened; these mustn't toast on the next page as well.
        foreach (['success', 'error', 'warning', 'info', 'unifi_action_outcome'] as $key) {
            if (in_array($key, $flashed, true)) {
                $session->forget($key);
            }
        }

        return response()->json([
            'outcome' => $failed ? 'failed' : ($warned ? 'warning' : 'done'),
            'state' => $this->presenter->present($user),
        ])->header('Cache-Control', 'no-store');
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && ConnectServicePresenter::canConnect($user), 403);

        return $user;
    }
}
