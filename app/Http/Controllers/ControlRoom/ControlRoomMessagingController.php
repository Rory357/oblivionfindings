<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\Communication;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ControlRoomMessagingController extends Controller
{
    use AuthorizesControlRoomAlertAccess;

    /**
     * List conversation threads grouped by alert or direct user.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();

        // Build alert-linked threads: group by alert_id where alert_id is not null
        $alertThreads = Communication::query()
            ->whereNotNull('alert_id')
            ->whereHas('alert', fn (Builder $alertQuery) => $siteAccess->applyAlertScope(
                $alertQuery,
                $user,
                $bypassPermissions,
            ))
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

        $alertLastMessages = Communication::query()
            ->with('alert:id,alert_type')
            ->whereIn('id', $alertThreads->pluck('last_message_id'))
            ->get()
            ->keyBy('id');

        $alertThreadData = [];
        foreach ($alertThreads as $thread) {
            $lastMessage = $alertLastMessages->get($thread->last_message_id);
            $alert = $lastMessage?->alert;

            $alertThreadData[] = [
                'id' => 'alert-'.$thread->alert_id,
                'type' => 'alert',
                'alert_id' => $thread->alert_id,
                'user_id' => null,
                'title' => $alert ? ucfirst(str_replace('_', ' ', $alert->alert_type)).' #'.$alert->id : 'Alert #'.$thread->alert_id,
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
            ->whereHas('targetUser', fn (Builder $targetUserQuery) => $siteAccess->applyStaffScope(
                $targetUserQuery,
                $user,
                $bypassPermissions,
            ))
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

        $directLastMessages = Communication::query()
            ->with('targetUser:id,name')
            ->whereIn('id', $directThreads->pluck('last_message_id'))
            ->get()
            ->keyBy('id');

        $directThreadData = [];
        foreach ($directThreads as $thread) {
            $lastMessage = $directLastMessages->get($thread->last_message_id);
            $targetUser = $lastMessage?->targetUser;

            $directThreadData[] = [
                'id' => 'user-'.$thread->target_user_id,
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
        $staff = $this->accessibleStaffQuery($user)
            ->orderBy('name')
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

        $validated = $request->validate([
            'alert_id' => ['nullable', 'integer', 'required_without:user_id', Rule::prohibitedIf($request->filled('user_id')), 'exists:control_room_alerts,id'],
            'user_id' => ['nullable', 'integer', 'required_without:alert_id', Rule::prohibitedIf($request->filled('alert_id')), 'exists:users,id'],
        ]);

        $query = Communication::query()
            ->with(['targetUser:id,name', 'initiatedBy:id,name']);

        if (filled($validated['alert_id'] ?? null)) {
            $alert = ControlRoomAlert::query()->findOrFail((int) $validated['alert_id']);
            $this->assertCanAccessAlert($user, $alert);
            $query->where('alert_id', $alert->id);
        } else {
            $targetUser = $this->assertCanAccessStaff(
                $user,
                (int) $validated['user_id'],
                'You are not authorized to access messages for that staff member.',
            );
            $query->whereNull('alert_id')
                ->where('target_user_id', $targetUser->id);
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

        if (filled($validated['alert_id'] ?? null)) {
            $alert = ControlRoomAlert::query()->findOrFail((int) $validated['alert_id']);
            $this->assertCanAccessAlert($user, $alert);
        }

        $targetUser = $this->assertCanAccessStaff(
            $user,
            (int) $validated['target_user_id'],
            'You are not authorized to message that staff member.',
        );

        $communication = Communication::create([
            'alert_id' => $validated['alert_id'] ?? null,
            'channel' => 'in_app',
            'direction' => 'outbound',
            'purpose' => 'update',
            'status' => 'sent',
            'target_user_id' => $targetUser->id,
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
    public function markRead(Request $request, Communication $communication)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        if ($communication->alert_id) {
            $communication->loadMissing('alert');
            abort_unless($communication->alert, 403);
            $this->assertCanAccessAlert($user, $communication->alert);
        } else {
            $this->assertCanAccessStaff(
                $user,
                (int) $communication->target_user_id,
                'You are not authorized to access messages for that staff member.',
            );
        }

        if (! $communication->delivered_at) {
            $communication->update([
                'delivered_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }
}
