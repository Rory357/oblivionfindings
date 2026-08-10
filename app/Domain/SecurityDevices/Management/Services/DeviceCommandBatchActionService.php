<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandBatch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class DeviceCommandBatchActionService
{
    public function __construct(
        private readonly DeviceCommandBatchAccessService $access,
        private readonly DeviceCommandApprovalService $approvals,
        private readonly DeviceCommandQueueService $queue,
    ) {}

    /** @return array{processed: int, skipped: int} */
    public function decide(
        DeviceCommandBatch $batch,
        User $actor,
        CommandApprovalDecision $decision,
        string $comment,
    ): array {
        $batch = $this->access->assertCanView($actor, $batch);
        $batch->loadMissing('targets.command.device');
        $eligible = $batch->targets
            ->pluck('command')
            ->filter(fn ($command): bool => $command?->status === CommandStatus::AwaitingApproval)
            ->values();
        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'decision' => 'No visible child command is currently awaiting an approval decision.',
            ]);
        }

        $processed = 0;
        foreach ($eligible as $command) {
            try {
                $this->approvals->decide($command, $actor, new CommandDecisionInput($decision, $comment));
                $processed++;
            } catch (ValidationException) {
                // The child lifecycle records expiry or policy drift where applicable.
                // Other children remain independently reviewable.
            }
        }

        return ['processed' => $processed, 'skipped' => $eligible->count() - $processed];
    }

    /** @return array{processed: int, skipped: int} */
    public function queue(DeviceCommandBatch $batch, User $actor): array
    {
        $batch = $this->access->assertCanView($actor, $batch);
        $batch->loadMissing('targets.command.device');
        $eligible = $batch->targets
            ->pluck('command')
            ->filter(fn ($command): bool => $command?->status === CommandStatus::Ready)
            ->values();
        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'command' => 'No visible child command is currently ready for governed dispatch.',
            ]);
        }

        $processed = 0;
        foreach ($eligible as $command) {
            try {
                $this->queue->queue($command, $actor);
                $processed++;
            } catch (ValidationException) {
                // Dispatch-time revalidation is per target; one blocked child must
                // never turn the whole batch into blanket success or failure.
            }
        }

        return ['processed' => $processed, 'skipped' => $eligible->count() - $processed];
    }
}
