<?php

namespace App\Services\Chat;

use App\Models\OpsMessage;
use App\Models\User;
use App\Notifications\ChatMessageNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ChatNotificationPreviews
{
    public function forUser(User $actor, array $ids): array
    {
        $previews = array_fill_keys($ids, null);
        // This projection is for the signed-in app only. External payloads stay generic.
        if (! Schema::hasTable('chat_personal_preferences') || ! app(ChatPersonalPreferences::class)->read($actor)['values']['show_previews']) {
            return $previews;
        }
        $owned = $actor->notifications()->where('type', ChatMessageNotice::class)->whereIn('id', $ids)->pluck('id');
        $rows = DB::table('chat_message_deliveries')->where('user_id', $actor->id)
            ->where('status', 'delivered')->whereIn('notification_id', $owned)->get();
        $messages = OpsMessage::with(['conversation.client', 'sender'])->whereIn('id', $rows->pluck('message_id'))->get()->keyBy('id');
        $delivery = app(ChatMessageDelivery::class);
        foreach ($rows as $row) {
            $message = $messages->get($row->message_id);
            if (! $message?->conversation || ! $delivery->visible($message, $message->conversation, $actor)) {
                continue;
            }
            $body = trim(preg_replace('/\s+/u', ' ', $message->content ?? '') ?? '');
            $previews[$row->notification_id] = ($message->sender?->name ?? 'Chat').': '.($body !== '' ? Str::limit($body, 180) : 'Shared an attachment');
        }

        return $previews;
    }
}
