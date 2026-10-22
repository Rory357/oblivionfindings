<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Models\UserPushSubscription;
use App\Notifications\ChatMessageNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChatBrowserPushDelivery
{
    public function __construct(private readonly ChatBrowserPushTransport $transport) {}

    public static function fingerprint(UserPushSubscription $subscription): string
    {
        return hash('sha256', json_encode([$subscription->id, $subscription->user_id, $subscription->provider,
            $subscription->token, $subscription->keys, (int) $subscription->chat_protocol_version,
            $subscription->chat_registration_id], JSON_THROW_ON_ERROR));
    }

    public function enqueue(int $deliveryId, User $user, string $eventKey): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Browser push work must be committed with its message.');
        }
        if (! app(ChatNotificationPreferences::class)->browserPushEnabled($user, $eventKey)) {
            return;
        }
        foreach ($user->pushSubscriptions()->where('provider', 'webpush')->where('enabled', true)
            ->where('chat_protocol_version', ChatBrowserProtocol::VERSION)->whereNotNull('chat_registration_id')->get() as $subscription) {
            if (! ChatBrowserProtocol::supports($subscription)) {
                continue;
            }
            DB::table('chat_browser_push_deliveries')->insertOrIgnore([
                'delivery_id' => $deliveryId, 'subscription_id' => $subscription->id,
                'subscription_fingerprint' => self::fingerprint($subscription), 'status' => 'pending',
                'available_at' => now(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function recover(?int $messageId = null): int
    {
        if (! config('chat.shared_experience') || ! config('chat.notifications.browser_push_enabled')) {
            return 0;
        }
        $rows = DB::table('chat_browser_push_deliveries')->where(function ($query) {
            $query->where(fn ($pending) => $pending->where('status', 'pending')->where(fn ($due) => $due->where('available_at', '<=', now())->orWhere('expires_at', '<=', now())))
                ->orWhere(fn ($abandoned) => $abandoned->where('status', 'sending')->where('lease_until', '<=', now()));
        })->when($messageId, fn ($query) => $query->whereIn('delivery_id', DB::table('chat_message_deliveries')->select('id')->where('message_id', $messageId)))
            ->orderBy('id')->limit(100)->pluck('id');
        $accepted = 0;
        $deadline = microtime(true) + 20;
        foreach ($rows as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $claim = null;
            try {
                $claim = $this->claim((int) $id);
                if (! $claim) {
                    continue;
                }
                // No provider I/O while holding conversation or delivery locks.
                // Re-read after claiming: account reassignment, consent and preferences may have changed.
                $context = $this->context($claim);
                if (! $context) {
                    $this->settle($claim, 'suppressed');

                    continue;
                }
                [$parent, $user, $subscription] = $context;
                if ($deferredUntil = app(ChatAvailability::class)->deferUntil($user)) {
                    $this->settle($claim, 'pending', $deferredUntil);

                    continue;
                }
                if (! DB::table('chat_browser_push_deliveries')->where('id', $claim->id)->where('claim_token', $claim->claim_token)
                    ->where('status', 'sending')->where('lease_until', '>', now())->exists()) {
                    continue;
                }
                $preview = (new ChatMessageNotice($parent->notification_id, $parent->event_key))->toArray($user);
                $outcome = $this->transport->send($subscription, [
                    'title' => $preview['title'], 'body' => $preview['body'],
                    'data' => ['type' => 'chat', 'notification_id' => $parent->notification_id, 'url' => $preview['url'],
                        'device_binding' => $claim->subscription_fingerprint,
                        'silent' => ! app(ChatNotificationSound::class)->enabledFor($user)],
                ]);
                $accepted += $this->complete($claim, $outcome);
            } catch (\Throwable) {
                // Never retain care data, endpoints or keys, or reset another worker's lease.
                if ($claim) {
                    $this->settle($claim, 'pending', now()->addMinute(), 'Push attempt interrupted');
                } else {
                    DB::table('chat_browser_push_deliveries')->where('id', $id)->where('status', 'pending')->update([
                        'available_at' => now()->addMinute(), 'last_error' => 'Push preparation unavailable', 'updated_at' => now(),
                    ]);
                }
            }
        }

        return $accepted;
    }

    public function displayPreview(User $viewer, string $notification, string $binding): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $binding)
            || ! config('chat.shared_experience') || ! config('chat.notifications.browser_push_enabled')) {
            return null;
        }
        $parent = DB::table('chat_message_deliveries')->where('notification_id', $notification)
            ->where('user_id', $viewer->id)->first();
        $row = $parent ? DB::table('chat_browser_push_deliveries')->where('delivery_id', $parent->id)
            ->where('subscription_fingerprint', $binding)->where('attempts', '>', 0)
            ->whereIn('status', ['sending', 'accepted', 'pending'])->first() : null;
        $context = $row ? $this->context($row) : null;
        if (! $context || $this->quiet($context[1])) {
            return null;
        }
        // Recheck the current session and current authority when the browser is about to display,
        // including delivery that reached the provider before a sign-out or account change.
        $preview = (new ChatMessageNotice($notification, $parent->event_key))->toArray($viewer);

        return ['title' => $preview['title'], 'body' => $preview['body'],
            'actor_id' => (int) $viewer->id, 'conversation_id' => (int) $context[3]->conversation_id,
            'data' => ['type' => 'chat', 'notification_id' => $notification, 'device_binding' => $binding,
                'silent' => ! app(ChatNotificationSound::class)->enabledFor($context[1])]];
    }

    private function claim(int $id): ?object
    {
        $candidate = DB::table('chat_browser_push_deliveries')->find($id);
        $parent = $candidate ? DB::table('chat_message_deliveries')->find($candidate->delivery_id) : null;
        $message = $parent ? OpsMessage::withTrashed()->find($parent->message_id) : null;
        if (! $message) {
            return null;
        }

        return DB::transaction(function () use ($id, $message): ?object {
            // Shared: writers still exclude the claim; claims no longer queue behind each other.
            OpsConversation::whereKey($message->conversation_id)->sharedLock()->first();
            $row = DB::table('chat_browser_push_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['pending', 'sending'], true)
                || ($row->status === 'sending' ? $row->lease_until > now()->toDateTimeString() : ($row->available_at > now()->toDateTimeString() && $row->expires_at > now()->toDateTimeString()))) {
                return null;
            }
            $context = $this->context($row);
            if (! $context) {
                DB::table('chat_browser_push_deliveries')->where('id', $id)->update([
                    'status' => 'suppressed', 'claim_token' => null, 'lease_until' => null, 'last_error' => null, 'updated_at' => now(),
                ]);

                return null;
            }
            [, $user] = $context;
            if ($deferredUntil = app(ChatAvailability::class)->deferUntil($user)) {
                DB::table('chat_browser_push_deliveries')->where('id', $id)->update([
                    'status' => 'pending', 'claim_token' => null, 'lease_until' => null,
                    'available_at' => $deferredUntil, 'updated_at' => now(),
                ]);

                return null;
            }
            DB::table('chat_browser_push_deliveries')->where('id', $id)->update([
                'status' => 'sending', 'claim_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(2),
                'attempts' => $row->attempts + 1, 'updated_at' => now(),
            ]);

            return DB::table('chat_browser_push_deliveries')->find($id);
        });
    }

    /** Single-tenant authority: current user, membership/history, approved client/site access and exact device binding. */
    private function context(object $row): ?array
    {
        if (! config('chat.shared_experience') || ! config('chat.notifications.browser_push_enabled') || $row->expires_at <= now()->toDateTimeString()) {
            return null;
        }
        $parent = DB::table('chat_message_deliveries')->find($row->delivery_id);
        $user = $parent ? User::find($parent->user_id) : null;
        $message = $parent ? OpsMessage::with('conversation.client')->find($parent->message_id) : null;
        $subscription = UserPushSubscription::find($row->subscription_id);
        // A message notice replaced by a newer one in the same conversation sends no push either.
        if (! $user || ! $message?->conversation || ! $subscription || ! $subscription->enabled || $parent->status === 'superseded' || ! ChatBrowserProtocol::supports($subscription)
            || (int) $subscription->user_id !== (int) $user->id || ! hash_equals($row->subscription_fingerprint, self::fingerprint($subscription))
            || ! app(ChatNotificationPreferences::class)->browserPushEnabled($user, $parent->event_key)
            || ! app(ChatMessageDelivery::class)->shouldNotify($message, $message->conversation, $user)) {
            return null;
        }

        return [$parent, $user, $subscription, $message];
    }

    private function quiet(User $user): bool
    {
        return app(ChatAvailability::class)->deferUntil($user) !== null;
    }

    private function settle(object $claim, string $status, mixed $availableAt = null, ?string $error = null): int
    {
        return DB::table('chat_browser_push_deliveries')->where('id', $claim->id)->where('status', 'sending')->where('claim_token', $claim->claim_token)->update([
            'status' => $status, 'claim_token' => null, 'lease_until' => null, 'last_error' => $error,
            'available_at' => $availableAt ?? now(), 'accepted_at' => $status === 'accepted' ? now() : null, 'updated_at' => now(),
        ]);
    }

    private function complete(object $claim, string $outcome): int
    {
        return DB::transaction(function () use ($claim, $outcome): int {
            $row = DB::table('chat_browser_push_deliveries')->where('id', $claim->id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'sending' || $row->claim_token !== $claim->claim_token) {
                return 0;
            }
            if ($outcome === 'accepted') {
                return $this->settle($claim, 'accepted');
            }
            if ($outcome === 'expired') {
                $subscription = UserPushSubscription::whereKey($claim->subscription_id)->lockForUpdate()->first();
                if ($subscription && hash_equals($claim->subscription_fingerprint, self::fingerprint($subscription))) {
                    $subscription->update(['enabled' => false]);
                }
            }
            if (in_array($outcome, ['expired', 'rejected'], true)) {
                $this->settle($claim, $outcome, error: $outcome === 'expired' ? 'Subscription expired' : 'Subscription rejected');
            } else {
                $seconds = min(3600, 60 * (2 ** min(6, max(0, $claim->attempts - 1))));
                $this->settle($claim, 'pending', now()->addSeconds($seconds), $outcome === 'unavailable' ? 'Push configuration unavailable' : 'Provider outcome unconfirmed');
            }

            return 0;
        });
    }
}
