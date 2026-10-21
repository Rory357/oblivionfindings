<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsMessage;
use App\Services\Chat\ChatBrowserPushDelivery;
use App\Services\Chat\ChatCallNoticeDelivery;
use App\Services\Chat\ChatFamilyWorkDelivery;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatNotificationPreviews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatNotificationController extends Controller
{
    public function previews(Request $request, ChatNotificationPreviews $previews)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:30'], 'ids.*' => ['required', 'uuid', 'distinct']]);

        return response()->json(['actor_id' => (int) $request->user()->id, 'previews' => $previews->forUser($request->user(), $data['ids'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function display(Request $request, string $notification, ChatBrowserPushDelivery $push)
    {
        $binding = $request->query('binding');
        $preview = is_string($binding) ? $push->displayPreview($request->user(), $notification, $binding) : null;

        // Read-only: this does not acknowledge delivery, mark a message read or renew idle activity.
        return response()->json($preview ?? ['message' => 'Notification unavailable.'], $preview ? 200 : 404)
            ->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, string $notification, ChatMessageDelivery $delivery)
    {
        $row = DB::table('chat_message_deliveries')->where('notification_id', $notification)
            ->where('user_id', $request->user()->id)->where(fn ($query) => $query->where('status', 'delivered')
                // Push may arrive before the provider acknowledgement is persisted, including a push-only opt-in.
            ->orWhereExists(fn ($push) => $push->selectRaw('1')->from('chat_browser_push_deliveries')
                ->whereColumn('delivery_id', 'chat_message_deliveries.id')->where('attempts', '>', 0)))->first();
        if (! $row) {
            $missed = app(ChatCallNoticeDelivery::class)->conversationForNotice($request->user(), $notification);
            if ($missed) {
                $request->user()->notifications()->whereKey($notification)->update(['read_at' => now()]);

                return redirect()->route('chat.index', array_filter(['client' => $missed->client_id, 'conversation' => $missed->id, 'calls' => 'missed']));
            }
            $conversation = app(ChatFamilyWorkDelivery::class)->conversationForNotice($request->user(), $notification);
            abort_unless($conversation, 404);
            $request->user()->notifications()->whereKey($notification)->update(['read_at' => now()]);

            return redirect()->route('chat.index', ['client' => $conversation->client_id, 'conversation' => $conversation->id]);
        }
        $message = OpsMessage::with('conversation.client')->find($row->message_id);
        abort_unless($message?->conversation && $delivery->visible($message, $message->conversation, $request->user()), 404);
        $request->user()->notifications()->whereKey($notification)->update(['read_at' => now()]);

        // This opens the message; the visible-thread read cursor still owns message read state.
        return redirect()->route('chat.index', array_filter([
            'client' => $message->conversation->client_id, 'conversation' => $message->conversation_id, 'message' => $message->id,
        ]));
    }
}
