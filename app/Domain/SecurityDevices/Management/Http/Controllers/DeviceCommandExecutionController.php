<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeviceCommandExecutionController extends Controller
{
    public function store(
        Request $request,
        DeviceCommandRequest $command,
        DeviceCommandQueueService $queue,
    ): RedirectResponse {
        $queue->queue($command, $request->user());

        return back()->with('success', 'Command added to the governed execution queue.');
    }
}
