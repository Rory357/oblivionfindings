<?php

namespace App\Notifications\ControlRoom;

use App\Models\User;
use App\Support\ControlRoom\BroadcastCommandInput;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * A staff broadcast delivered in-app to one recipient. The stored data is only a
 * reference (schema_version, kind, intent_id, communication_id); the message text is
 * read from the original Control Room communication by BroadcastRecipientReadService,
 * which also decides who may see it. Created only by BroadcastRecipientAvailabilityService.
 */
final class BroadcastRecipientNotice extends Notification
{
    public function __construct(
        private readonly string $notificationId,
        private readonly string $intentId,
        private readonly int $communicationId,
        private readonly int $recipientId,
    ) {
        if (! BroadcastCommandInput::canonicalUuid($notificationId) || ! BroadcastCommandInput::canonicalUuid($intentId)
            || $communicationId < 1 || $communicationId > 9007199254740991
            || $recipientId < 1 || $recipientId > 9007199254740991) {
            throw new LogicException('Invalid broadcast recipient reference.');
        }
        $this->id = $notificationId;
    }

    public function via(object $notifiable): array
    {
        $this->assertOriginalReceiver($notifiable);
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $this->assertOriginalReceiver($notifiable);
        return ['schema_version' => 1, 'kind' => 'broadcast_recipient',
            'intent_id' => $this->intentId, 'communication_id' => $this->communicationId];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    private function assertOriginalReceiver(object $notifiable): void
    {
        if (! $notifiable instanceof User || (int) $notifiable->id !== $this->recipientId
            || $this->id !== $this->notificationId) {
            throw new LogicException('Broadcast reference cannot transfer to another receiver or notification identity.');
        }
    }
}
