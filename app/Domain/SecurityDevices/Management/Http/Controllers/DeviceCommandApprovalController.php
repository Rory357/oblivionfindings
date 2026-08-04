<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Http\Requests\DecideDeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class DeviceCommandApprovalController extends Controller
{
    public function store(
        DecideDeviceCommandRequest $request,
        DeviceCommandRequest $command,
        DeviceCommandApprovalService $approvals,
    ): RedirectResponse {
        $validated = $request->validated();
        $command = $approvals->decide($command, $request->user(), new CommandDecisionInput(
            decision: CommandApprovalDecision::from($validated['decision']),
            comment: $validated['comment'],
        ));

        return back()->with(
            'success',
            $command->status === CommandStatus::Ready
                ? 'Command approved and ready for governed dispatch.'
                : 'Command decision recorded.',
        );
    }
}
