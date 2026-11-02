<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Notifications\ChatMessageNotice;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChatMessageDelivery
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatSettings $settings) {}

    public function enqueue(OpsMessage $message, OpsConversation $conversation): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Chat notification work must be committed with its message.');
        }
        $recipients = User::whereIn('id', $conversation->members()->pluck('user_id'))->get();
        if ($conversation->conversation_type === 'family' && $conversation->care_team_cover && $conversation->client) {
            // SQL shortlists permission holders and assigned workers; canManage() stays the authority.
            $family = app(ClientFamilyCommunicationAccess::class);
            $recipients = $recipients->concat($family->candidateStaffQuery($conversation->client, true)->get()
                ->filter(fn ($user) => $family->canManage($user, $conversation->client)));
        }
        $mentioned = DB::table('chat_message_mentions')->where('message_id', $message->id)->pluck('user_id');
        $notified = [];
        foreach ($recipients->unique('id') as $recipient) {
            if ((int) $recipient->id === (int) $message->sender_id || ! $this->visible($message, $conversation, $recipient)) {
                continue;
            }
            $key = $mentioned->contains($recipient->id) ? 'chat.mentioned'
                : ($message->sender_type !== 'family' && $conversation->client && $recipient->canAccessClientPortal($conversation->client) ? 'chat.family.reply' : 'chat.message.received');
            $inserted = DB::table('chat_message_deliveries')->insertOrIgnore([
                'notification_id' => (string) Str::uuid(), 'message_id' => $message->id, 'user_id' => $recipient->id,
                'event_key' => $key, 'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted) {
                $id = DB::table('chat_message_deliveries')->where('message_id', $message->id)->where('user_id', $recipient->id)->value('id');
                app(ChatBrowserPushDelivery::class)->enqueue((int) $id, $recipient, $key);
            }
            $notified[] = (int) $recipient->id;
        }
        // Refresh each recipient's cached unread badge once the message is visible (covers care-team staff too).
        DB::afterCommit(fn () => collect($notified)->each(fn (int $id) => OpsMessageVisibilityService::forgetUnreadCount($id)));
        // Then each recipient's open tabs get their new count (queued after the same commit).
        app(ChatRealtime::class)->unreadChanged($notified);
    }

    public function recover(?int $messageId = null): int
    {
        if (! config('chat.shared_experience')) {
            return 0;
        }
        $ids = DB::table('chat_message_deliveries')->where('status', 'pending')->where('available_at', '<=', now())
            ->when($messageId, fn ($query) => $query->where('message_id', $messageId))->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $count += $this->deliver((int) $id);
            } catch (\Throwable $error) {
                // Do not place exception details, message text or recipient names in retained diagnostics.
                DB::table('chat_message_deliveries')->where('id', $id)->where('status', 'pending')->update([
                    // MySQL applies SET left to right, so status/backoff read the incremented count.
                    // Stop after ten attempts instead of retrying and reporting the same row forever.
                    'attempts' => DB::raw('attempts + 1'),
                    'status' => DB::raw("CASE WHEN attempts >= 10 THEN 'failed' ELSE 'pending' END"),
                    'last_error' => DB::raw("CASE WHEN attempts >= 10 THEN 'Delivery failed; retries exhausted' ELSE 'Delivery failed; retry pending' END"),
                    'available_at' => now()->addMinutes(min(60, 2 ** min(6, (int) DB::table('chat_message_deliveries')->where('id', $id)->value('attempts')))), 'updated_at' => now(),
                ]);
                report($error);
            }
        }

        return $count;
    }

    private function deliver(int $id): int
    {
        $candidate = DB::table('chat_message_deliveries')->find($id);
        $message = $candidate ? OpsMessage::withTrashed()->find($candidate->message_id) : null;
        if (! $message) {
            return 0;
        }

        return DB::transaction(function () use ($id, $message): int {
            // Use the same conversation-first lock order as message and membership commands.
            // Shared: membership and message writers still exclude delivery; deliveries no longer queue behind each other.
            $conversation = OpsConversation::whereKey($message->conversation_id)->sharedLock()->first();
            $row = DB::table('chat_message_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || $row->available_at > now()->toDateTimeString()) {
                return 0;
            }
            $message = OpsMessage::find($row->message_id);
            $user = User::find($row->user_id);
            if (! $conversation || ! $message || ! $user || ! $this->shouldNotify($message, $conversation, $user)) {
                $this->finish($id, 'suppressed');

                return 0;
            }
            if (! $this->inAppEnabled($user, $row->event_key)) {
                $this->finish($id, 'suppressed');

                return 0;
            }
            // A later message in the same conversation will carry the notice, so a backlog (for example
            // while someone is off duty) becomes one notice per conversation instead of one per message.
            if ($this->supersededBy($row, $conversation)) {
                $this->finish($id, 'superseded');

                return 0;
            }
            if ($deferredUntil = app(ChatAvailability::class)->deferUntil($user)) {
                DB::table('chat_message_deliveries')->where('id', $id)->update([
                    'available_at' => $deferredUntil, 'updated_at' => now(),
                ]);

                return 0;
            }
            $notice = $user->notifications()->whereKey($row->notification_id)->first();
            if (! $notice) {
                $user->notifyNow(new ChatMessageNotice($row->notification_id, $row->event_key));
            }
            // A listener can cancel delivery without throwing; retain the durable retry.
            if (! $user->notifications()->whereKey($row->notification_id)->exists()) {
                throw new \RuntimeException('Chat message notice was not persisted.');
            }
            $this->finish($id, 'delivered');
            // The bell keeps one unread entry per conversation: the newest. An unread mention stays
            // until a newer mention replaces it or the conversation is read.
            $this->markConversationNoticesRead($user, $conversation, null, $row->notification_id, keepMentions: $row->event_key !== 'chat.mentioned');
            app(ChatRealtime::class)->noticeChanged([(int) $user->id], 'message');

            return 1;
        });
    }

    /**
     * Marks a person's chat notices for one conversation as read, up to a message when given.
     * Used when they read the conversation and when a newer notice replaces older ones.
     */
    public function markConversationNoticesRead(User $user, OpsConversation $conversation, ?int $uptoMessageId = null, ?string $exceptNotificationId = null, bool $keepMentions = false): int
    {
        $notices = DB::table('chat_message_deliveries')
            ->join('ops_messages', 'ops_messages.id', '=', 'chat_message_deliveries.message_id')
            ->where('chat_message_deliveries.user_id', $user->id)
            ->where('chat_message_deliveries.status', 'delivered')
            ->where('ops_messages.conversation_id', $conversation->id)
            ->when($uptoMessageId, fn ($query) => $query->where('chat_message_deliveries.message_id', '<=', $uptoMessageId))
            ->when($exceptNotificationId, fn ($query) => $query->where('chat_message_deliveries.notification_id', '!=', $exceptNotificationId))
            ->when($keepMentions, fn ($query) => $query->where('chat_message_deliveries.event_key', '!=', 'chat.mentioned'))
            ->select('chat_message_deliveries.notification_id');

        return $user->unreadNotifications()->where('type', ChatMessageNotice::class)->whereIn('id', $notices)->update(['read_at' => now()]);
    }

    /** A plain message gives way to any newer notice in its conversation; a mention only to a newer mention. */
    private function supersededBy(object $row, OpsConversation $conversation): bool
    {
        return DB::table('chat_message_deliveries')
            ->join('ops_messages', 'ops_messages.id', '=', 'chat_message_deliveries.message_id')
            ->where('chat_message_deliveries.user_id', $row->user_id)
            ->where('ops_messages.conversation_id', $conversation->id)
            ->where('chat_message_deliveries.message_id', '>', $row->message_id)
            ->whereIn('chat_message_deliveries.status', ['pending', 'delivered'])
            ->when($row->event_key === 'chat.mentioned', fn ($query) => $query->where('chat_message_deliveries.event_key', 'chat.mentioned'))
            ->exists();
    }

    private function finish(int $id, string $status): void
    {
        DB::table('chat_message_deliveries')->where('id', $id)->update([
            'status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null,
            'last_error' => null, 'updated_at' => now(),
        ]);
    }

    public function visible(OpsMessage $message, OpsConversation $conversation, User $user): bool
    {
        $settings = $this->settings->forConversation($conversation);

        return config('chat.shared_experience') && $settings['enabled']
            && ($conversation->conversation_type !== 'family' || $settings['family_enabled'])
            && ! $message->trashed() && $this->access->allows($user, $conversation)
            && $this->access->messages($conversation, $user)->whereKey($message->id)->exists();
    }

    public function inAppEnabled(User $user, string $key): bool
    {
        $preference = app(ChatNotificationPreferences::class)->forUser($user, $key);

        return $preference['enabled'] && $preference['inapp'];
    }

    public function shouldNotify(OpsMessage $message, OpsConversation $conversation, User $user): bool
    {
        if (! $this->visible($message, $conversation, $user)) {
            return false;
        }
        $member = $conversation->participants()->where('user_id', $user->id)->first();

        return ! $member?->is_muted && (int) $member?->last_read_message_id < $message->id
            && ($member?->last_read_message_id || ! $member?->last_read_at || $member->last_read_at->lt($message->created_at));
    }

    public function filterNotifications(MorphMany $query, User $viewer): MorphMany
    {
        $allowed = [];
        $messageNotices = null;
        if (config('chat.shared_experience')) {
            // Only chat notices the outer query can still return (e.g. unread for badges) are evaluated.
            $reachable = (clone $query)->getQuery()->reorder()->where('type', ChatMessageNotice::class)->select('id');
            $allowed = array_merge(app(ChatFamilyWorkDelivery::class)->visibleNoticeIds($viewer, $reachable), app(ChatCallNoticeDelivery::class)->visibleNoticeIds($viewer, $reachable));
            // visible() is conversation-level authority plus the message's own history boundary.
            // Decide authority once per conversation, then apply the boundary for every row in SQL.
            $conversations = OpsConversation::with(['client', 'participants'])->whereIn('id', DB::table('chat_message_deliveries')
                ->join('ops_messages', 'ops_messages.id', '=', 'chat_message_deliveries.message_id')
                ->where('chat_message_deliveries.user_id', $viewer->id)->where('chat_message_deliveries.status', 'delivered')
                ->whereIn('chat_message_deliveries.notification_id', $reachable)
                ->select('ops_messages.conversation_id'))->get()
                ->filter(function (OpsConversation $conversation): bool {
                    $settings = $this->settings->forConversation($conversation);

                    return $settings['enabled'] && ($conversation->conversation_type !== 'family' || $settings['family_enabled']);
                });
            // messagesAcross() applies allows(), canonical provenance, history grants and excludes removed messages.
            $messageNotices = $this->access->messagesAcross($viewer, $conversations)
                ->join('chat_message_deliveries as chat_notice', 'chat_notice.message_id', '=', 'ops_messages.id')
                ->where('chat_notice.user_id', $viewer->id)->where('chat_notice.status', 'delivered')
                ->reorder()->select('chat_notice.notification_id');
        }

        return $query->where(fn ($notifications) => $notifications->where('type', '!=', ChatMessageNotice::class)
            ->orWhere(fn ($chat) => $chat->where('type', ChatMessageNotice::class)->where(fn ($ids) => $ids->whereIn('id', $allowed)
                ->when($messageNotices, fn ($messages) => $messages->orWhereIn('id', $messageNotices)))));
    }
}
