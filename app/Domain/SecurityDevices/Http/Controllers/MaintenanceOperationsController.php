<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\MaintenanceOperationsPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceOperationsController extends Controller
{
    public function __invoke(Request $request, MaintenanceOperationsPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.view'), 403);

        return Inertia::render('security-devices/maintenance', [
            'pageMeta' => [
                'title' => 'Maintenance',
                'description' => 'Plan, perform, and reconcile device servicing, calibration, firmware, and configuration work.',
                'href' => '/security-devices/maintenance',
            ],
            'workspace' => $presenter->present($user, $request->only([
                'tab',
                'search',
                'status',
                'type',
                'site_id',
                'device_id',
                'domain',
            ])),
        ]);
    }
}
