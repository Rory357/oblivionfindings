<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Exceptions\CommandDispatchPreconditionException;
use App\Domain\SecurityDevices\Management\Jobs\DispatchDeviceCommand;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeviceCommandQueueService
{
    public function __construct(
        private readonly GovernedCommandDispatchService $dispatch,
        private readonly DeviceCommandAuditService $audit,
    ) {}

    public function queue(DeviceCommandRequest $request, User $actor): DeviceCommandRequest
    {
        $result = DB::transaction(function () use ($request, $actor): array {
            $locked = DeviceCommandRequest::query()
                ->with(['device', 'requestedBy', 'approvedBy', 'approvals'])
                ->lockForUpdate()
                ->findOrFail($request->id);
            if ($locked->status !== CommandStatus::Ready) {
                throw ValidationException::withMessages([
                    'command' => 'Only a ready command can be added to the execution queue.',
                ]);
            }
            try {
                $this->dispatch->assertDispatchable($locked, $actor);
            } catch (CommandDispatchPreconditionException $failure) {
                $this->dispatch->applyPreconditionFailure($locked, $actor, $failure);

                return ['request' => $locked, 'failure' => $failure];
            }
            $locked->status = CommandStatus::Queued;
            $locked->save();
            $this->audit->append($locked, $actor, 'queued', [
                'queue' => (string) config('security_devices.command_queue', 'monitoring-commands'),
                'status' => CommandStatus::Queued->value,
            ]);
            DispatchDeviceCommand::dispatch($locked->id, $actor->id)->afterCommit();

            return ['request' => $locked, 'failure' => null];
        });
        if ($result['failure'] instanceof CommandDispatchPreconditionException) {
            throw $result['failure']->asValidationException();
        }

        return $result['request'];
    }
}
