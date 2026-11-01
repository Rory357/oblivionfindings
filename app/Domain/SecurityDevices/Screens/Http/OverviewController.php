<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Screens\Overview\OverviewPresenter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Security & devices home (S&D rebuild drawings pg-sdr-1, 1b and 18). */
final class OverviewController extends Controller
{
    public function __invoke(Request $request, OverviewPresenter $overview): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.viewAny'), 403);

        return Inertia::render('security-devices/overview/index', $overview->present($user, $request->only(['tab', 'site'])));
    }
}
