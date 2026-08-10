<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Http\Requests\DecideDeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBatchActionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class DeviceCommandBatchDecisionController extends Controller
{
    public function store(
        DecideDeviceCommandRequest $request,
        DeviceCommandBatch $batch,
        DeviceCommandBatchActionService $actions,
    ): RedirectResponse {
        $validated = $request->validated();
        $result = $actions->decide(
            $batch,
            $request->user(),
            CommandApprovalDecision::from($validated['decision']),
            $validated['comment'],
        );

        return back()->with(
            'success',
            "Decision recorded for {$result['processed']} child command(s); {$result['skipped']} changed or ineligible child command(s) were left unchanged.",
        );
    }
}
