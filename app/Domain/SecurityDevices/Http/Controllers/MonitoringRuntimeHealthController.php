<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class MonitoringRuntimeHealthController extends Controller
{
    public function __invoke(Request $request, MonitoringRuntimeHealthService $health): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer?->canDo('securityDevices.events.view'), 403);

        return response()->json($health->present($viewer), 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
