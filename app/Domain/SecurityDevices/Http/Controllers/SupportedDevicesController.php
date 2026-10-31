<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\SupportedDevicesPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

final class SupportedDevicesController extends Controller
{
    public function __invoke(Request $request, SupportedDevicesPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->canDo('securityDevices.integrations.view') || $user->canDo('securityDevices.integrations.manage')),
            403,
        );

        return Inertia::render(
            'security-devices/supported-devices',
            $presenter->present($user),
        );
    }
}
