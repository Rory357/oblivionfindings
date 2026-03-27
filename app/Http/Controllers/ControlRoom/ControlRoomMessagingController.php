<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Communication;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ControlRoomMessagingController extends Controller
{
    /**
     * List conversation threads grouped by alert or direct user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        // Build alert-linked threads: group by alert_id where alert_id is not null
        $alertThreads = Communication::query()
            ->whereNotNull('alert_id')
            ->select(
                'alert_id',
                DB::raw('MAX(id) as last_message_id'),
                DB::raw('MAX(sent_at) as last_message_at'),
                DB::raw("SUM(CASE WHEN direction = 'inbound' AND delivered_at IS NULL THEN 1 ELSE 0 END) as unread_count"),
                DB::raw('COUNT(*) as message_count'),
            )
            ->groupBy('alert_id')
            ->orderByDesc('last_message_at')
            ->get();

        $alertThreadData = [];
        foreach ($alertThreads as $thread) {
            $lastMessage = Communication::find($thread->last_message_id);
            $alert = $lastMessage?->alert;

            $alertThreadData[] = [
                'id' => 'alert-' . $thread->alert_id,
                'type' => 'alert',
                'alert_id' => $thread->alert_id,
                'user_id' => null,
                'title' => $alert ? ucfirst(str_replace('_', ' ', $alert->alert_type)) . ' #' . $alert->id : 'Alert #' . $thread->alert_id,
                'last_message' => $lastMessage ? substr($lastMessage->content, 0, 80) : '',
                'last_message_at' => $thread->last_message_at,
                'unread_count' => (int) $thread->unread_count,
                'message_count' => (int) $thread->message_count,
            ];
        }

        // Build direct message threads: group by target_user_id where alert_id is null
        $directThreads = Communication::query()
            ->whereNull('alert_id')
            ->whereNotNull('target_user_id')
            ->select(
                'target_user_id',
                DB::raw('MAX(id) as last_message_id'),
                DB::raw('MAX(sent_at) as last_message_at'),
                DB::raw("SUM(CASE WHEN direction = 'inbound' AND delivered_at IS NULL THEN 1 ELSE 0 END) as unread_count"),
                DB::raw('COUNT(*) as message_count'),
            )
            ->groupBy('target_user_id')
            ->orderByDesc('last_message_at')
            ->get();

        $directThreadData = [];
        foreach ($directThreads as $thread) {
            $lastMessage = Communication::find($thread->last_message_id);
            $targetUser = User::find($thread->target_user_id);

            $directThreadData[] = [
                'id' => 'user-' . $thread->target_user_id,
                'type' => 'direct',
                'alert_id' => null,
                'user_id' => $thread->target_user_id,
                'title' => $targetUser?->name ?? 'Unknown User',
                'last_message' => $lastMessage ? substr($lastMessage->content, 0, 80) : '',
                'last_message_at' => $thread->last_message_at,
                'unread_count' => (int) $thread->unread_count,
                'message_count' => (int) $thread->message_count,
            ];
        }

        // Merge and sort by last message time
        $threads = collect(array_merge($alertThreadData, $directThreadData))
            ->sortByDesc('last_message_at')
            ->values()
            ->all();

        // Staff list for new conversation
        $staff = User::orderBy('name')
            ->select('id', 'name')
            ->limit(200)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])
            ->all();

        return Inertia::render('control-room/messaging', [
            'threads' => $threads,
            'staff' => $staff,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    /**
     * Get messages for a specific thread (alert-linked or direct).
     */
    public function thread(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $alertId = $request->input('alert_id');
        $userId = $request->input('user_id');

        abort_unless($alertId || $userId, 400, 'Either alert_id or user_id is required.');

        $query = Communication::query()
            ->with(['targetUser:id,name', 'initiatedBy:id,name']);

        if ($alertId) {
            $query->where('alert_id', (int) $alertId);
        } else {
            $query->whereNull('alert_id')
                ->where('target_user_id', (int) $userId);
        }

        $messages = $query->orderBy('sent_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (Communication $msg) => [
                'id' => $msg->id,
                'direction' => $msg->direction,
                'content' => $msg->content,
                'sender_name' => $msg->direction === 'outbound'
                    ? ($msg->initiatedBy?->name ?? 'System')
                    : ($msg->targetUser?->name ?? 'Unknown'),
                'sent_at' => $msg->sent_at?->toISOString(),
                'delivered_at' => $msg->delivered_at?->toISOString(),
                'status' => $msg->status,
            ])
            ->all();

        return response()->json([
            'messages' => $messages,
        ]);
    }

    /**
     * Send a message.
     */
    public function send(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'alert_id' => ['nullable', 'integer', 'exists:control_room_alerts,id'],
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $communication = Communication::create([
            'alert_id' => $validated['alert_id'] ?? null,
            'channel' => 'in_app',
            'direction' => 'outbound',
            'purpose' => 'update',
            'status' => 'sent',
            'target_user_id' => $validated['target_user_id'],
            'content' => $validated['content'],
            'sent_at' => now(),
            'initiated_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.messaging.sent', $communication, [
            'target_user_id' => $validated['target_user_id'],
            'alert_id' => $validated['alert_id'] ?? null,
        ]);

        return response()->json([
            'message' => [
                'id' => $communication->id,
                'direction' => $communication->direction,
                'content' => $communication->content,
                'sender_name' => $user->name,
                'sent_at' => $communication->sent_at->toISOString(),
                'delivered_at' => null,
                'status' => $communication->status,
            ],
        ]);
    }

    /**
     * Mark a message as read (delivered).
     */
    public function markRead(Request $request, int $communicationId)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $communication = Communication::findOrFail($communicationId);

        if (!$communication->delivered_at) {
            $communication->update([
                'delivered_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }
}
