<?php

namespace App\Domain\CompanyHub\Notifications;

use App\Domain\CompanyHub\Services\HubConversations;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class HubConversationReply extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $kind, private readonly int $commentId, private readonly int $recipientId)
    {
        $this->afterCommit = true;
        $this->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'company-hub:reply:'.$kind.':'.$commentId.':'.$recipientId)->toString();
    }

    public function via(object $notifiable): array
    {
        return $this->shouldSend($notifiable, 'database') ? ['database'] : [];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $channel === 'database' && $this->context($notifiable) !== null && ! DB::table('notifications')->where('id', $this->id)->exists();
    }

    public function toArray(object $notifiable): array
    {
        $context = $this->context($notifiable);

        return ['type' => 'company_hub_reply', 'title' => $context ? 'A new reply in your conversation' : 'Conversation unavailable',
            'message' => $context ? 'Open Company Hub to read the current conversation.' : '',
            'url' => $context['url'] ?? '/company-hub', 'action_url' => $context['url'] ?? '/company-hub'];
    }

    public function afterSending(object $notifiable, string $channel, mixed $response): void
    {
        if ($channel === 'database' && $this->context($notifiable) === null) {
            DB::table('notifications')->where('id', $this->id)->where('notifiable_id', $this->recipientId)->where('type', self::class)->delete();
        }
    }

    private function context(object $notifiable): ?array
    {
        return $notifiable instanceof User && (int) $notifiable->id === $this->recipientId ? app(HubConversations::class)->context($notifiable, $this->kind, $this->commentId) : null;
    }
}
