<?php

namespace App\Jobs\Notifications;

use App\Models\ControlRoom\Communication;
use App\Services\ControlRoom\ControlRoomNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverControlRoomAlertNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 3600;

    public function __construct(public int $communicationId) {}

    public function uniqueId(): string
    {
        return (string) $this->communicationId;
    }

    public function handle(ControlRoomNotificationService $notifications): void
    {
        $replacementIds = [];

        try {
            $replacementIds = DB::transaction(function () use ($notifications): array {
                $communication = Communication::query()
                    ->whereKey($this->communicationId)
                    ->lockForUpdate()
                    ->first();

                if ($communication === null
                    || $communication->superseded_at !== null
                    || (int) $communication->retry_count >= $this->tries
                    || ! in_array($communication->status, ['pending', 'failed'], true)
                ) {
                    return [];
                }

                return $notifications->deliverStagedNotification($communication);
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($exception): void {
                $communication = Communication::query()
                    ->whereKey($this->communicationId)
                    ->lockForUpdate()
                    ->first();

                if ($communication === null
                    || $communication->superseded_at !== null
                    || (int) $communication->retry_count >= $this->tries
                    || ! in_array($communication->status, ['pending', 'failed'], true)
                ) {
                    return;
                }

                $communication->forceFill([
                    'status' => 'failed',
                    'status_detail' => mb_substr($exception->getMessage(), 0, 1000),
                    'retry_count' => (int) $communication->retry_count + 1,
                ])->save();
            });

            throw $exception;
        }

        foreach ($replacementIds as $replacementId) {
            try {
                self::dispatch($replacementId);
            } catch (Throwable $exception) {
                // The current-generation row is already durable and remains
                // pending for the scheduled recovery sweep.
                Log::error('Control Room replacement notification dispatch failed', [
                    'superseded_communication_id' => $this->communicationId,
                    'replacement_communication_id' => $replacementId,
                    'exception' => $exception::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
