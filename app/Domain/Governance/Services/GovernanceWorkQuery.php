<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Data\GovernanceWorkItem;
use App\Domain\Governance\Enums\GovernanceArea;
use App\Domain\Governance\Enums\GovernanceWorkKind;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GovernanceWorkQuery
{
    public function __construct(
        protected GovernanceRecordAccessService $recordAccess,
        protected BoardPackAccessService $boardPackAccess,
        protected GovernanceVotingProfileService $votingProfileService,
    ) {}

    /**
     * Query full personal obligations for the given viewer.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     pagination: array{total: int, per_page: int, current_page: int, last_page: int},
     *     scope: array<string, mixed>,
     *     availability: array<string, string>,
     *     generated_at: string
     * }
     */
    public function queryFeed(User|int $viewer, array $filters = [], int $perPage = 25, int $page = 1): array
    {
        if (is_int($viewer)) {
            $viewer = User::findOrFail($viewer);
        }

        $availability = [
            'resolutions' => 'available',
            'board_packs' => 'available',
            'action_items' => 'available',
            'policies' => 'available',
            'meetings' => 'available',
        ];

        $voteItems = $this->queryVoteItems($viewer, $availability);
        $readItems = $this->queryReadItems($viewer, $availability);
        $actItems = $this->queryActItems($viewer, $availability);
        $knowItems = $this->queryKnowItems($viewer, $availability);

        /** @var Collection<int, GovernanceWorkItem> $allItems */
        $allItems = collect()
            ->merge($voteItems)
            ->merge($readItems)
            ->merge($actItems)
            ->merge($knowItems);

        // Sort deterministically: urgency -> due date -> priority -> stable ID
        $sortedItems = $allItems->sort(fn (GovernanceWorkItem $a, GovernanceWorkItem $b) => $this->compareWorkItems($a, $b))->values();

        $completedItems = $this->queryCompletedItems($viewer, $availability);

        // Calculate full authorised totals BEFORE pagination or display limits
        $totals = [
            'all' => $sortedItems->count(),
            'vote' => $sortedItems->where('kind', GovernanceWorkKind::Vote->value)->count(),
            'read' => $sortedItems->where('kind', GovernanceWorkKind::Read->value)->count(),
            'act' => $sortedItems->where('kind', GovernanceWorkKind::Act->value)->count(),
            'know' => $sortedItems->where('kind', GovernanceWorkKind::Know->value)->count(),
            'pending' => $sortedItems->whereIn('status', ['pending', 'due_soon', 'overdue', 'blocked'])->count(),
            'overdue' => $sortedItems->where('status', 'overdue')->count(),
            'blocked' => $sortedItems->where('status', 'blocked')->count(),
            'completed' => $completedItems->count(),
        ];

        // Apply filters
        $statusFilter = $filters['status'] ?? 'pending';

        if ($statusFilter === 'completed') {
            $filteredItems = $completedItems;
        } elseif ($statusFilter === 'all') {
            $filteredItems = $sortedItems->concat($completedItems)->sort(fn (GovernanceWorkItem $a, GovernanceWorkItem $b) => $this->compareWorkItems($a, $b))->values();
        } else {
            $filteredItems = $sortedItems;
            if ($statusFilter === 'pending') {
                $filteredItems = $filteredItems->whereIn('status', ['pending', 'due_soon', 'overdue', 'blocked']);
            } elseif ($statusFilter === 'overdue') {
                $filteredItems = $filteredItems->where('status', 'overdue');
            } elseif ($statusFilter === 'blocked') {
                $filteredItems = $filteredItems->where('status', 'blocked');
            }
        }

        $kindFilter = $filters['kind'] ?? 'all';
        if ($kindFilter !== 'all' && in_array($kindFilter, ['vote', 'read', 'act', 'know'], true)) {
            $filteredItems = $filteredItems->where('kind', $kindFilter);
        }

        $dueFilter = $filters['due'] ?? 'all';
        if ($dueFilter === 'overdue') {
            $filteredItems = $filteredItems->where('status', 'overdue');
        } elseif ($dueFilter === 'next7') {
            $filteredItems = $filteredItems->filter(function (GovernanceWorkItem $item) {
                if (! $item->dueDate) {
                    return false;
                }
                $due = Carbon::parse($item->dueDate);
                return $due->isFuture() && now()->diffInDays($due, false) <= 7;
            });
        }

        if (! empty($filters['search'])) {
            $search = strtolower(trim((string) $filters['search']));
            $filteredItems = $filteredItems->filter(function (GovernanceWorkItem $item) use ($search) {
                return str_contains(strtolower($item->title), $search)
                    || str_contains(strtolower($item->reason), $search)
                    || str_contains(strtolower($item->source['reference'] ?? ''), $search);
            });
        }

        $totalFiltered = $filteredItems->count();
        $lastPage = (int) max(1, ceil($totalFiltered / $perPage));
        $currentPage = max(1, min($page, $lastPage));

        $pagedSlice = $filteredItems->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return [
            'items' => $pagedSlice->map(fn (GovernanceWorkItem $item) => $item->toArray())->all(),
            'totals' => $totals,
            'pagination' => [
                'total' => $totalFiltered,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
            ],
            'scope' => [
                'viewer' => [
                    'user_id' => $viewer->id,
                    'name' => $viewer->name,
                    'board_member_id' => $viewer->boardMember?->id,
                ],
                'filters' => $filters,
            ],
            'availability' => $availability,
            'all_sources_succeeded' => ! in_array('unavailable', $availability, true),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Derive canonical Vote obligations.
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryVoteItems(User $viewer, array &$availability): Collection
    {
        if (! Schema::hasTable('resolutions')) {
            return collect();
        }

        try {
            $query = Resolution::query()->where('status', 'open');
            $this->recordAccess->scopeResolutions($query, $viewer);
            $resolutions = $query->get();

            $boardMember = $viewer->boardMember;
            $items = collect();

            foreach ($resolutions as $resolution) {
                // If viewer is a board member, check if they can vote and haven't yet
                $canVote = false;
                $votedOrConflict = false;

                if ($boardMember && $boardMember->canVote()) {
                    // Check electorate
                    $canVote = $this->votingProfileService->isMemberInElectorate($boardMember, $resolution);

                    if ($canVote) {
                        $hasVoted = $resolution->votes()->where('board_member_id', $boardMember->id)->exists();
                        $hasConflict = $resolution->conflictDeclarations()->where('board_member_id', $boardMember->id)->exists();
                        $votedOrConflict = $hasVoted || $hasConflict;
                    }
                } elseif ($viewer->can('vote', $resolution)) {
                    $canVote = true;
                }

                // If user already voted/recused or cannot vote, this is not a pending personal vote obligation
                if (! $canVote || $votedOrConflict) {
                    continue;
                }

                // Determine due status and priority
                $deadline = $resolution->deadline;
                $isOverdue = $deadline !== null && $deadline->isPast();
                $isDueSoon = $deadline !== null && ! $isOverdue && now()->diffInDays($deadline, false) <= 7;
                $isMissingDeadline = $deadline === null;

                $status = match (true) {
                    $isOverdue => 'overdue',
                    $isDueSoon => 'due_soon',
                    default => 'pending',
                };

                $priority = match (true) {
                    $isOverdue => 'critical',
                    $isDueSoon || $isMissingDeadline => 'high',
                    default => 'medium',
                };

                $reason = match (true) {
                    $isMissingDeadline => 'Legacy resolution has missing voting deadline; prompt governance manager review.',
                    $isOverdue => "Voting deadline expired on {$deadline->timezone('Pacific/Auckland')->format('j M Y, g:i A')}.",
                    default => "Voting open until {$deadline->timezone('Pacific/Auckland')->format('j M Y, g:i A')}.",
                };

                $items->push(new GovernanceWorkItem(
                    id: "resolution:{$resolution->id}:vote",
                    kind: GovernanceWorkKind::Vote,
                    source: [
                        'type' => 'resolution',
                        'id' => $resolution->id,
                        'reference' => $resolution->resolution_reference ?? "RES-{$resolution->id}",
                        'href' => "/governance/resolutions/{$resolution->id}",
                    ],
                    title: "Vote: {$resolution->title}",
                    reason: $reason,
                    priority: $priority,
                    status: $status,
                    dueAt: $deadline?->toIso8601String(),
                    dueDate: $deadline?->toDateString(),
                    assigneeUserId: $viewer->id,
                    boardMemberId: $boardMember?->id,
                    requiredAction: [
                        'key' => 'vote',
                        'label' => 'Cast Vote',
                        'href' => "/governance/resolutions/{$resolution->id}",
                        'allowed' => true,
                        'blocked_reason' => null,
                    ],
                    sourceVersion: 1,
                    area: GovernanceArea::Resolutions,
                    ownerName: $viewer->name,
                ));
            }

            return $items;
        } catch (\Throwable $e) {
            $availability['resolutions'] = 'unavailable';
            return collect();
        }
    }

    /**
     * Derive canonical Read obligations (board packs & policy attestations).
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryReadItems(User $viewer, array &$availability): Collection
    {
        $items = collect();
        $boardMember = $viewer->boardMember;

        // 1. Current distributed Board Packs
        if (Schema::hasTable('board_packs') && Schema::hasTable('governance_meetings')) {
            try {
                $packsQuery = BoardPack::query()
                    ->where('is_current', true)
                    ->whereNotNull('distributed_at')
                    ->with('meeting');

                $packs = $packsQuery->get();

                foreach ($packs as $pack) {
                    if (! $this->boardPackAccess->canView($viewer, $pack)) {
                        continue;
                    }

                    // Check if viewer has already acknowledged read on this revision
                    $hasRead = $boardMember
                        ? $pack->hasMemberRead($boardMember->id)
                        : (is_array($pack->read_tracking) && collect($pack->read_tracking)->contains('user_id', $viewer->id));

                    if ($hasRead) {
                        continue;
                    }

                    $meeting = $pack->meeting;
                    $meetingTime = $meeting?->scheduled_at;
                    $isOverdue = $meetingTime !== null && $meetingTime->isPast();
                    $isDueSoon = $meetingTime !== null && ! $isOverdue && now()->diffInDays($meetingTime, false) <= 3;

                    $status = match (true) {
                        $isOverdue => 'overdue',
                        $isDueSoon => 'due_soon',
                        default => 'pending',
                    };

                    $priority = match (true) {
                        $isOverdue => 'critical',
                        $isDueSoon => 'high',
                        default => 'medium',
                    };

                    $revNumber = $pack->revision_number ?? 1;

                    $items->push(new GovernanceWorkItem(
                        id: "board_pack:{$pack->id}:read",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'board_pack',
                            'id' => $pack->id,
                            'reference' => "Pack Rev {$revNumber}",
                            'href' => "/governance/packs/{$pack->id}",
                        ],
                        title: "Read Board Pack (Rev {$revNumber}) — " . ($meeting?->title ?? 'Upcoming Meeting'),
                        reason: "Board pack distributed on {$pack->distributed_at?->timezone('Pacific/Auckland')->format('j M Y')}; explicit reading acknowledgement required.",
                        priority: $priority,
                        status: $status,
                        dueAt: $meetingTime?->toIso8601String(),
                        dueDate: $meetingTime?->toDateString(),
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Read Pack',
                            'href' => "/governance/packs/{$pack->id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: $revNumber,
                        area: GovernanceArea::Meetings,
                        ownerName: $viewer->name,
                    ));
                }
            } catch (\Throwable $e) {
                $availability['board_packs'] = 'unavailable';
            }
        }

        // 2. Policy Attestations
        if (Schema::hasTable('policy_attestations') && Schema::hasTable('governance_policies')) {
            try {
                $attestations = DB::table('policy_attestations')
                    ->join('governance_policies', 'policy_attestations.governance_policy_id', '=', 'governance_policies.id')
                    ->where('policy_attestations.user_id', $viewer->id)
                    ->whereNull('policy_attestations.acknowledged_at')
                    ->select(
                        'policy_attestations.id as attestation_id',
                        'policy_attestations.governance_policy_id as policy_id',
                        'policy_attestations.due_date',
                        'governance_policies.title as policy_title',
                        'governance_policies.version_number as policy_version'
                    )
                    ->get();

                foreach ($attestations as $attestation) {
                    $due = $attestation->due_date ? Carbon::parse($attestation->due_date) : null;
                    $isOverdue = $due !== null && $due->isPast();
                    $isDueSoon = $due !== null && ! $isOverdue && now()->diffInDays($due, false) <= 7;

                    $items->push(new GovernanceWorkItem(
                        id: "policy:{$attestation->attestation_id}:read",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'policy',
                            'id' => (int) $attestation->policy_id,
                            'reference' => "Policy #{$attestation->policy_id}",
                            'href' => "/governance/policies/{$attestation->policy_id}",
                        ],
                        title: "Attest Policy: {$attestation->policy_title}",
                        reason: 'Governance policy review and formal attestation required.',
                        priority: $isOverdue ? 'critical' : ($isDueSoon ? 'high' : 'medium'),
                        status: $isOverdue ? 'overdue' : ($isDueSoon ? 'due_soon' : 'pending'),
                        dueAt: $due?->toIso8601String(),
                        dueDate: $due?->toDateString(),
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Attest Policy',
                            'href' => "/governance/policies/{$attestation->policy_id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: (int) ($attestation->policy_version ?? 1),
                        area: GovernanceArea::Policies,
                        ownerName: $viewer->name,
                    ));
                }
            } catch (\Throwable $e) {
                $availability['policies'] = 'unavailable';
            }
        }

        return $items;
    }

    /**
     * Derive canonical Act obligations (ActionItems assigned directly to viewer ID).
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryActItems(User $viewer, array &$availability): Collection
    {
        if (! Schema::hasTable('action_items')) {
            return collect();
        }

        try {
            // Strictly enforce viewer identity server-side via assigned_to = viewer->id (NEVER display names)
            $query = ActionItem::query()
                ->where('assigned_to', $viewer->id)
                ->whereIn('status', ['open', 'in_progress', 'blocked']);

            // Full list without artificial limits (e.g. all 40 fixture actions), scoped to records the viewer can access
            $actionItems = $query->get()->filter(function (ActionItem $item) use ($viewer) {
                return $this->recordAccess->canViewActionItem($viewer, $item);
            });

            return $actionItems->map(function (ActionItem $item) use ($viewer): GovernanceWorkItem {
                $isBlocked = $item->status === 'blocked';
                $isOverdue = ! $isBlocked && $item->due_date !== null && $item->due_date->isPast();
                $isDueSoon = ! $isBlocked && ! $isOverdue && $item->due_date !== null && now()->diffInDays($item->due_date, false) <= 7;

                $status = match (true) {
                    $isBlocked => 'blocked',
                    $isOverdue => 'overdue',
                    $isDueSoon => 'due_soon',
                    default => 'pending',
                };

                $priority = match (true) {
                    $isBlocked => 'high',
                    $isOverdue && in_array($item->priority, ['critical', 'high'], true) => 'critical',
                    $isOverdue => 'high',
                    $item->priority === 'critical' => 'critical',
                    $item->priority === 'high' => 'high',
                    default => 'medium',
                };

                $reason = match (true) {
                    $isBlocked => $item->blocked_reason ?? 'Action item progress is currently blocked.',
                    $isOverdue => "Action item was due on {$item->due_date?->format('j M Y')}.",
                    $item->due_date !== null => "Action item due on {$item->due_date->format('j M Y')}.",
                    default => 'Action item pending completion.',
                };

                return new GovernanceWorkItem(
                    id: "action_item:{$item->id}:act",
                    kind: GovernanceWorkKind::Act,
                    source: [
                        'type' => 'action_item',
                        'id' => $item->id,
                        'reference' => $item->action_reference ?? "ACT-{$item->id}",
                        'href' => "/governance/actions/{$item->id}",
                    ],
                    title: ($item->action_reference ? "{$item->action_reference}: " : '') . Str::limit($item->description, 80),
                    reason: $reason,
                    priority: $priority,
                    status: $status,
                    dueAt: $item->due_date?->toIso8601String(),
                    dueDate: $item->due_date?->toDateString(),
                    assigneeUserId: $viewer->id,
                    boardMemberId: $viewer->boardMember?->id,
                    requiredAction: [
                        'key' => 'act',
                        'label' => 'Open Action',
                        'href' => "/governance/actions/{$item->id}",
                        'allowed' => true,
                        'blocked_reason' => $isBlocked ? ($item->blocked_reason ?? 'Action is blocked') : null,
                    ],
                    sourceVersion: 1,
                    area: GovernanceArea::ActionItems,
                    ownerName: $viewer->name,
                );
            });
        } catch (\Throwable $e) {
            $availability['action_items'] = 'unavailable';
            return collect();
        }
    }

    /**
     * Derive canonical Know obligations (informational updates without false completion requirement).
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryKnowItems(User $viewer, array &$availability): Collection
    {
        if (! Schema::hasTable('governance_meetings')) {
            return collect();
        }

        try {
            $meetingQuery = GovernanceMeeting::query()
                ->where('scheduled_at', '>=', now())
                ->whereNotIn('status', ['cancelled', 'archived'])
                ->orderBy('scheduled_at')
                ->limit(3);

            $this->recordAccess->scopeMeetings($meetingQuery, $viewer);
            $meetings = $meetingQuery->get();

            return $meetings->map(function (GovernanceMeeting $meeting) use ($viewer): GovernanceWorkItem {
                $scheduled = $meeting->scheduled_at;
                $days = $scheduled ? (int) now()->startOfDay()->diffInDays($scheduled->copy()->startOfDay(), false) : null;

                return new GovernanceWorkItem(
                    id: "meeting:{$meeting->id}:know",
                    kind: GovernanceWorkKind::Know,
                    source: [
                        'type' => 'governance_meeting',
                        'id' => $meeting->id,
                        'reference' => "Meeting #{$meeting->id}",
                        'href' => "/governance/meetings/{$meeting->id}",
                    ],
                    title: "Upcoming: {$meeting->title}",
                    reason: $days !== null
                        ? ($days === 0 ? 'Scheduled for today.' : "Scheduled in {$days} day(s).")
                        : 'Meeting scheduled on board calendar.',
                    priority: 'medium',
                    status: 'pending',
                    dueAt: $scheduled?->toIso8601String(),
                    dueDate: $scheduled?->toDateString(),
                    assigneeUserId: $viewer->id,
                    boardMemberId: $viewer->boardMember?->id,
                    requiredAction: [
                        'key' => 'know',
                        'label' => 'View Meeting',
                        'href' => "/governance/meetings/{$meeting->id}",
                        'allowed' => true,
                        'blocked_reason' => null,
                    ],
                    sourceVersion: 1,
                    area: GovernanceArea::Meetings,
                    ownerName: $viewer->name,
                );
            });
        } catch (\Throwable $e) {
            $availability['meetings'] = 'unavailable';
            return collect();
        }
    }

    /**
     * Query completed items and durable receipts for the viewer.
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryCompletedItems(User $viewer, array &$availability): Collection
    {
        $items = collect();
        $boardMember = $viewer->boardMember;

        // 1. Completed Action Items
        if (Schema::hasTable('action_items')) {
            try {
                $actions = ActionItem::query()
                    ->where('assigned_to', $viewer->id)
                    ->whereIn('status', ['complete', 'completed'])
                    ->orderByDesc('completed_at')
                    ->limit(50)
                    ->get();

                foreach ($actions as $action) {
                    $items->push(new GovernanceWorkItem(
                        id: "action:{$action->id}:completed",
                        kind: GovernanceWorkKind::Act,
                        source: [
                            'type' => 'action_item',
                            'id' => $action->id,
                            'reference' => $action->action_reference ?? "ACT-{$action->id}",
                            'href' => "/governance/actions/{$action->id}",
                        ],
                        title: ($action->action_reference ? "{$action->action_reference}: " : '') . Str::limit($action->description ?? 'Action Item', 80),
                        reason: 'Action item completed.',
                        priority: $action->priority ?? 'medium',
                        status: 'completed',
                        dueAt: $action->due_date?->toIso8601String(),
                        dueDate: $action->due_date?->toDateString(),
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'act',
                            'label' => 'View Completed Action',
                            'href' => "/governance/actions/{$action->id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: 1,
                        area: GovernanceArea::ActionItems,
                        ownerName: $viewer->name,
                        receipt: [
                            'receipt_id' => "ACT-RCP-{$action->id}",
                            'completed_at' => $action->completed_at?->toIso8601String() ?? $action->updated_at?->toIso8601String(),
                            'completion_notes' => $action->completion_notes ?? 'Completed by assignee',
                            'summary' => $action->completion_notes ?? 'Completed by assignee',
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                $availability['action_items'] = 'unavailable';
            }
        }

        // 2. Completed Board Pack Reads
        if (Schema::hasTable('board_packs') && $boardMember) {
            try {
                $packs = BoardPack::query()
                    ->where('build_status', 'published')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get();

                foreach ($packs as $pack) {
                    $receipt = $pack->getMemberReceipt($boardMember->id);
                    if ($receipt) {
                        $items->push(new GovernanceWorkItem(
                            id: "pack:{$pack->id}:read:completed",
                            kind: GovernanceWorkKind::Read,
                            source: [
                                'type' => 'board_pack',
                                'id' => $pack->id,
                                'reference' => "Pack #{$pack->id} (r{$pack->revision_number})",
                                'href' => "/governance/packs/{$pack->id}",
                            ],
                            title: "Read: ".($pack->meeting?->title ?? 'Board')." Pack (r{$pack->revision_number})",
                            reason: 'Formal reading acknowledgement recorded.',
                            priority: 'medium',
                            status: 'completed',
                            dueAt: null,
                            dueDate: null,
                            assigneeUserId: $viewer->id,
                            boardMemberId: $boardMember->id,
                            requiredAction: [
                                'key' => 'read',
                                'label' => 'View Pack',
                                'href' => "/governance/packs/{$pack->id}",
                                'allowed' => true,
                                'blocked_reason' => null,
                            ],
                            sourceVersion: (int) ($pack->revision_number ?? 1),
                            area: GovernanceArea::Packs,
                            ownerName: $viewer->name,
                            receipt: [
                                'receipt_id' => $receipt['receipt_id'] ?? "RCP-{$pack->id}-{$boardMember->id}",
                                'completed_at' => $receipt['read_at'] ?? null,
                                'revision_number' => $receipt['revision_number'] ?? $pack->revision_number,
                            ],
                        ));
                    }
                }
            } catch (\Throwable $e) {
                $availability['board_packs'] = 'unavailable';
            }
        }

        // 3. Completed Policy Attestations
        if (Schema::hasTable('policy_attestations') && Schema::hasTable('governance_policies')) {
            try {
                $attestations = DB::table('policy_attestations')
                    ->join('governance_policies', 'policy_attestations.governance_policy_id', '=', 'governance_policies.id')
                    ->where('policy_attestations.user_id', $viewer->id)
                    ->whereNotNull('policy_attestations.acknowledged_at')
                    ->select(
                        'policy_attestations.id as attestation_id',
                        'policy_attestations.governance_policy_id as policy_id',
                        'policy_attestations.acknowledged_at',
                        'governance_policies.title as policy_title',
                        DB::raw('COALESCE(policy_attestations.policy_version, governance_policies.version_number, 1) as policy_version')
                    )
                    ->orderByDesc('policy_attestations.acknowledged_at')
                    ->limit(50)
                    ->get();

                foreach ($attestations as $att) {
                    $items->push(new GovernanceWorkItem(
                        id: "policy:{$att->attestation_id}:read:completed",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'policy',
                            'id' => (int) $att->policy_id,
                            'reference' => "Policy #{$att->policy_id}",
                            'href' => "/governance/policies/{$att->policy_id}",
                        ],
                        title: "Attested: {$att->policy_title}",
                        reason: 'Policy reading and compliance attestation confirmed.',
                        priority: 'low',
                        status: 'completed',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'View Policy',
                            'href' => "/governance/policies/{$att->policy_id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: (int) ($att->policy_version ?? 1),
                        area: GovernanceArea::Policies,
                        ownerName: $viewer->name,
                        receipt: [
                            'receipt_id' => "ATT-RCP-{$att->attestation_id}",
                            'completed_at' => $att->acknowledged_at,
                            'version' => $att->policy_version,
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                $availability['policies'] = 'unavailable';
            }
        }

        // 4. Completed Votes
        if (Schema::hasTable('governance_votes') && $boardMember) {
            try {
                $votes = Vote::query()
                    ->where('board_member_id', $boardMember->id)
                    ->with('resolution')
                    ->orderByDesc('voted_at')
                    ->limit(50)
                    ->get();

                foreach ($votes as $vote) {
                    $res = $vote->resolution;
                    $items->push(new GovernanceWorkItem(
                        id: "resolution:{$vote->resolution_id}:vote:completed",
                        kind: GovernanceWorkKind::Vote,
                        source: [
                            'type' => 'resolution',
                            'id' => $vote->resolution_id,
                            'reference' => $res?->resolution_reference ?? "RES-{$vote->resolution_id}",
                            'href' => "/governance/resolutions/{$vote->resolution_id}",
                        ],
                        title: "Voted: ".($res?->title ?? "Resolution #{$vote->resolution_id}"),
                        reason: "Vote cast as ".strtoupper($vote->vote).".",
                        priority: 'low',
                        status: 'completed',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember->id,
                        requiredAction: [
                            'key' => 'vote',
                            'label' => 'View Decision',
                            'href' => "/governance/resolutions/{$vote->resolution_id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: 1,
                        area: GovernanceArea::Resolutions,
                        ownerName: $viewer->name,
                        receipt: [
                            'receipt_id' => "VOTE-RCP-{$vote->id}",
                            'vote' => $vote->vote,
                            'completed_at' => $vote->voted_at?->toIso8601String(),
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                $availability['resolutions'] = 'unavailable';
            }
        }

        return $items;
    }

    /**
     * Total completed actions count for viewer.
     */
    protected function queryCompletedCount(User $viewer): int
    {
        if (! Schema::hasTable('action_items')) {
            return 0;
        }

        return ActionItem::query()
            ->where('assigned_to', $viewer->id)
            ->whereIn('status', ['complete', 'completed'])
            ->count();
    }

    /**
     * Deterministic comparison: urgency -> due date -> priority -> stable ID.
     */
    protected function compareWorkItems(GovernanceWorkItem $a, GovernanceWorkItem $b): int
    {
        // 1. Urgency
        $urgencyRank = [
            'overdue' => 5000,
            'due_soon' => 4000,
            'blocked' => 3000,
            'pending' => 2000,
            'completed' => 1000,
        ];
        $uA = $urgencyRank[$a->status] ?? 2000;
        $uB = $urgencyRank[$b->status] ?? 2000;
        if ($uA !== $uB) {
            return $uB <=> $uA; // Higher urgency first
        }

        // 2. Due date (earlier due dates first; nulls last)
        if ($a->dueDate !== null || $b->dueDate !== null) {
            if ($a->dueDate === null) {
                return 1;
            }
            if ($b->dueDate === null) {
                return -1;
            }
            $dateCmp = strcmp($a->dueDate, $b->dueDate);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
        }

        // 3. Priority
        $priorityRank = [
            'critical' => 400,
            'high' => 300,
            'medium' => 200,
            'low' => 100,
        ];
        $pA = $priorityRank[$a->priority] ?? 200;
        $pB = $priorityRank[$b->priority] ?? 200;
        if ($pA !== $pB) {
            return $pB <=> $pA; // Higher priority first
        }

        // 4. Stable ID tiebreaker
        return strcmp($a->id, $b->id);
    }
}
