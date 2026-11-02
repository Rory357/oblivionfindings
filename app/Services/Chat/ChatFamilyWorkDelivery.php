<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\User;
use App\Notifications\ChatMessageNotice;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChatFamilyWorkDelivery
{
    public const EVENT = 'chat.family.assigned';

    public function enqueue(int $eventId, OpsConversation $conversation, int $actorId, ?int $previousOwner): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Family assignment notification work must commit with its ownership event.');
        }
        $owner = (int) $conversation->assigned_to;
        if (! $owner || $owner === $actorId || $owner === $previousOwner || $conversation->queue_status === 'resolved') {
            return false;
        }

        return (bool) DB::table('chat_family_work_deliveries')->insertOrIgnore([
            'notification_id' => (string) Str::uuid(), 'event_id' => $eventId, 'user_id' => $owner,
            'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function visible(object $event, OpsConversation $conversation, User $user): bool
    {
        $policy = app(ChatSettings::class)->forConversation($conversation);
        if (! config('chat.shared_experience') || ! $policy['enabled'] || ! $policy['family_enabled']
            || $conversation->conversation_type !== 'family' || ! $conversation->client || $conversation->is_archived || $conversation->queue_status === 'resolved'
            || (int) $conversation->assigned_to !== (int) $user->id || (int) $event->owner_id !== (int) $user->id
            || (int) $event->conversation_id !== (int) $conversation->id || ! in_array($event->action, ['assign', 'handoff'], true)
            || ! app(OpsMessageVisibilityService::class)->isCurrentStaff($user)
            || $user->canAccessClientPortal($conversation->client) || ! app(ChatAccess::class)->allows($user, $conversation, true)) {
            return false;
        }
        $member = $conversation->participants()->where('user_id', $user->id)->first();
        if ($member?->history_after_id !== null && ($member->family_work_history_after_id === null || $event->id <= $member->family_work_history_after_id)) {
            return false;
        }

        // Returning ownership to the same worker must never revive an earlier assignment notice.
        // A no-op ownership confirmation does not supersede the original assignment.
        return ! DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)->where('id', '>', $event->id)
            ->where(fn ($changes) => $changes
                ->where(fn ($ownership) => $ownership->whereIn('action', ['assign', 'handoff', 'claim'])
                    ->where(fn ($owner) => $owner->whereNull('owner_id')->orWhere('owner_id', '!=', $user->id)))
                ->orWhere(fn ($resolved) => $resolved->where('action', 'status')->where('queue_status', 'resolved')))->exists();
    }

    public function recover(?int $eventId = null): int
    {
        if (! config('chat.shared_experience')) {
            return 0;
        }
        $ids = DB::table('chat_family_work_deliveries')->where('status', 'pending')->where('available_at', '<=', now())
            ->when($eventId, fn ($query) => $query->where('event_id', $eventId))->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $count += $this->deliver((int) $id);
            } catch (\Throwable $error) {
                DB::table('chat_family_work_deliveries')->where('id', $id)->where('status', 'pending')->update([
                    // MySQL applies SET left to right, so status/backoff read the incremented count.
                    // Stop after ten attempts instead of retrying and reporting the same row forever.
                    'attempts' => DB::raw('attempts + 1'),
                    'status' => DB::raw("CASE WHEN attempts >= 10 THEN 'failed' ELSE 'pending' END"),
                    'last_error' => DB::raw("CASE WHEN attempts >= 10 THEN 'Delivery failed; retries exhausted' ELSE 'Delivery failed; retry pending' END"),
                    'available_at' => now()->addMinutes(min(60, 2 ** min(6, (int) DB::table('chat_family_work_deliveries')->where('id', $id)->value('attempts')))), 'updated_at' => now(),
                ]);
                report($error);
            }
        }

        return $count;
    }

    private function deliver(int $id): int
    {
        $candidate = DB::table('chat_family_work_deliveries')->find($id);
        $event = $candidate ? DB::table('chat_family_work_events')->find($candidate->event_id) : null;
        if (! $event) {
            return 0;
        }

        return DB::transaction(function () use ($id, $event): int {
            // Shared: the conversation is only read here; family work writers still exclude delivery.
            $conversation = OpsConversation::whereKey($event->conversation_id)->sharedLock()->first();
            $row = DB::table('chat_family_work_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || $row->available_at > now()->toDateTimeString()) {
                return 0;
            }
            $user = User::find($row->user_id);
            $member = $conversation?->participants()->where('user_id', $row->user_id)->first();
            $preference = $user ? app(ChatNotificationPreferences::class)->forUser($user, self::EVENT) : null;
            if (! $conversation || ! $user || ! $this->visible($event, $conversation, $user)
                || $member?->is_muted || ! $preference['enabled'] || ! $preference['inapp']) {
                $this->finish($id, 'suppressed');

                return 0;
            }
            if ($deferredUntil = app(ChatAvailability::class)->deferUntil($user)) {
                DB::table('chat_family_work_deliveries')->where('id', $id)->update([
                    'available_at' => $deferredUntil, 'updated_at' => now(),
                ]);

                return 0;
            }
            if (! $user->notifications()->whereKey($row->notification_id)->exists()) {
                $user->notifyNow(new ChatMessageNotice($row->notification_id, self::EVENT));
            }
            // A listener may cancel a notification without throwing. Keep it recoverable.
            if (! $user->notifications()->whereKey($row->notification_id)->exists()) {
                throw new \RuntimeException('Family assignment notice was not persisted.');
            }
            $this->finish($id, 'delivered');
            app(ChatRealtime::class)->noticeChanged([(int) $user->id], 'family_work');

            return 1;
        });
    }

    private function finish(int $id, string $status): void
    {
        DB::table('chat_family_work_deliveries')->where('id', $id)->update([
            'status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null,
            'last_error' => null, 'updated_at' => now(),
        ]);
    }

    public function conversationForNotice(User $user, string $notification): ?OpsConversation
    {
        if (! config('chat.shared_experience')) {
            return null;
        }
        $row = DB::table('chat_family_work_deliveries')->where('notification_id', $notification)->where('user_id', $user->id)->where('status', 'delivered')->first();
        $event = $row ? DB::table('chat_family_work_events')->find($row->event_id) : null;
        $conversation = $event ? OpsConversation::with('client')->find($event->conversation_id) : null;

        return $event && $conversation && $this->visible($event, $conversation, $user) ? $conversation : null;
    }

    /** @param  mixed  $within  Optional notification-ID subquery; only those notices are evaluated. */
    public function visibleNoticeIds(User $user, mixed $within = null): array
    {
        if (! config('chat.shared_experience')) {
            return [];
        }
        $rows = DB::table('chat_family_work_deliveries')->where('user_id', $user->id)->where('status', 'delivered')
            ->when($within, fn ($query) => $query->whereIn('notification_id', $within))->get();
        $events = DB::table('chat_family_work_events')->whereIn('id', $rows->pluck('event_id'))->get()->keyBy('id');
        $conversations = OpsConversation::with('client')->whereIn('id', $events->pluck('conversation_id'))->get()->keyBy('id');

        return $rows->filter(function ($row) use ($events, $conversations, $user) {
            $event = $events->get($row->event_id);
            $conversation = $event ? $conversations->get($event->conversation_id) : null;

            return $event && $conversation && $this->visible($event, $conversation, $user);
        })->pluck('notification_id')->all();
    }
}
