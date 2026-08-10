<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Jobs\DeliverComplianceReminderJob;
use App\Domain\Hr\Models\HrComplianceReminderDelivery;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Notifications\ComplianceExpiryNotification;
use App\Models\User;
use App\Notifications\ComplianceReminderNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HrComplianceReminderDeliveryService
{
    public const KIND_EXPIRY = 'expiry';

    public const KIND_MANUAL = 'manual';

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Dispatcher $notifications,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    public function stageExpiry(
        HrStaffComplianceStatus $status,
        User $recipient,
        array $payload,
        int $reminderDays,
    ): HrComplianceReminderDelivery {
        return $this->stage(
            keyParts: [
                'compliance-expiry-v1',
                $status->id,
                $status->expires_at?->toDateString(),
                $reminderDays,
            ],
            recipient: $recipient,
            kind: self::KIND_EXPIRY,
            sourceType: 'compliance_status',
            sourceId: (int) $status->id,
            payload: $payload,
        );
    }

    public function stageManual(
        User $recipient,
        string $sourceType,
        ?int $sourceId,
        string $requirementName,
        ?string $expiryDate,
        User $initiatedBy,
    ): HrComplianceReminderDelivery {
        return $this->stage(
            keyParts: [
                'compliance-manual-v1',
                $sourceType,
                $sourceId ?? 0,
                $recipient->id,
                now()->toDateString(),
            ],
            recipient: $recipient,
            kind: self::KIND_MANUAL,
            sourceType: $sourceType,
            sourceId: $sourceId,
            payload: [
                'requirement_name' => $requirementName,
                'expiry_date' => $expiryDate,
                'sender_name' => $initiatedBy->name,
            ],
            initiatedBy: $initiatedBy,
        );
    }

    public function queue(HrComplianceReminderDelivery $delivery): void
    {
        if (! in_array($delivery->status, [
            HrComplianceReminderDelivery::STATUS_PENDING,
            HrComplianceReminderDelivery::STATUS_FAILED,
        ], true) || $delivery->attempts >= self::MAX_ATTEMPTS) {
            return;
        }

        try {
            DeliverComplianceReminderJob::dispatch((int) $delivery->id)->afterCommit();
        } catch (Throwable $exception) {
            // The committed outbox row remains pending and the minute recovery
            // sweep will enqueue it when the queue accepts work again.
            Log::error('Compliance reminder queue acceptance failed', [
                'delivery_id' => $delivery->id,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function deliver(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = HrComplianceReminderDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();
            if (! $delivery
                || ! in_array($delivery->status, [
                    HrComplianceReminderDelivery::STATUS_PENDING,
                    HrComplianceReminderDelivery::STATUS_FAILED,
                ], true)
                || $delivery->attempts >= self::MAX_ATTEMPTS
            ) {
                return;
            }

            $recipient = User::query()->find($delivery->recipient_user_id);
            if (! $recipient || ! $this->currentStaff->isCurrent($recipient)) {
                $delivery->forceFill([
                    'status' => HrComplianceReminderDelivery::STATUS_CANCELLED,
                    'last_error' => 'Recipient is no longer current approved staff.',
                ])->save();

                return;
            }

            $payload = is_array($delivery->payload) ? $delivery->payload : [];
            $notification = match ($delivery->kind) {
                self::KIND_EXPIRY => new ComplianceExpiryNotification($recipient, $payload),
                self::KIND_MANUAL => new ComplianceReminderNotification(
                    (string) ($payload['requirement_name'] ?? 'outstanding compliance requirements'),
                    isset($payload['expiry_date']) ? (string) $payload['expiry_date'] : null,
                    isset($payload['sender_name']) ? (string) $payload['sender_name'] : null,
                ),
                default => throw new \DomainException('Unknown compliance reminder delivery kind.'),
            };

            // The delivery job is the queue boundary. sendNow keeps the
            // database notification and terminal outbox state in one database
            // transaction even though the notification classes remain usable
            // as normal queued notifications elsewhere.
            $this->notifications->sendNow($recipient, $notification);
            $delivery->forceFill([
                'status' => HrComplianceReminderDelivery::STATUS_SENT,
                'attempts' => (int) $delivery->attempts + 1,
                'last_error' => null,
                'sent_at' => now(),
            ])->save();
        }, 3);
    }

    public function recordFailure(int $deliveryId, Throwable $exception): void
    {
        DB::transaction(function () use ($deliveryId, $exception): void {
            $delivery = HrComplianceReminderDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();
            if (! $delivery || in_array($delivery->status, [
                HrComplianceReminderDelivery::STATUS_SENT,
                HrComplianceReminderDelivery::STATUS_CANCELLED,
            ], true)) {
                return;
            }

            $delivery->forceFill([
                'status' => HrComplianceReminderDelivery::STATUS_FAILED,
                'attempts' => min(self::MAX_ATTEMPTS, (int) $delivery->attempts + 1),
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
        }, 3);
    }

    public function recoverPending(int $limit = 100): int
    {
        $deliveries = HrComplianceReminderDelivery::query()
            ->whereIn('status', [
                HrComplianceReminderDelivery::STATUS_PENDING,
                HrComplianceReminderDelivery::STATUS_FAILED,
            ])
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('updated_at', '<=', now()->subSeconds(30))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($deliveries as $delivery) {
            $retryDelaySeconds = min(900, 30 * (2 ** max(0, (int) $delivery->attempts)));
            if ($delivery->updated_at?->gt(now()->subSeconds($retryDelaySeconds))) {
                continue;
            }
            $this->queue($delivery);
        }

        return $deliveries->count();
    }

    private function stage(
        array $keyParts,
        User $recipient,
        string $kind,
        string $sourceType,
        ?int $sourceId,
        array $payload,
        ?User $initiatedBy = null,
    ): HrComplianceReminderDelivery {
        $deliveryKey = hash('sha256', implode('|', array_map(
            fn ($part) => (string) ($part ?? ''),
            $keyParts,
        )));

        return HrComplianceReminderDelivery::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey],
            [
                'recipient_user_id' => $recipient->id,
                'initiated_by_user_id' => $initiatedBy?->id,
                'kind' => $kind,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'payload' => $payload,
                'status' => HrComplianceReminderDelivery::STATUS_PENDING,
            ],
        );
    }
}
