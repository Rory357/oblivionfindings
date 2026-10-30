<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrKudosReply;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrRecognitionReplies;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sent to the author of a kudos or feed post when someone replies to it.
 * Database-only (the header bell) — a social wall shouldn't email on every
 * reply. Deep-links back to the community feed.
 */
class FeedReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // A declared default also keeps older serialized notifications readable.
    private ?int $kudosReplyId = null;

    /**
     * @param  string  $context  what was replied to, e.g. "your kudos" / "your post"
     */
    public function __construct(
        private string $replierName,
        private string $context,
        private string $snippet,
        private ?int $teamKudosId = null,
        ?int $kudosReplyId = null,
    ) {
        $this->kudosReplyId = $kudosReplyId;
        if ($teamKudosId !== null || $kudosReplyId !== null) {
            $this->afterCommit = true;
        }
    }

    public function via(object $notifiable): array
    {
        if (! $this->shouldSend($notifiable, 'database')) {
            return [];
        }

        return ['database'];
    }

    /** Channels selected before commit do not grant delivery after access changes. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && app(HubNotificationPreferences::class)->allows($notifiable, 'replies') && $this->currentTeamParticipant($notifiable);
    }

    /** Withdrawal can commit after the channel guard but before the bell row is saved. */
    public function afterSending(object $notifiable, string $channel, mixed $response): void
    {
        if ($channel === 'database' && $this->kudosReplyId !== null && ! $this->shouldSend($notifiable, $channel)) {
            DB::table('notifications')->where('id', $this->id)->where('type', self::class)->delete();
        }
    }

    private function currentTeamParticipant(object $notifiable): bool
    {
        if ($this->kudosReplyId !== null) {
            $reply = HrKudosReply::visible()->find($this->kudosReplyId);
            $kudos = $reply ? HrKudos::find($reply->kudos_id) : null;
            $viewer = User::find($notifiable->id);
            $replier = $reply ? User::find($reply->user_id) : null;
            $feed = app(FeedService::class);

            return $kudos && $viewer && $replier && $viewer->canDo('hr.recognition.view') && $feed->canViewKudos($kudos, $viewer)
                && ($this->teamKudosId === null || (int) $this->teamKudosId === (int) $kudos->id)
                && (new UserSiteAccessService)->applyStaffScope(User::query()->whereKey($viewer->id), $viewer)->exists()
                && app(HrRecognitionReplies::class)->canParticipate($kudos, $replier)
                && ((int) $kudos->from_user_id === (int) $viewer->id
                    || ($kudos->isTeamRecognition() ? $kudos->recipients()->whereKey($viewer->id)->exists() : (int) $kudos->to_user_id === (int) $viewer->id)
                    || ($reply->parent_id && HrKudosReply::visible()->where('kudos_id', $kudos->id)->whereKey($reply->parent_id)->where('user_id', $viewer->id)->exists()));
        }
        if ($this->teamKudosId === null) {
            return true;
        }
        $kudos = HrKudos::find($this->teamKudosId);
        $viewer = User::find($notifiable->id);

        return $kudos && $kudos->isTeamRecognition() && $viewer && app(FeedService::class)->canViewKudos($kudos, $viewer)
            && ((int) $kudos->from_user_id === (int) $viewer->id || $kudos->recipients()->whereKey($viewer->id)->exists());
    }

    public function toArray(object $notifiable): array
    {
        if ($this->kudosReplyId !== null) {
            $reply = $this->currentTeamParticipant($notifiable) ? HrKudosReply::visible()->find($this->kudosReplyId) : null;

            return [
                'type' => 'feed_reply', 'kudos_reply_id' => $this->kudosReplyId,
                'title' => $reply ? "{$reply->user?->name} replied to {$this->context}" : 'Reply unavailable',
                'message' => $reply ? Str::limit((string) $reply->body, 140) : '', 'url' => '/hr/feed',
            ];
        }

        return [
            'type' => 'feed_reply',
            'title' => "{$this->replierName} replied to {$this->context}",
            'message' => Str::limit($this->snippet, 140),
            'url' => '/hr/feed',
        ];
    }
}
