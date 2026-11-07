<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Overview\OverviewPresenter;
use App\Domain\SecurityDevices\Screens\Overview\WorkerOverviewPresenter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Security & devices home (S&D rebuild drawings pg-sdr-1, 1b, 1c, 18 and 18b). */
final class OverviewController extends Controller
{
    public function __invoke(Request $request, OverviewPresenter $overview, DeviceFormOptions $formOptions, WorkerOverviewPresenter $worker): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        // Safety fix round (7 Nov 2026): the care-alert key admits the worker's own-house Overview
        // (pg-sdr-1c) and nothing else in Security & devices; everyone else still needs viewAny.
        $workerView = OverviewPresenter::showsWorkerView($user);
        abort_unless($user->canDo('securityDevices.viewAny') || ($workerView && $user->canDo(CareAlertController::KEY)), 403);

        // pg-sdr-1c: someone without the keys of people who look after devices sees their own house.
        if ($workerView) {
            return Inertia::render('security-devices/overview/index', $worker->present($user, $request->only(['site'])));
        }

        return Inertia::render('security-devices/overview/index', [
            // kind / day: the Activity tab's own filters (pg-sdr-18c).
            ...$overview->present($user, $request->only(['tab', 'site', 'month', 'kind', 'day'])),
            // Add a device and Scan a network load their choices only when one opens.
            'deviceForm' => Inertia::optional(fn (): array => $formOptions->present($user)),
        ]);
    }
}
