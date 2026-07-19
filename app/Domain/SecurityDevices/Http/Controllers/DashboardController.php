<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\EstateOperationsPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, EstateOperationsPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.viewAny'), 403);

        return Inertia::render('security-devices/dashboard', $presenter->estate($user));
    }
}
