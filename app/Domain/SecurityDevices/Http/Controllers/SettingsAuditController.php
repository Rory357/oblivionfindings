<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\SettingsAuditPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SettingsAuditController extends Controller
{
    public function __invoke(Request $request, SettingsAuditPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user && (
            $user->canDo('securityDevices.groups.manage')
            || $user->canDo('securityDevices.reports.view')
            || $user->canDo('securityDevices.commands.admin')
            || $user->canDo('securityDevices.monitoring.manage')
        ), 403);

        return Inertia::render('security-devices/settings', $presenter->present($user));
    }
}
