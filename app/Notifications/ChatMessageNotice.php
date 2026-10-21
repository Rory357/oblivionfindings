<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

final class ChatMessageNotice extends Notification
{
    public function __construct(string $id, private readonly string $eventKey)
    {
        $this->id = $id;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $title = match ($this->eventKey) {
            'chat.mentioned' => 'You were mentioned in Chat',
            'chat.family.reply' => 'New reply from the care team',
            'chat.family.assigned' => 'Family work assigned to you',
            'chat.call.missed' => 'Missed Chat call',
            default => 'New Chat message',
        };

        $body = match ($this->eventKey) {
            'chat.family.assigned' => 'Open Chat to review the handover.',
            'chat.call.missed' => 'Open Chat to review your missed calls.',
            default => 'Open Chat to view the message.',
        };

        // Retained previews contain no care details, names or record identifiers.
        return ['kind' => 'chat', 'event_key' => $this->eventKey, 'title' => $title,
            'body' => $body, 'message' => $body,
            'url' => '/chat/notifications/'.$this->id, 'action_url' => '/chat/notifications/'.$this->id];
    }
}
