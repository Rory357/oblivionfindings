<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\IntegrationsWorkspacePresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationsHubController extends Controller
{
    public function __invoke(Request $request, IntegrationsWorkspacePresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.integrations.view'), 403);

        return Inertia::render('security-devices/integrations', $presenter->present($user));
    }
}
