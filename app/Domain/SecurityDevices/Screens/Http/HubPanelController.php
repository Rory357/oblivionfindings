<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Screens\Hubs\HubPanelPresenter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The hub panel (S&D rebuild drawing sdr-13), read when it opens from Sites or the Site page. */
final class HubPanelController extends Controller
{
    public function __invoke(Request $request, MonitoringCollector $collector, HubPanelPresenter $panel): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()
            ->json($panel->present($user, $collector))
            ->header('Cache-Control', 'private, no-store');
    }
}
