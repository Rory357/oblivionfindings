<?php

namespace App\Domain\SecurityDevices\Management\Jobs;

use App\Domain\Monitoring\Contracts\CommandDispatchPort;
use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandReconciliationDelay;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchDeviceCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $commandId,
        public readonly int $triggeredByUserId,
    ) {
        $this->onQueue((string) config('security_devices.command_queue', 'monitoring-commands'));
    }

    public function handle(CommandDispatchPort $commands, CommandReconciliationDelay $delay): void
    {
        $command = DeviceCommandRequest::query()->findOrFail($this->commandId);
        $actor = User::query()->findOrFail($this->triggeredByUserId);
        $attempt = $commands->dispatch($command, $actor);

        if ($attempt->status === CommandAttemptStatus::Succeeded) {
            $job = ReconcileDeviceCommand::dispatch($command->id, $actor->id)
                ->onQueue((string) config('security_devices.command_queue', 'monitoring-commands'));
            $seconds = $delay->seconds($command->fresh());
            if ($seconds > 0) {
                $job->delay(now()->addSeconds($seconds));
            }
        }
    }
}
