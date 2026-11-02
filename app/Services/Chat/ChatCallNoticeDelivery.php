<?php

namespace App\Services\Chat;

use App\Jobs\DeliverChatCallNotifications;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\OpsConversation;
use App\Models\User;
use App\Notifications\ChatMessageNotice;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable per-invitation missed notices. No caller names or care content are retained. */
final class ChatCallNoticeDelivery
{
    public const EVENT = 'chat.call.missed';

    public function enqueue(ChatCall $call): void
    {
        if (! config('chat.shared_experience')) {
            return;
        }
        $inserted = false;
        foreach ($call->participants()->get() as $participant) {
            if (ChatCallOutcome::forParticipant($call, $participant) !== 'missed') {
                continue;
            }
            $inserted = (bool) DB::table('chat_call_notice_deliveries')->insertOrIgnore([
                'notification_id' => (string) Str::uuid(), 'participant_id' => $participant->id,
                'invitation_version' => $participant->invitation_version ?? 1, 'user_id' => $participant->user_id,
                'status' => 'pending', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]) || $inserted;
        }
        if (! $inserted) {
            return;
        }
        $dispatch = function () use ($call): void {
            try {
                Bus::dispatch(new DeliverChatCallNotifications($call->id));
            } catch (\Throwable $error) {
                // Recovery owns the persisted rows; a queue outage does not undo an ended call.
                report($error);
            }
        };
        DB::transactionLevel() ? DB::afterCommit($dispatch) : $dispatch();
    }

    public function visible(object $row, ChatCallParticipant $participant, User $user): bool
    {
        $call = $participant->call;
        $conversation = $call?->conversation;
        if (! config('chat.shared_experience') || ! $conversation
            || (int) $row->user_id !== (int) $user->id || (int) $participant->user_id !== (int) $user->id
            || (int) $row->invitation_version !== (int) ($participant->invitation_version ?? 1)
            || ChatCallOutcome::forParticipant($call, $participant) !== 'missed'
            || ! app(ChatAccess::class)->allows($user, $conversation)) {
            return false;
        }
        $policy = app(ChatSettings::class)->forConversation($conversation);
        if (! $policy['enabled'] || ($conversation->conversation_type === 'family' && ! $policy['family_enabled'])) {
            return false;
        }
        $member = $conversation->participants()->where('user_id', $user->id)->first();

        return ! DB::table('chat_history_call_exclusions')->where('call_id', $call->id)
            ->where('participant_id', $member?->id ?? 0)->exists();
    }

    public function recover(?string $callId = null): int
    {
        if (! config('chat.shared_experience')) {
            return 0;
        }
        $ids = DB::table('chat_call_notice_deliveries')->where('status', 'pending')->where('available_at', '<=', now())
            ->when($callId, fn ($query) => $query->whereIn('participant_id', ChatCallParticipant::where('call_id', $callId)->select('id')))
            ->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $count += $this->deliver((int) $id);
            } catch (\Throwable $error) {
                DB::table('chat_call_notice_deliveries')->where('id', $id)->where('status', 'pending')->update([
                    // MySQL applies SET left to right, so status/backoff read the incremented count.
                    // Stop after ten attempts instead of retrying and reporting the same row forever.
                    'attempts' => DB::raw('attempts + 1'),
                    'status' => DB::raw("CASE WHEN attempts >= 10 THEN 'failed' ELSE 'pending' END"),
                    'last_error' => DB::raw("CASE WHEN attempts >= 10 THEN 'Delivery failed; retries exhausted' ELSE 'Delivery failed; retry pending' END"),
                    'available_at' => now()->addMinutes(min(60, 2 ** min(6, (int) DB::table('chat_call_notice_deliveries')->where('id', $id)->value('attempts')))), 'updated_at' => now(),
                ]);
                report($error);
            }
        }

        return $count;
    }

    private function deliver(int $id): int
    {
        $candidate = DB::table('chat_call_notice_deliveries')->find($id);
        $person = $candidate ? ChatCallParticipant::with('call')->find($candidate->participant_id) : null;
        if (! $person?->call) {
            return 0;
        }

        return DB::transaction(function () use ($id, $person): int {
            // Shared on the conversation (never updated here); the call rows below stay exclusive.
            $conversation = OpsConversation::whereKey($person->call->conversation_id)->sharedLock()->first();
            $call = ChatCall::whereKey($person->call_id)->lockForUpdate()->first();
            $participant = ChatCallParticipant::whereKey($person->id)->lockForUpdate()->first();
            $row = DB::table('chat_call_notice_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending' || $row->available_at > now()->toDateTimeString()) {
                return 0;
            }
            $user = User::find($row->user_id);
            if ($call && $participant) {
                $call->setRelation('conversation', $conversation);
                $participant->setRelation('call', $call);
            }
            $preference = $user ? app(ChatNotificationPreferences::class)->forUser($user, self::EVENT) : null;
            $member = $conversation?->participants()->where('user_id', $row->user_id)->first();
            if (! $user || ! $participant || ! $this->visible($row, $participant, $user)
                || $member?->is_muted || ! $preference['enabled'] || ! $preference['inapp']) {
                $this->finish($id, 'suppressed');

                return 0;
            }
            if ($until = app(ChatAvailability::class)->deferUntil($user)) {
                DB::table('chat_call_notice_deliveries')->where('id', $id)->update(['available_at' => $until, 'updated_at' => now()]);

                return 0;
            }
            if (! $user->notifications()->whereKey($row->notification_id)->exists()) {
                $user->notifyNow(new ChatMessageNotice($row->notification_id, self::EVENT));
            }
            if (! $user->notifications()->whereKey($row->notification_id)->exists()) {
                throw new \RuntimeException('Missed call notice was not persisted.');
            }
            $this->finish($id, 'delivered');
            app(ChatRealtime::class)->noticeChanged([(int) $user->id], 'call');

            return 1;
        });
    }

    private function finish(int $id, string $status): void
    {
        DB::table('chat_call_notice_deliveries')->where('id', $id)->update([
            'status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null,
            'last_error' => null, 'updated_at' => now(),
        ]);
    }

    public function conversationForNotice(User $user, string $notice): ?OpsConversation
    {
        if (! config('chat.shared_experience')) {
            return null;
        }
        $row = DB::table('chat_call_notice_deliveries')->where('notification_id', $notice)->where('user_id', $user->id)->where('status', 'delivered')->first();
        $participant = $row ? ChatCallParticipant::with('call.conversation.client')->find($row->participant_id) : null;

        return $participant && $this->visible($row, $participant, $user) ? $participant->call->conversation : null;
    }

    /** @param  mixed  $within  Optional notification-ID subquery; only those notices are evaluated. */
    public function visibleNoticeIds(User $user, mixed $within = null): array
    {
        if (! config('chat.shared_experience')) {
            return [];
        }
        $rows = DB::table('chat_call_notice_deliveries')->where('user_id', $user->id)->where('status', 'delivered')
            ->when($within, fn ($query) => $query->whereIn('notification_id', $within))->get();
        $participants = ChatCallParticipant::with('call.conversation.client')->whereIn('id', $rows->pluck('participant_id'))->get()->keyBy('id');

        return $rows->filter(fn ($row) => ($participant = $participants->get($row->participant_id)) && $this->visible($row, $participant, $user))
            ->pluck('notification_id')->all();
    }
}
