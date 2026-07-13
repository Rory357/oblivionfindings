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

        $rankedAlertCommunications = Communication::query()
            ->conversational()
            ->whereNotNull('alert_id')
            ->whereHas('alert', fn (Builder $alertQuery) => $siteAccess->applyAlertScope(
                $alertQuery,
                $user,
                $bypassPermissions,
            ))
            ->select([
                'control_room_communications.id',
                'control_room_communications.alert_id',
                'control_room_communications.content',
                'control_room_communications.sent_at',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY alert_id ORDER BY sent_at DESC, id DESC) as thread_rank')
            ->selectRaw('COUNT(*) OVER (PARTITION BY alert_id) as message_count')
            ->selectRaw("SUM(CASE WHEN direction = 'inbound' AND delivered_at IS NULL THEN 1 ELSE 0 END) OVER (PARTITION BY alert_id) as unread_count");

        $alertThreads = DB::query()
            ->fromSub($rankedAlertCommunications->toBase(), 'ranked_alert_communications')
            ->where('thread_rank', 1)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        $alertsById = ControlRoomAlert::query()
            ->select('id', 'alert_type')
            ->whereIn('id', $alertThreads->pluck('alert_id'))
            ->get()
            ->keyBy('id');

        $alertThreadData = [];
        foreach ($alertThreads as $thread) {
            $alert = $alertsById->get($thread->alert_id);

            $alertThreadData[] = [
                'id' => 'alert-'.$thread->alert_id,
                'type' => 'alert',
                'alert_id' => $thread->alert_id,
                'user_id' => null,
                'title' => $alert ? ucfirst(str_replace('_', ' ', $alert->alert_type)).' #'.$alert->id : 'Alert #'.$thread->alert_id,
                'last_message' => substr((string) $thread->content, 0, 80),
                'last_message_at' => $thread->sent_at,
                'unread_count' => (int) $thread->unread_count,
                'message_count' => (int) $thread->message_count,
                '_latest_message_id' => (int) $thread->id,
            ];
        }

        $rankedDirectCommunications = Communication::query()
            ->conversational()
            ->whereNull('alert_id')
            ->whereNotNull('target_user_id')
            ->whereHas('targetUser', function (Builder $targetUserQuery) use ($siteAccess, $user, $bypassPermissions): void {
                $targetUserQuery->staff();
                $siteAccess->applyStaffScope($targetUserQuery, $user, $bypassPermissions);
            })
            ->select([
                'control_room_communications.id',
                'control_room_communications.target_user_id',
                'control_room_communications.content',
                'control_room_communications.sent_at',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY target_user_id ORDER BY sent_at DESC, id DESC) as thread_rank')
            ->selectRaw('COUNT(*) OVER (PARTITION BY target_user_id) as message_count')
            ->selectRaw("SUM(CASE WHEN direction = 'inbound' AND delivered_at IS NULL THEN 1 ELSE 0 END) OVER (PARTITION BY target_user_id) as unread_count");

        $directThreads = DB::query()
            ->fromSub($rankedDirectCommunications->toBase(), 'ranked_direct_communications')
            ->where('thread_rank', 1)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        $targetUsersById = User::query()
            ->staff()
            ->select('id', 'name')
            ->whereIn('id', $directThreads->pluck('target_user_id'))
            ->get()
            ->keyBy('id');

        $directThreadData = [];
        foreach ($directThreads as $thread) {
            $targetUser = $targetUsersById->get($thread->target_user_id);

            $directThreadData[] = [
                'id' => 'user-'.$thread->target_user_id,
                'type' => 'direct',
                'alert_id' => null,
                'user_id' => $thread->target_user_id,
                'title' => $targetUser?->name ?? 'Unknown User',
                'last_message' => substr((string) $thread->content, 0, 80),
                'last_message_at' => $thread->sent_at,
                'unread_count' => (int) $thread->unread_count,
                'message_count' => (int) $thread->message_count,
                '_latest_message_id' => (int) $thread->id,
            ];
        }

        $threads = collect(array_merge($alertThreadData, $directThreadData))
            ->sort(function (array $left, array $right): int {
                $timeOrder = strcmp((string) $right['last_message_at'], (string) $left['last_message_at']);

                return $timeOrder !== 0
                    ? $timeOrder
                    : $right['_latest_message_id'] <=> $left['_latest_message_id'];
            })
            ->map(function (array $thread): array {
                unset($thread['_latest_message_id']);

                return $thread;
            })
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
            'alert_id' => ['nullable', 'integer', 'required_without:user_id', Rule::prohibitedIf($request->filled('user_id'))],
            'user_id' => ['nullable', 'integer', 'required_without:alert_id', Rule::prohibitedIf($request->filled('alert_id'))],
        ]);

        $query = Communication::query()
            ->conversational()
            ->with(['targetUser:id,name', 'initiatedBy:id,name']);

        if (filled($validated['alert_id'] ?? null)) {
            $alert = $this->resolveAccessibleAlert($user, (int) $validated['alert_id']);
            $query->where('alert_id', $alert->id);
        } else {
            $targetUser = $this->resolveAccessibleStaff($user, (int) $validated['user_id']);
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

        $alertIdentifier = $request->validate([
            'alert_id' => ['nullable', 'integer'],
        ]);

        $alert = filled($alertIdentifier['alert_id'] ?? null)
            ? $this->resolveAccessibleAlert($user, (int) $alertIdentifier['alert_id'])
            : null;

        $targetIdentifier = $request->validate([
            'target_user_id' => ['required', 'integer'],
        ]);
        $targetUser = $this->resolveAccessibleStaff($user, (int) $targetIdentifier['target_user_id']);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $communication = Communication::create([
            'alert_id' => $alert?->id,
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
            'target_user_id' => $targetUser->id,
            'alert_id' => $alert?->id,
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

        $communication = $this->resolveAccessibleCommunication($user, $communication);

        if (! $communication->delivered_at) {
            $communication->update([
                'delivered_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function resolveAccessibleAlert(User $user, int $alertId): ControlRoomAlert
    {
        $query = ControlRoomAlert::query()->whereKey($alertId);
        $this->siteAccess()->applyAlertScope($query, $user, $this->alertBypassPermissions());

        return $query->firstOrFail();
    }

    private function resolveAccessibleStaff(User $user, int $staffUserId): User
    {
        return $this->accessibleStaffQuery($user)
            ->whereKey($staffUserId)
            ->firstOrFail();
    }

    private function resolveAccessibleCommunication(User $user, Communication $communication): Communication
    {
        $siteAccess = $this->siteAccess();
        $bypassPermissions = $this->alertBypassPermissions();

        return Communication::query()
            ->whereKey($communication->id)
            ->where(function (Builder $query) use ($user, $siteAccess, $bypassPermissions): void {
                $query->where(function (Builder $alertCommunicationQuery) use ($user, $siteAccess, $bypassPermissions): void {
                    $alertCommunicationQuery
                        ->whereNotNull('alert_id')
                        ->whereHas('alert', fn (Builder $alertQuery) => $siteAccess->applyAlertScope(
                            $alertQuery,
                            $user,
                            $bypassPermissions,
                        ));
                })->orWhere(function (Builder $directCommunicationQuery) use ($user, $siteAccess, $bypassPermissions): void {
                    $directCommunicationQuery
                        ->whereNull('alert_id')
                        ->whereNotNull('target_user_id')
                        ->whereHas('targetUser', function (Builder $targetUserQuery) use ($user, $siteAccess, $bypassPermissions): void {
                            $targetUserQuery->staff();
                            $siteAccess->applyStaffScope($targetUserQuery, $user, $bypassPermissions);
                        });
                });
            })
            ->firstOrFail();
    }
}
