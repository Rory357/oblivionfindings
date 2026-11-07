<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchActionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeviceCommandBatchExecutionController extends Controller
{
    public function store(
        Request $request,
        DeviceCommandBatch $batch,
        DeviceCommandBatchActionService $actions,
    ): RedirectResponse|JsonResponse {
        $result = $actions->queue($batch, $request->user());

        // S&D rebuild C6: the Devices bulk bar sends the ready ones straight after its one confirm.
        if ($request->expectsJson()) {
            return response()->json($result)->header('Cache-Control', 'no-store');
        }

        return back()->with(
            'success',
            "Queued {$result['processed']} child command(s); {$result['skipped']} changed or ineligible child command(s) were left safely unqueued.",
        );
    }
}
