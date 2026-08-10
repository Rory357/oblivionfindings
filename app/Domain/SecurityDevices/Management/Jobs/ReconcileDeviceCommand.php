<?php

namespace App\Domain\SecurityDevices\Management\Jobs;

use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ReconcileDeviceCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public int $timeout = 60;

    public function __construct(
        public readonly int $commandId,
        public readonly ?int $actorUserId = null,
    ) {
        $this->onQueue((string) config('security_devices.command_queue', 'monitoring-commands'));
    }

    public function handle(DeviceCommandReconciliationService $reconciliation): void
    {
        $command = DeviceCommandRequest::query()->findOrFail($this->commandId);
        if (! in_array($command->status, [CommandStatus::Reconciling, CommandStatus::Uncertain], true)) {
            return;
        }
        $actor = $this->actorUserId ? User::query()->find($this->actorUserId) : null;
        $reconciliation->reconcile($command, $actor);
    }
}
