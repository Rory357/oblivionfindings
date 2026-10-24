<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Jobs\Notifications\DeliverBroadcastCommunicationJob;
use App\Models\ControlRoom\Communication;
use App\Models\User;
use App\Notifications\ControlRoom\BroadcastRecipientNotice;
use App\Services\AuditLogger;
use App\Services\ControlRoom\BroadcastRecipientLedger;
use App\Services\UserSiteAccessService;
use App\Support\ControlRoom\BroadcastCommandInput;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ControlRoomBroadcastController extends Controller
{
    /**
     * A send with no delivery result this long after it was sent is treated as a
     * problem. The delivery job runs straight away and gives up within about a
     * minute and a half (3 tries, 30 s apart), so a send still unconfirmed after
     * 15 minutes will not confirm on its own: the queue stalled or the result was lost.
     */
    public const UNCONFIRMED_AFTER_MINUTES = 15;

    /** Delivery problems periods, in days. */
    public const PROBLEM_PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public const DEFAULT_PROBLEM_PERIOD = '7d';

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

        $readCounts = $this->readCountsByGroup(
            $broadcastGroups->getCollection()->pluck('broadcast_group_id')->filter()->values()->all(),
            $user,
            $siteAccess,
        );

        $broadcastData = $broadcastGroups->through(function ($group) use ($initiators, $readCounts) {
            $initiator = $initiators->get($group->initiated_by_user_id);
            $reads = $readCounts[(string) $group->broadcast_group_id] ?? ['read' => 0, 'of' => 0];

            return [
                'broadcast_group_id' => $group->broadcast_group_id,
                'content' => $group->content,
                'channels' => $group->channels_used ? explode(',', $group->channels_used) : [],
                'sent_at' => $group->sent_at,
                'template_used' => $group->template_used,
                'total_recipients' => (int) $group->total_recipients,
                'delivered_count' => (int) $group->delivered_count,
                'failed_count' => (int) $group->failed_count,
                // People who marked the in-app copy read, of the people it was sent to
                // in-app with read tracking (broadcasts before the ledger have none).
                'read_count' => $reads['read'],
                'read_of' => $reads['of'],
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
            'force_delivery' => ['nullable', 'boolean'],
        ]);

        $sendToAll = $validated['send_to_all'] ?? false;
        $targetRoles = $validated['target_roles'] ?? [];
        $forceDelivery = (bool) ($validated['force_delivery'] ?? false);

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
                    'force_delivery' => $forceDelivery,
                    'sent_at' => $now,
                    'initiated_by_user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Bulk insert in chunks and record the broadcast intent ledger in the same
        // transaction (in-app recipients read their message through it), then
        // dispatch a delivery job for each row. We query the freshly-inserted IDs
        // instead of relying on insertGetId() because bulk insert doesn't return
        // IDs reliably across DB drivers.
        DB::transaction(function () use ($records, $user, $broadcastGroupId, $validated, $channels, $targetRoles, $sendToAll, $forceDelivery, $targetUsers, $now): void {
            foreach (array_chunk($records, 500) as $chunk) {
                Communication::insert($chunk);
            }
            app(BroadcastRecipientLedger::class)->record($user, $broadcastGroupId, [
                'content' => $validated['content'],
                'channels' => $channels,
                'target_roles' => $targetRoles,
                'send_to_all' => $sendToAll,
                'template' => $validated['template'] ?? null,
                'force_delivery' => $forceDelivery,
            ], $targetUsers, $now);
        });

        Communication::query()
            ->where('broadcast_group_id', $broadcastGroupId)
            ->pluck('id')
            ->each(fn ($id) => DeliverBroadcastCommunicationJob::dispatch((int) $id));

        AuditLogger::log('controlRoom.broadcast.sent', null, [
            'broadcast_group_id' => $broadcastGroupId,
            'channels' => $channels,
            'target_roles' => $targetRoles,
            'send_to_all' => $sendToAll,
            'force_delivery' => $forceDelivery,
            'recipient_count' => $targetUsers->count(),
            'total_messages' => count($records),
        ]);

        return redirect()->route('control-room.broadcast.index')
            ->with('success', "Broadcast queued for {$targetUsers->count()} recipients via ".count($channels).' channel(s).');
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
        $reads = $this->inAppReads($communications);
        $readTracked = $communications->filter(fn (Communication $comm) => $this->readTracked($comm));

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
            // In-app reads: people who marked it read, of those sent it in-app with tracking.
            'read' => $readTracked->filter(fn (Communication $comm) => ($reads[(int) $comm->id] ?? null) !== null)->count(),
            'read_of' => $readTracked->count(),
        ];

        $recipients = $communications->map(fn (Communication $comm) => [
            'id' => $comm->id,
            'channel' => $comm->channel,
            'status' => $comm->status,
            'status_detail' => $comm->status_detail,
            'sent_at' => $comm->sent_at?->toISOString(),
            'delivered_at' => $comm->delivered_at?->toISOString(),
            // First time the recipient marked their in-app copy read (never moved later).
            'read_at' => ($reads[(int) $comm->id] ?? null)?->toISOString(),
            'read_tracked' => $this->readTracked($comm),
            'target_user' => $comm->targetUser ? [
                'id' => $comm->targetUser->id,
                'name' => $comm->targetUser->name,
                'email' => $comm->targetUser->email,
            ] : null,
        ])->all();

        return Inertia::render('control-room/broadcast-show', [
            'summary' => $summary,
            'recipients' => $recipients,
            'history' => $this->history($communications, $reads, $readTracked->count()),
        ]);
    }

    /**
     * Communications › Delivery problems — every broadcast send, to staff this
     * person can see, that failed or still has no result UNCONFIRMED_AFTER_MINUTES
     * after it was sent. Read-only: there is no resend endpoint for broadcasts.
     */
    public function problems(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();

        $channel = $this->queryChoice($request, 'channel', BroadcastCommandInput::CHANNELS);
        $outcome = $this->queryChoice($request, 'outcome', ['failed', 'unconfirmed']);
        $period = $this->queryChoice($request, 'period', array_keys(self::PROBLEM_PERIODS)) ?? self::DEFAULT_PROBLEM_PERIOD;

        $now = now();
        $since = $now->copy()->subDays(self::PROBLEM_PERIODS[$period]);
        $cutoff = $now->copy()->subMinutes(self::UNCONFIRMED_AFTER_MINUTES);

        $scoped = fn () => Communication::query()
            ->where('purpose', 'broadcast')
            ->whereNotNull('broadcast_group_id')
            ->whereHas('targetUser', fn ($targetUserQuery) => $siteAccess->applyStaffScope($targetUserQuery, $user, $bypassPermissions))
            ->where('sent_at', '>=', $since)
            ->when($channel, fn ($query, $value) => $query->where('channel', $value));
        $failed = fn ($query) => $query->where('status', 'failed');
        $unconfirmed = fn ($query) => $query->whereIn('status', ['pending', 'sent'])->where('sent_at', '<=', $cutoff);
        $either = fn ($query) => $query->where(fn ($any) => $any->where($failed)->orWhere($unconfirmed));

        // Header numbers count every problem for the channel and period, whatever "What happened" shows.
        $totals = $scoped()->where($either)
            ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count")
            ->selectRaw("COUNT(CASE WHEN status <> 'failed' THEN 1 END) as unconfirmed_count")
            ->selectRaw('COUNT(DISTINCT target_user_id) as people_count')
            ->toBase()
            ->first();
        $oldest = $scoped()->where($either)->orderBy('sent_at')->orderBy('id')
            ->first(['id', 'broadcast_group_id', 'content', 'sent_at']);

        $problems = $scoped()
            ->where(match ($outcome) {
                'failed' => $failed,
                'unconfirmed' => $unconfirmed,
                default => $either,
            })
            ->with([
                'targetUser:id,name',
                'targetUser.hrEmployeeProfile:id,user_id,primary_site_id',
                'targetUser.hrEmployeeProfile.primarySite:id,name',
            ])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // The same person's other channels for the same broadcast: did it reach them another way?
        $page = $problems->getCollection();
        $siblings = $page->isEmpty() ? collect() : Communication::query()
            ->where('purpose', 'broadcast')
            ->whereIn('broadcast_group_id', $page->pluck('broadcast_group_id')->unique()->values()->all())
            ->whereIn('target_user_id', $page->pluck('target_user_id')->filter()->unique()->values()->all())
            ->get(['id', 'broadcast_group_id', 'target_user_id', 'channel', 'status', 'broadcast_delivery_state', 'broadcast_outcome']);
        $siblingReads = $this->inAppReads($siblings);
        $byPerson = $siblings->groupBy(fn (Communication $comm) => $comm->broadcast_group_id.'|'.$comm->target_user_id);

        // A house name shows only when this person can see that Site.
        $allSites = $siteAccess->canBypass($user, $bypassPermissions);
        $siteIds = $allSites ? [] : array_map('intval', $siteAccess->accessibleSiteIds($user, $bypassPermissions));

        $data = $problems->through(function (Communication $comm) use ($byPerson, $siblingReads, $allSites, $siteIds) {
            $same = $byPerson->get($comm->broadcast_group_id.'|'.$comm->target_user_id, collect());
            $inApp = $same->first(fn (Communication $other) => $other->channel === 'in_app');
            $profile = $comm->targetUser?->hrEmployeeProfile;
            $siteId = $profile?->primary_site_id ? (int) $profile->primary_site_id : null;
            $isFailed = $comm->status === 'failed';

            return [
                'id' => (int) $comm->id,
                'broadcast_group_id' => (string) $comm->broadcast_group_id,
                'message' => (string) $comm->content,
                'channel' => $comm->channel,
                'outcome' => $isFailed ? 'failed' : 'unconfirmed',
                'reason' => $isFailed ? $this->reasonKey($comm->status_detail) : null,
                'detail' => $isFailed ? $comm->status_detail : null,
                'sent_at' => $comm->sent_at?->toISOString(),
                // When the failure was recorded (the row is not written again after it).
                'failed_at' => $isFailed ? $comm->updated_at?->toISOString() : null,
                'person' => $comm->targetUser ? [
                    'id' => (int) $comm->targetUser->id,
                    'name' => $comm->targetUser->name,
                    'site' => $siteId !== null && ($allSites || in_array($siteId, $siteIds, true))
                        ? $profile->primarySite?->name
                        : null,
                ] : null,
                'reached_by' => $same
                    ->filter(fn (Communication $other) => (int) $other->id !== (int) $comm->id && $other->status === 'delivered')
                    ->pluck('channel')->unique()->values()->all(),
                'read_at' => $inApp ? ($siblingReads[(int) $inApp->id] ?? null)?->toISOString() : null,
            ];
        });

        return Inertia::render('control-room/delivery-problems', [
            'problems' => $data,
            'stats' => [
                'failed' => (int) ($totals->failed_count ?? 0),
                'unconfirmed' => (int) ($totals->unconfirmed_count ?? 0),
                'people' => (int) ($totals->people_count ?? 0),
                'oldest' => $oldest ? [
                    'broadcast_group_id' => (string) $oldest->broadcast_group_id,
                    'message' => Str::limit((string) $oldest->content, 120),
                    'sent_at' => $oldest->sent_at?->toISOString(),
                ] : null,
            ],
            'filters' => [
                'channel' => $channel,
                'outcome' => $outcome,
                'period' => $period,
            ],
            'thresholdMinutes' => self::UNCONFIRMED_AFTER_MINUTES,
            'can' => [
                'viewStaff' => $user->canDo('staff.viewAny'),
            ],
        ]);
    }

    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    /** One allowed value from the query string, or null (anything else is ignored, never an error). */
    private function queryChoice(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** An in-app row sent through the broadcast ledger, so its read is recorded. */
    private function readTracked(Communication $comm): bool
    {
        return $comm->channel === 'in_app' && $comm->broadcast_delivery_state !== null;
    }

    /**
     * When each in-app row's recipient first marked their inbox copy read.
     *
     * The copy is the BroadcastRecipientNotice the availability service linked on
     * the row (broadcast_outcome.recipient_notification_id), and must still belong
     * to the row's recipient and point back at the row. Its read_at is set only by
     * the broadcast read command, which never moves a first read.
     *
     * @param  Collection<int, Communication>  $rows
     * @return array<int, CarbonInterface|null> keyed by Communication id; rows without a copy are absent
     */
    private function inAppReads(Collection $rows): array
    {
        $links = [];
        foreach ($rows as $row) {
            $outcome = is_array($row->broadcast_outcome) ? $row->broadcast_outcome : [];
            $noticeId = $outcome['recipient_notification_id'] ?? null;
            if ($row->channel === 'in_app' && ($outcome['availability'] ?? null) === 'available'
                && BroadcastCommandInput::canonicalUuid($noticeId)) {
                $links[$noticeId] = $row;
            }
        }
        if ($links === []) {
            return [];
        }

        $reads = [];
        $notices = DatabaseNotification::query()
            ->whereIn('id', array_keys($links))
            ->where('type', BroadcastRecipientNotice::class)
            ->where('notifiable_type', (new User)->getMorphClass())
            ->get(['id', 'notifiable_id', 'data', 'read_at']);
        foreach ($notices as $notice) {
            $row = $links[(string) $notice->getKey()] ?? null;
            $data = is_array($notice->data) ? $notice->data : [];
            if (! $row || (int) $notice->notifiable_id !== (int) $row->target_user_id
                || (int) ($data['communication_id'] ?? 0) !== (int) $row->id) {
                continue;
            }
            $reads[(int) $row->id] = $notice->read_at;
        }

        return $reads;
    }

    /**
     * Read counts for a page of broadcasts, over the recipients this person can see.
     *
     * @return array<string, array{read:int, of:int}>
     */
    private function readCountsByGroup(array $groupIds, User $user, UserSiteAccessService $siteAccess): array
    {
        if ($groupIds === []) {
            return [];
        }
        $rows = Communication::query()
            ->where('purpose', 'broadcast')
            ->where('channel', 'in_app')
            ->whereNotNull('broadcast_delivery_state')
            ->whereIn('broadcast_group_id', $groupIds)
            ->whereHas('targetUser', fn ($targetUserQuery) => $siteAccess->applyStaffScope($targetUserQuery, $user, $this->alertBypassPermissions()))
            ->get(['id', 'broadcast_group_id', 'target_user_id', 'channel', 'broadcast_delivery_state', 'broadcast_outcome']);
        $reads = $this->inAppReads($rows);

        $counts = [];
        foreach ($rows as $row) {
            $group = (string) $row->broadcast_group_id;
            $counts[$group] ??= ['read' => 0, 'of' => 0];
            $counts[$group]['of']++;
            if (($reads[(int) $row->id] ?? null) !== null) {
                $counts[$group]['read']++;
            }
        }

        return $counts;
    }

    /**
     * What happened to one broadcast, from what is recorded: who sent it, each
     * channel's confirmed, failed and skipped results (with when the result was
     * recorded), in-app reads, and sends still waiting for a result. Retries and
     * acknowledgements are not recorded for broadcasts, so they never appear.
     *
     * @param  Collection<int, Communication>  $communications  rows the viewer can see
     * @param  array<int, CarbonInterface|null>  $reads
     */
    private function history(Collection $communications, array $reads, int $readOf): array
    {
        $iso = fn (?CarbonInterface $at) => $at?->toISOString();
        $events = [];

        $first = $communications->filter(fn (Communication $comm) => $comm->sent_at !== null)
            ->sortBy(fn (Communication $comm) => $comm->sent_at->getTimestamp())->first();
        $senderId = $first?->initiated_by_user_id ?? $communications->first()?->initiated_by_user_id;
        $sender = $senderId ? User::query()->select('id', 'name')->find($senderId) : null;
        $events[] = [
            'kind' => 'sent',
            'at' => $iso($first?->sent_at),
            'by' => $sender?->name,
            'channels' => $communications->pluck('channel')->unique()->values()->all(),
            'people' => $communications->pluck('target_user_id')->filter()->unique()->count(),
            'force_delivery' => $communications->contains(fn (Communication $comm) => (bool) $comm->force_delivery),
        ];

        foreach ($communications->groupBy('channel') as $channel => $rows) {
            foreach (['delivered', 'failed', 'skipped'] as $status) {
                $matching = $rows->filter(fn (Communication $comm) => $comm->status === $status);
                if ($matching->isEmpty()) {
                    continue;
                }
                $times = $matching
                    ->map(fn (Communication $comm) => $status === 'delivered' ? ($comm->delivered_at ?? $comm->updated_at) : $comm->updated_at)
                    ->filter()
                    ->sortBy(fn (CarbonInterface $at) => $at->getTimestamp())
                    ->values();
                $event = [
                    'kind' => $status,
                    'channel' => (string) $channel,
                    'count' => $matching->count(),
                    'at' => $iso($times->first()),
                    'last_at' => $iso($times->last()),
                ];
                if ($status !== 'delivered') {
                    $event['reasons'] = $matching
                        ->groupBy(fn (Communication $comm) => $this->reasonKey($comm->status_detail))
                        ->map(fn (Collection $group, string $reason) => [
                            'reason' => $reason,
                            'count' => $group->count(),
                            'detail' => $reason === 'other' ? $group->first()->status_detail : null,
                        ])
                        ->values()
                        ->all();
                }
                $events[] = $event;
            }
        }

        $readRows = $communications
            ->filter(fn (Communication $comm) => ($reads[(int) $comm->id] ?? null) !== null)
            ->sortBy(fn (Communication $comm) => $reads[(int) $comm->id]->getTimestamp())
            ->values();
        if ($readRows->isNotEmpty()) {
            $events[] = [
                'kind' => 'read',
                'count' => $readRows->count(),
                'of' => $readOf,
                'at' => $iso($reads[(int) $readRows->first()->id]),
                'last_at' => $iso($reads[(int) $readRows->last()->id]),
                'first_reader' => $readRows->first()->targetUser?->name,
            ];
        }

        usort($events, fn (array $a, array $b) => ($a['kind'] === 'sent' ? 0 : 1) <=> ($b['kind'] === 'sent' ? 0 : 1)
            ?: strcmp((string) $a['at'], (string) $b['at']));

        // Still waiting goes last: it is where the broadcast stands now, not a recorded event.
        foreach ($communications->groupBy('channel') as $channel => $rows) {
            $waiting = $rows->filter(fn (Communication $comm) => in_array($comm->status, ['pending', 'sent'], true));
            if ($waiting->isEmpty()) {
                continue;
            }
            $events[] = [
                'kind' => 'waiting',
                'channel' => (string) $channel,
                'count' => $waiting->count(),
                'at' => $iso($waiting->map(fn (Communication $comm) => $comm->sent_at)->filter()
                    ->sortBy(fn (CarbonInterface $at) => $at->getTimestamp())->first()),
            ];
        }

        return $events;
    }

    /** Plain reason keys for the delivery job's recorded failure and skip messages. */
    private function reasonKey(?string $detail): string
    {
        $detail = (string) $detail;

        return match (true) {
            str_starts_with($detail, 'No phone number') => 'no_phone',
            str_starts_with($detail, 'No email address') => 'no_email',
            str_starts_with($detail, 'No push token') => 'no_device',
            str_starts_with($detail, 'SMS provider is not configured'),
            str_starts_with($detail, 'Unsupported SMS provider') => 'sms_not_set_up',
            str_starts_with($detail, 'Push provider is not configured'),
            str_starts_with($detail, 'Unsupported push provider') => 'push_not_set_up',
            str_starts_with($detail, 'User has this channel disabled') => 'turned_off',
            str_starts_with($detail, 'Recipient is no longer eligible') => 'not_eligible',
            default => 'other',
        };
    }
}
