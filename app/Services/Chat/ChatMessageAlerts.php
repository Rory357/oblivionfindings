<?php

namespace App\Services\Chat;

use App\Models\OpsMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Content-free arrival candidates, never an acknowledgement or a history feed. */
final class ChatMessageAlerts
{
    public function __construct(private readonly ChatMessageDelivery $delivery) {}

    public function since(User $actor, ?int $after): array
    {
        $query = DB::table('chat_message_deliveries')->where('user_id', $actor->id);
        $latest = (int) (clone $query)->max('id');
        $result = ['actor_id' => (int) $actor->id, 'cursor' => $latest, 'alerts' => [], 'has_more' => false];
        // A new browser session starts quietly. Never replay a notification backlog.
        if ($after === null || $after >= $latest) {
            return $result;
        }
        if (! app(ChatNotificationSound::class)->enabledFor($actor) || app(ChatAvailability::class)->deferUntil($actor)) {
            return $result;
        }
        $rows = (clone $query)->where('id', '>', $after)->where('id', '<=', $latest)->orderBy('id')->limit(100)->get();
        $result['cursor'] = (int) ($rows->last()?->id ?? $latest);
        $result['has_more'] = $result['cursor'] < $latest;
        $messages = OpsMessage::with('conversation.client')->whereIn('id', $rows->pluck('message_id'))->get()->keyBy('id');
        $cutoff = now()->subSeconds(30)->toDateTimeString();
        foreach ($rows as $row) {
            $message = $messages->get($row->message_id);
            if ($row->created_at < $cutoff || $row->status === 'suppressed'
                || ! $message?->conversation || (int) $message->sender_id === (int) $actor->id
                || ! $this->delivery->inAppEnabled($actor, $row->event_key)
                || ! $this->delivery->shouldNotify($message, $message->conversation, $actor)) {
                continue;
            }
            $result['alerts'][] = ['id' => $row->notification_id, 'conversation_id' => (int) $message->conversation_id];
        }

        return $result;
    }
}
