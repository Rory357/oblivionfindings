<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\EstateOperationsPresenter;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SiteTechnologyController extends Controller
{
    public function index(Request $request, EstateOperationsPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        return Inertia::render('security-devices/sites/index', $presenter->sites($user));
    }

    public function show(Request $request, Site $site, EstateOperationsPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        return Inertia::render('security-devices/sites/show', $presenter->site($user, $site));
    }
}
