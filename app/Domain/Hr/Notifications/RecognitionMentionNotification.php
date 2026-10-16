<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\CompanyHub\Services\HubRecognition;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrRecognitionMentions;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class RecognitionMentionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private int $kudosId, private ?int $replyId, private int $actorId)
    {
        $this->afterCommit = true;
    }

    private function sourceKey(): string
    {
        return $this->replyId ? 'reply:'.$this->replyId : 'post';
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User || ! app(HrRecognitionMentions::class)->ready()) {
            return false;
        }
        $viewer = User::find($notifiable->id);
        $actor = User::find($this->actorId);
        $kudos = HrKudos::find($this->kudosId);
        $reply = $this->replyId ? HrKudosReply::visible()->find($this->replyId) : null;

        return $viewer && $actor && $kudos && (! $this->replyId || ($reply && ! $reply->hidden_at && (int) $reply->kudos_id === $this->kudosId))
            && $viewer->canDo('hr.recognition.view') && $viewer->canDo('company_hub.view')
            && app(HubNotificationPreferences::class)->allows($viewer, 'replies')
            && app(FeedService::class)->canViewKudos($kudos, $viewer)
            && in_array((int) $viewer->id, array_column(app(HrRecognitionMentions::class)->options($actor, $kudos, [(int) $viewer->id]), 'id'), true)
            && DB::table('hr_recognition_mentions')->where('kudos_id', $this->kudosId)->where('source_key', $this->sourceKey())->where('user_id', $viewer->id)->exists();
    }

    public function via(object $notifiable): array
    {
        return $this->shouldSend($notifiable, 'database') ? ['database'] : [];
    }

    public function toArray(object $notifiable): array
    {
        $available = $this->shouldSend($notifiable, 'database');

        return ['type' => 'recognition_mention', 'kudos_id' => $this->kudosId, 'source_key' => $this->sourceKey(),
            'title' => $available ? 'A colleague mentioned you in a shout-out' : 'Mention unavailable', 'message' => '',
            'url' => $available ? HubRecognition::readerUrl($this->kudosId) : '/company-hub'];
    }

    public function afterSending(object $notifiable, string $channel, mixed $response): void
    {
        if ($channel === 'database' && ! $this->shouldSend($notifiable, $channel)) {
            DB::table('notifications')->where('id', $this->id)->where('type', self::class)->delete();
        }
    }
}
