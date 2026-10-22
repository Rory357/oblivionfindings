<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\DiscoveryOperationsPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DiscoveryCollectorController extends Controller
{
    public function __invoke(Request $request, DiscoveryOperationsPresenter $presenter): Response
    {
        $user = $request->user();
        $asOf = now()->toImmutable();
        abort_unless($user->canDo('securityDevices.integrations.view'), 403);

        return Inertia::render('security-devices/discovery', [
            'actor_id' => (int) $user->id,
            'as_of' => $asOf->toISOString(),
            'workspace' => $presenter->present($user, $request->input('tab')),
        ]);
    }
}
