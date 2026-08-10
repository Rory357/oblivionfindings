<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Presenters\MonitoringOperationsPresenter;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringOperationsController extends Controller
{
    public function __invoke(Request $request, MonitoringOperationsPresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.events.view'), 403);

        return Inertia::render('security-devices/monitoring', [
            'pageMeta' => [
                'title' => 'Monitoring',
                'description' => 'Native estate-wide health, coverage, findings, dependencies, trends, and collection certainty.',
                'href' => '/security-devices/monitoring',
            ],
            'workspace' => $presenter->present($user, $request->only([
                'tab',
                'search',
                'state',
                'kind',
                'site_id',
                'device_id',
                'collection_mode',
            ])),
        ]);
    }
}
