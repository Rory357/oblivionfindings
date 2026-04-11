<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Communication;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ControlRoomBroadcastController extends Controller
{
    /**
     * List broadcast communications grouped by broadcast_group_id.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        $broadcastGroups = Communication::query()
            ->where('purpose', 'broadcast')
            ->whereNotNull('broadcast_group_id')
            ->whereHas('targetUser', fn ($targetUserQuery) => $siteAccess->applyStaffScope($targetUserQuery, $user, $bypassPermissions))
            ->selectRaw('
                broadcast_group_id,
                MIN(content) as content,
                MIN(sent_at) as sent_at,
                MIN(template_used) as template_used,
                MIN(initiated_by_user_id) as initiated_by_user_id,
                COUNT(*) as total_recipients,
                COUNT(CASE WHEN status = \'delivered\' THEN 1 END) as delivered_count,
                COUNT(CASE WHEN status = \'failed\' THEN 1 END) as failed_count,
                GROUP_CONCAT(DISTINCT channel) as channels_used
            ')
            ->groupBy('broadcast_group_id')
            ->orderByDesc('sent_at')
            ->paginate(20);

        $initiatorIds = $broadcastGroups->pluck('initiated_by_user_id')->filter()->unique();
        $initiators = User::whereIn('id', $initiatorIds)
            ->select('id', 'name')
            ->get()
            ->keyBy('id');

        $broadcastData = $broadcastGroups->through(function ($group) use ($initiators) {
            $initiator = $initiators->get($group->initiated_by_user_id);

            return [
                'broadcast_group_id' => $group->broadcast_group_id,
                'content' => $group->content,
                'channels' => $group->channels_used ? explode(',', $group->channels_used) : [],
                'sent_at' => $group->sent_at,
                'template_used' => $group->template_used,
                'total_recipients' => (int) $group->total_recipients,
                'delivered_count' => (int) $group->delivered_count,
                'failed_count' => (int) $group->failed_count,
                'initiated_by' => $initiator ? [
                    'id' => $initiator->id,
                    'name' => $initiator->name,
                ] : null,
            ];
        });

        // Get available roles for the compose form
        $roles = ['admin', 'coordinator', 'support_worker', 'shift_lead', 'nurse'];

        // Get estimated user counts per role
        $roleCounts = [];
        foreach ($roles as $role) {
            $roleCounts[$role] = User::staff()
                ->tap(fn ($staffQuery) => $siteAccess->applyStaffScope($staffQuery, $user, $bypassPermissions))
                ->whereHas('roles', fn ($q) => $q->where('name', $role))
                ->count();
        }

        $totalStaff = User::staff()
            ->tap(fn ($staffQuery) => $siteAccess->applyStaffScope($staffQuery, $user, $bypassPermissions))
            ->count();

        return Inertia::render('control-room/broadcast', [
            'broadcasts' => $broadcastData,
            'roles' => $roles,
            'roleCounts' => $roleCounts,
            'totalStaff' => $totalStaff,
            'can' => [
                'manage' => $user->canDo('controlRoom.alerts.manage'),
            ],
        ]);
    }

    /**
     * Create a broadcast message - sends to selected users via selected channels.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['in_app', 'push', 'sms', 'email'])],
            'target_roles' => ['nullable', 'array'],
            'target_roles.*' => ['string', Rule::in(['admin', 'coordinator', 'support_worker', 'shift_lead', 'nurse'])],
            'send_to_all' => ['nullable', 'boolean'],
            'template' => ['nullable', 'string', 'max:255'],
        ]);

        $sendToAll = $validated['send_to_all'] ?? false;
        $targetRoles = $validated['target_roles'] ?? [];

        // Resolve target users
        $usersQuery = User::staff();
        app(UserSiteAccessService::class)->applyStaffScope($usersQuery, $user, $this->alertBypassPermissions());

        if (! $sendToAll && ! empty($targetRoles)) {
            $usersQuery->whereHas('roles', fn ($q) => $q->whereIn('name', $targetRoles));
        }

        $targetUsers = $usersQuery->select('id', 'name', 'email', 'cellphone', 'work_phone')->get();

        if ($targetUsers->isEmpty()) {
            return redirect()->route('control-room.broadcast.index')
                ->with('error', 'No recipients found for the selected criteria.');
        }

        $broadcastGroupId = Str::uuid()->toString();
        $now = now();
        $channels = $validated['channels'];

        // Create a Communication record per user per channel
        $records = [];
        foreach ($targetUsers as $targetUser) {
            foreach ($channels as $channel) {
                $records[] = [
                    'broadcast_group_id' => $broadcastGroupId,
                    'alert_id' => null,
                    'channel' => $channel,
                    'direction' => 'outbound',
                    'purpose' => 'broadcast',
                    'target_user_id' => $targetUser->id,
                    'target_email' => $channel === 'email' ? $targetUser->email : null,
                    'target_phone' => $channel === 'sms'
                        ? ($targetUser->cellphone ?: $targetUser->work_phone)
                        : null,
                    'content' => $validated['content'],
                    'template_used' => $validated['template'] ?? null,
                    'status' => 'pending',
                    'sent_at' => $now,
                    'initiated_by_user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Bulk insert in chunks
        foreach (array_chunk($records, 500) as $chunk) {
            Communication::insert($chunk);
        }

        AuditLogger::log('controlRoom.broadcast.sent', null, [
            'broadcast_group_id' => $broadcastGroupId,
            'channels' => $channels,
            'target_roles' => $targetRoles,
            'send_to_all' => $sendToAll,
            'recipient_count' => $targetUsers->count(),
            'total_messages' => count($records),
        ]);

        return redirect()->route('control-room.broadcast.index')
            ->with('success', "Broadcast sent to {$targetUsers->count()} recipients via ".count($channels).' channel(s).');
    }

    /**
     * Show all Communication records for a specific broadcast group.
     */
    public function show(Request $request, string $groupId)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);

        $communications = Communication::query()
            ->where('purpose', 'broadcast')
            ->where('broadcast_group_id', $groupId)
            ->whereHas('targetUser', fn ($targetUserQuery) => $siteAccess->applyStaffScope($targetUserQuery, $user, $this->alertBypassPermissions()))
            ->with('targetUser:id,name,email')
            ->orderBy('channel')
            ->orderBy('status')
            ->get();

        if ($communications->isEmpty()) {
            abort(404);
        }

        $first = $communications->first();

        $summary = [
            'broadcast_group_id' => $groupId,
            'content' => $first->content,
            'template_used' => $first->template_used,
            'sent_at' => $first->sent_at?->toISOString(),
            'channels' => $communications->pluck('channel')->unique()->values()->all(),
            'total' => $communications->count(),
            'delivered' => $communications->where('status', 'delivered')->count(),
            'sent' => $communications->where('status', 'sent')->count(),
            'pending' => $communications->where('status', 'pending')->count(),
            'failed' => $communications->where('status', 'failed')->count(),
        ];

        $recipients = $communications->map(fn (Communication $comm) => [
            'id' => $comm->id,
            'channel' => $comm->channel,
            'status' => $comm->status,
            'status_detail' => $comm->status_detail,
            'sent_at' => $comm->sent_at?->toISOString(),
            'delivered_at' => $comm->delivered_at?->toISOString(),
            'target_user' => $comm->targetUser ? [
                'id' => $comm->targetUser->id,
                'name' => $comm->targetUser->name,
                'email' => $comm->targetUser->email,
            ] : null,
        ])->all();

        return Inertia::render('control-room/broadcast-show', [
            'summary' => $summary,
            'recipients' => $recipients,
        ]);
    }

    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
