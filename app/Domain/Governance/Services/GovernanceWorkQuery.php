<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Data\GovernanceWorkItem;
use App\Domain\Governance\Enums\GovernanceArea;
use App\Domain\Governance\Enums\GovernanceWorkKind;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Governance\Support\GovernanceWording;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The viewer's personal Governance work — votes, reading, assigned actions —
 * plus the meetings coming up for them. Every title, reason and button label
 * here reaches board members verbatim, so it follows vocabulary.md: the record
 * title first, plain reasons ("In 3 days", "You voted For"), sentence-case
 * verbs ("Vote", "Read pack", "Read and confirm", "Update action").
 */
class GovernanceWorkQuery
{
    /** Statuses of work that still needs the viewer to do something. */
    public const OPEN_STATUSES = ['pending', 'due_soon', 'overdue', 'blocked'];

    /** Upcoming meetings are "for your information" — never pending work. */
    public const UPCOMING_STATUS = 'upcoming';

    /** How long a completed performance review stays in "for your information". */
    public const REVIEW_OUTCOME_DAYS = 30;

    public function __construct(
        protected GovernanceRecordAccessService $recordAccess,
        protected BoardPackAccessService $boardPackAccess,
        protected GovernanceVotingProfileService $votingProfileService,
    ) {}

    /**
     * Query full personal obligations for the given viewer.
     *
     * `items` is the filtered, paginated list. "For your information" items
     * (upcoming meetings, a performance review that has just been completed)
     * are kept apart: they never count towards `all`,
     * `pending` or `overdue`, appear in `items` only for the `know` kind
     * filter, and are always listed separately as `coming_up`.
     *
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     coming_up: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     pagination: array{total: int, per_page: int, current_page: int, last_page: int},
     *     scope: array<string, mixed>,
     *     availability: array<string, string>,
     *     all_sources_succeeded: bool,
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
            'performance_reviews' => 'available',
        ];

        /** @var Collection<int, GovernanceWorkItem> $actionable */
        $actionable = collect()
            ->merge($this->queryVoteItems($viewer, $availability))
            ->merge($this->queryReadItems($viewer, $availability))
            ->merge($this->queryActItems($viewer, $availability))
            ->merge($this->queryPerformanceReviewItems($viewer, $availability))
            ->sort(fn (GovernanceWorkItem $a, GovernanceWorkItem $b) => $this->compareWorkItems($a, $b))
            ->values();

        $comingUp = $this->queryKnowItems($viewer, $availability)
            ->merge($this->queryPerformanceReviewOutcomes($viewer, $availability))
            ->sort(fn (GovernanceWorkItem $a, GovernanceWorkItem $b) => $this->compareWorkItems($a, $b))
            ->values();

        $completedItems = $this->queryCompletedItems($viewer, $availability)
            ->sortByDesc(fn (GovernanceWorkItem $item) => (string) ($item->receipt['completed_at'] ?? ''))
            ->values();

        // Full authorised totals, calculated BEFORE pagination or display limits.
        $totals = [
            'all' => $actionable->count(),
            'vote' => $actionable->where('kind', GovernanceWorkKind::Vote->value)->count(),
            'read' => $actionable->where('kind', GovernanceWorkKind::Read->value)->count(),
            'act' => $actionable->where('kind', GovernanceWorkKind::Act->value)->count(),
            'know' => $comingUp->count(),
            'pending' => $actionable->whereIn('status', self::OPEN_STATUSES)->count(),
            'overdue' => $actionable->where('status', 'overdue')->count(),
            'blocked' => $actionable->where('status', 'blocked')->count(),
            'completed' => $completedItems->count(),
        ];

        $statusFilter = (string) ($filters['status'] ?? 'pending');
        $kindFilter = (string) ($filters['kind'] ?? 'all');

        if ($kindFilter === GovernanceWorkKind::Know->value) {
            $filteredItems = $statusFilter === 'completed' ? collect() : $comingUp;
        } elseif ($statusFilter === 'completed') {
            $filteredItems = $completedItems;
        } elseif ($statusFilter === 'all') {
            $filteredItems = $actionable->concat($completedItems)->values();
        } else {
            $filteredItems = match ($statusFilter) {
                'overdue' => $actionable->where('status', 'overdue'),
                'blocked' => $actionable->where('status', 'blocked'),
                default => $actionable->whereIn('status', self::OPEN_STATUSES),
            };
        }

        if (in_array($kindFilter, ['vote', 'read', 'act'], true)) {
            $filteredItems = $filteredItems->where('kind', $kindFilter);
        }

        $dueFilter = $filters['due'] ?? 'all';
        if ($dueFilter === 'overdue') {
            $filteredItems = $filteredItems->where('status', 'overdue');
        } elseif ($dueFilter === 'next7') {
            $filteredItems = $filteredItems->filter(function (GovernanceWorkItem $item) {
                $days = GovernanceWording::daysFromToday($item->dueAt ?? $item->dueDate);

                return $days !== null && $days >= 0 && $days <= 7;
            });
        }

        if (! empty($filters['search'])) {
            $search = mb_strtolower(trim((string) $filters['search']));
            $filteredItems = $filteredItems->filter(function (GovernanceWorkItem $item) use ($search) {
                return str_contains(mb_strtolower($item->title), $search)
                    || str_contains(mb_strtolower($item->reason), $search)
                    || str_contains(mb_strtolower((string) ($item->source['reference'] ?? '')), $search);
            });
        }

        $filteredItems = $filteredItems->values();
        $totalFiltered = $filteredItems->count();
        $lastPage = (int) max(1, ceil($totalFiltered / $perPage));
        $currentPage = max(1, min($page, $lastPage));

        $pagedSlice = $filteredItems->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return [
            'items' => $pagedSlice->map(fn (GovernanceWorkItem $item) => $item->toArray())->all(),
            'coming_up' => $comingUp->map(fn (GovernanceWorkItem $item) => $item->toArray())->all(),
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
            $query = Resolution::query()->where('status', 'open')->with('meeting');
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

                // If user already voted/stepped aside or cannot vote, this is not a pending personal vote obligation
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
                    $isMissingDeadline => 'No voting deadline has been set.',
                    $isOverdue => 'The voting deadline passed on '.GovernanceLabels::date($deadline, true).', so your vote can no longer be recorded.',
                    default => 'Voting closes '.GovernanceWording::relativeDay($deadline).' — '.GovernanceLabels::date($deadline, true).'.',
                };

                $items->push(new GovernanceWorkItem(
                    id: "resolution:{$resolution->id}:vote",
                    kind: GovernanceWorkKind::Vote,
                    source: [
                        'type' => 'resolution',
                        'id' => $resolution->id,
                        'reference' => (string) ($resolution->resolution_reference ?? ''),
                        'href' => "/governance/resolutions/{$resolution->id}",
                    ],
                    title: (string) $resolution->title,
                    reason: $reason,
                    priority: $priority,
                    status: $status,
                    dueAt: $deadline?->toIso8601String(),
                    dueDate: $deadline?->toDateString(),
                    assigneeUserId: $viewer->id,
                    boardMemberId: $boardMember?->id,
                    requiredAction: [
                        'key' => 'vote',
                        // Votes can't be recorded after the deadline — offer the paper, not a dead "Vote".
                        'label' => $isOverdue ? 'Open paper' : 'Vote',
                        'href' => $this->decisionWorkspaceHref($viewer, $resolution),
                        'allowed' => true,
                        'blocked_reason' => null,
                    ],
                    sourceVersion: (int) ($resolution->version_number ?? 1),
                    area: GovernanceArea::Resolutions,
                    ownerName: $viewer->name,
                ));
            }

            return $items;
        } catch (\Throwable $e) {
            report($e);
            $availability['resolutions'] = 'unavailable';

            return collect();
        }
    }

    /**
     * Derive canonical Read obligations (board packs & policies to read and confirm).
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryReadItems(User $viewer, array &$availability): Collection
    {
        $items = collect();
        $boardMember = $viewer->boardMember;

        // 1. Current distributed board packs
        if (Schema::hasTable('board_packs') && Schema::hasTable('governance_meetings')) {
            try {
                $packs = BoardPack::query()
                    ->where('is_current', true)
                    ->whereNotNull('distributed_at')
                    ->with('meeting')
                    ->get();

                foreach ($packs as $pack) {
                    if (! $this->boardPackAccess->canView($viewer, $pack)) {
                        continue;
                    }

                    // Check if viewer has already confirmed reading this version
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

                    $revNumber = (int) ($pack->revision_number ?? 1);

                    $items->push(new GovernanceWorkItem(
                        id: "board_pack:{$pack->id}:read",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'board_pack',
                            'id' => $pack->id,
                            'reference' => "Version {$revNumber}",
                            'href' => "/governance/packs/{$pack->id}",
                        ],
                        title: $this->packTitle($meeting?->title, $revNumber),
                        reason: $this->packReason($meeting?->title, $meetingTime, $isOverdue),
                        priority: $priority,
                        status: $status,
                        dueAt: $meetingTime?->toIso8601String(),
                        dueDate: $meetingTime?->toDateString(),
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Read pack',
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
                report($e);
                $availability['board_packs'] = 'unavailable';
            }
        }

        // 2. Policies to read and confirm — only those the viewer can open and
        // that can be confirmed today (the same rule GovernancePolicyPolicy::attest applies).
        if (Schema::hasTable('policy_attestations')
            && Schema::hasTable('governance_policies')
            && $viewer->canDo('governance.policies.view')) {
            try {
                $attestations = DB::table('policy_attestations')
                    ->join('governance_policies', 'policy_attestations.governance_policy_id', '=', 'governance_policies.id')
                    ->where('policy_attestations.user_id', $viewer->id)
                    ->whereNull('policy_attestations.acknowledged_at')
                    ->whereNull('governance_policies.deleted_at')
                    // Only policies that ask members to read and confirm them.
                    ->where('governance_policies.requires_attestation', true)
                    ->whereIn('governance_policies.status', ['approved', 'published', 'active'])
                    ->where(function ($query) {
                        $query->whereNull('governance_policies.effective_from')
                            ->orWhereDate('governance_policies.effective_from', '<=', now(config('app.worker_timezone', GovernanceLabels::TIMEZONE))->toDateString());
                    })
                    ->select(
                        'policy_attestations.id as attestation_id',
                        'policy_attestations.governance_policy_id as policy_id',
                        'policy_attestations.due_date',
                        'governance_policies.title as policy_title',
                        'governance_policies.policy_code as policy_code',
                        DB::raw('COALESCE(policy_attestations.policy_version, governance_policies.version_number, 1) as policy_version')
                    )
                    ->get();

                foreach ($attestations as $attestation) {
                    $due = $attestation->due_date ? Carbon::parse($attestation->due_date) : null;
                    $isOverdue = $due !== null && $due->isPast();
                    $isDueSoon = $due !== null && ! $isOverdue && now()->diffInDays($due, false) <= 7;
                    $version = (int) ($attestation->policy_version ?? 1);

                    $reason = "Read version {$version} of this policy and confirm you've read it.";
                    if ($due !== null) {
                        $reason .= $isOverdue
                            ? ' It was due on '.GovernanceLabels::date($due->toDateString()).'.'
                            : ' Due '.GovernanceWording::relativeDay($due->toDateString()).' — '.GovernanceLabels::date($due->toDateString()).'.';
                    }

                    $items->push(new GovernanceWorkItem(
                        id: "policy:{$attestation->attestation_id}:read",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'policy',
                            'id' => (int) $attestation->policy_id,
                            'reference' => (string) ($attestation->policy_code ?: "Version {$version}"),
                            'href' => "/governance/policies/{$attestation->policy_id}",
                        ],
                        title: (string) $attestation->policy_title,
                        reason: $reason,
                        priority: $isOverdue ? 'critical' : ($isDueSoon ? 'high' : 'medium'),
                        status: $isOverdue ? 'overdue' : ($isDueSoon ? 'due_soon' : 'pending'),
                        dueAt: $due?->toIso8601String(),
                        dueDate: $due?->toDateString(),
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Read and confirm',
                            'href' => "/governance/policies/{$attestation->policy_id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: $version,
                        area: GovernanceArea::Policies,
                        ownerName: $viewer->name,
                    ));
                }

                // Policies the viewer confirmed before whose confirmation
                // frequency now asks for it again (the "due again" state on
                // Policies to confirm), unless a request above already covers it.
                $requestedPolicyIds = $attestations->pluck('policy_id')->map(fn ($id) => (int) $id)->all();
                $today = GovernancePolicy::nzToday();
                $repeating = GovernancePolicy::query()
                    ->where('requires_attestation', true)
                    ->whereIn('status', ['approved', 'published', 'active'])
                    ->whereNotNull('attestation_frequency')
                    ->whereNotIn('id', $requestedPolicyIds)
                    ->whereHas('attestations', fn ($query) => $query
                        ->where('user_id', $viewer->id)
                        ->whereNotNull('acknowledged_at'))
                    ->with(['attestations' => fn ($query) => $query
                        ->where('user_id', $viewer->id)
                        ->whereNotNull('acknowledged_at')
                        ->orderByDesc('acknowledged_at')])
                    ->orderBy('title')
                    ->get();

                foreach ($repeating as $policy) {
                    $mine = $policy->attestations->first();
                    if ($policy->confirmationStateFor($mine, $today) !== 'due_again') {
                        continue;
                    }

                    $dueOn = $policy->confirmationDueAgainOn($mine);
                    $isOverdue = $dueOn !== null && $dueOn < $today;
                    $version = (int) $policy->version_number;

                    $items->push(new GovernanceWorkItem(
                        id: "policy:{$policy->id}:confirm-again",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'policy',
                            'id' => (int) $policy->id,
                            'reference' => (string) ($policy->policy_code ?: "Version {$version}"),
                            'href' => "/governance/policies/{$policy->id}",
                        ],
                        title: (string) $policy->title,
                        reason: "Time to confirm this policy again — it asks members to re-read it "
                            .mb_strtolower(GovernanceLabels::label('frequency', (string) $policy->attestation_frequency))
                            .'. You last confirmed it on '.GovernanceLabels::date($mine->acknowledged_at).'.',
                        priority: $isOverdue ? 'high' : 'medium',
                        status: $isOverdue ? 'overdue' : 'due_soon',
                        dueAt: $dueOn !== null ? Carbon::parse($dueOn, GovernanceLabels::TIMEZONE)->toIso8601String() : null,
                        dueDate: $dueOn,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Read and confirm',
                            'href' => "/governance/policies/{$policy->id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: $version,
                        area: GovernanceArea::Policies,
                        ownerName: $viewer->name,
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
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

            // Full list without artificial limits, scoped to records the viewer can access
            $actionItems = $query->get()->filter(function (ActionItem $item) use ($viewer) {
                return $this->recordAccess->canViewActionItem($viewer, $item);
            });

            // The action page sits behind governance.actions.view: without it the
            // work is still listed, but the button says why it can't be opened.
            $canOpenActions = $viewer->canDo('governance.actions.view');

            return $actionItems->map(function (ActionItem $item) use ($viewer, $canOpenActions): GovernanceWorkItem {
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

                $dueDate = $item->due_date?->toDateString();
                $reason = match (true) {
                    $isBlocked => 'Blocked — '.rtrim((string) ($item->blocked_reason ?: 'no reason recorded'), '.').'.',
                    $isOverdue => 'Was due '.GovernanceLabels::date($dueDate).' ('.GovernanceWording::relativeDay($dueDate).').',
                    $dueDate !== null => 'Due '.GovernanceLabels::date($dueDate).' ('.GovernanceWording::relativeDay($dueDate).').',
                    default => 'No due date set.',
                };

                return new GovernanceWorkItem(
                    id: "action_item:{$item->id}:act",
                    kind: GovernanceWorkKind::Act,
                    source: [
                        'type' => 'action_item',
                        'id' => $item->id,
                        'reference' => (string) ($item->action_reference ?? ''),
                        'href' => "/governance/actions/{$item->id}",
                    ],
                    title: $this->actionTitle($item),
                    reason: $reason,
                    priority: $priority,
                    status: $status,
                    dueAt: $item->due_date?->toIso8601String(),
                    dueDate: $dueDate,
                    assigneeUserId: $viewer->id,
                    boardMemberId: $viewer->boardMember?->id,
                    requiredAction: [
                        'key' => 'act',
                        'label' => 'Update action',
                        'href' => "/governance/actions/{$item->id}",
                        'allowed' => $canOpenActions,
                        'blocked_reason' => ! $canOpenActions
                            ? "You don't have access to open actions. Ask the board secretary."
                            : ($isBlocked ? ($item->blocked_reason ?? 'This action is blocked.') : null),
                    ],
                    sourceVersion: (int) ($item->version_number ?? 1),
                    area: GovernanceArea::ActionItems,
                    ownerName: $viewer->name,
                );
            });
        } catch (\Throwable $e) {
            report($e);
            $availability['action_items'] = 'unavailable';

            return collect();
        }
    }

    /**
     * "For your information" items: the next meetings the viewer can open.
     * They are never pending work — the feed lists them apart as "Coming up".
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

                return new GovernanceWorkItem(
                    id: "meeting:{$meeting->id}:know",
                    kind: GovernanceWorkKind::Know,
                    source: [
                        'type' => 'governance_meeting',
                        'id' => $meeting->id,
                        'reference' => GovernanceLabels::label('meeting_type', $meeting->meeting_type),
                        'href' => "/governance/meetings/{$meeting->id}",
                    ],
                    title: (string) $meeting->title,
                    reason: $scheduled
                        ? GovernanceWording::capitalise(GovernanceWording::relativeDay($scheduled)).' — '.GovernanceLabels::date($scheduled, true).'.'
                        : 'The date is still to be confirmed.',
                    priority: 'low',
                    status: self::UPCOMING_STATUS,
                    dueAt: $scheduled?->toIso8601String(),
                    dueDate: $scheduled?->toDateString(),
                    assigneeUserId: $viewer->id,
                    boardMemberId: $viewer->boardMember?->id,
                    requiredAction: [
                        'key' => 'know',
                        'label' => 'Open meeting',
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
            report($e);
            $availability['meetings'] = 'unavailable';

            return collect();
        }
    }

    /**
     * "My performance review": while the board is waiting for the viewer's
     * self-assessment, a "Do" item to write it. Only the person being
     * reviewed gets it — the same condition the review page uses to offer
     * the self-assessment — and it never carries the board's ratings, scores
     * or decision, which stay masked until the review is complete.
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryPerformanceReviewItems(User $viewer, array &$availability): Collection
    {
        if (! Schema::hasTable('performance_reviews')) {
            return collect();
        }

        try {
            return PerformanceReview::query()
                ->where('reviewee_id', $viewer->id)
                ->whereNotIn('status', ['completed', 'closed'])
                ->whereNull('self_assessment_submitted_at')
                ->orderBy('id')
                ->get()
                ->map(function (PerformanceReview $review) use ($viewer): GovernanceWorkItem {
                    $cycle = PerformanceReview::cycleLabel($review->review_cycle);
                    // The review page (and its self-assessment route) sit behind
                    // governance.performance.view: without it the work is still
                    // listed, and the button says why it can't be opened.
                    $canWrite = $viewer->can('view', $review) && $viewer->can('submitSelfAssessment', $review);
                    $period = $review->period_start && $review->period_end
                        ? ' for '.GovernanceLabels::date($review->period_start->toDateString())
                            .' to '.GovernanceLabels::date($review->period_end->toDateString())
                        : '';

                    return new GovernanceWorkItem(
                        id: "performance_review:{$review->id}:self_assessment",
                        kind: GovernanceWorkKind::Act,
                        source: [
                            'type' => 'performance_review',
                            'id' => $review->id,
                            'reference' => '',
                            'href' => "/governance/performance/{$review->id}",
                        ],
                        title: "Write your self-assessment for {$cycle}",
                        reason: "The board is waiting for your self-assessment{$period}. You send it once, then the board completes your review.",
                        priority: 'high',
                        status: 'pending',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $viewer->boardMember?->id,
                        requiredAction: [
                            'key' => 'act',
                            'label' => 'Write self-assessment',
                            'href' => "/governance/performance/{$review->id}#self-assessment",
                            'allowed' => $canWrite,
                            'blocked_reason' => $canWrite
                                ? null
                                : "You don't have access to open performance reviews yet. Ask the board chair.",
                        ],
                        sourceVersion: 1,
                        area: 'Performance',
                        ownerName: $viewer->name,
                    );
                })
                ->values();
        } catch (\Throwable $e) {
            report($e);
            $availability['performance_reviews'] = 'unavailable';

            return collect();
        }
    }

    /**
     * Once the board has completed the viewer's performance review, a "for
     * your information" note that the outcome is ready to read, for
     * REVIEW_OUTCOME_DAYS after completion. It names the review — never the
     * rating or decision — and only reaches a reviewee who can open it.
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryPerformanceReviewOutcomes(User $viewer, array &$availability): Collection
    {
        if (! Schema::hasTable('performance_reviews')) {
            return collect();
        }

        try {
            $since = now()->subDays(self::REVIEW_OUTCOME_DAYS);

            return PerformanceReview::query()
                ->where('reviewee_id', $viewer->id)
                ->where('status', 'completed')
                ->where(function ($query) use ($since) {
                    $query->where('approved_by_board_at', '>=', $since)
                        ->orWhere(fn ($legacy) => $legacy->whereNull('approved_by_board_at')->where('updated_at', '>=', $since));
                })
                ->orderByDesc('id')
                ->get()
                ->filter(fn (PerformanceReview $review) => $viewer->can('view', $review))
                ->map(function (PerformanceReview $review) use ($viewer): GovernanceWorkItem {
                    $cycle = PerformanceReview::cycleLabel($review->review_cycle);
                    $completedAt = $review->approved_by_board_at ?? $review->updated_at;

                    return new GovernanceWorkItem(
                        id: "performance_review:{$review->id}:outcome",
                        kind: GovernanceWorkKind::Know,
                        source: [
                            'type' => 'performance_review',
                            'id' => $review->id,
                            'reference' => '',
                            'href' => "/governance/performance/{$review->id}",
                        ],
                        title: 'Your performance review is complete — read the outcome',
                        reason: $completedAt
                            ? "{$cycle}: the board completed it on ".GovernanceLabels::date($completedAt).'.'
                            : "{$cycle}: the board has completed it.",
                        priority: 'low',
                        status: 'completed',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $viewer->boardMember?->id,
                        requiredAction: [
                            'key' => 'know',
                            'label' => 'Read the outcome',
                            'href' => "/governance/performance/{$review->id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: 1,
                        area: 'Performance',
                        ownerName: $viewer->name,
                    );
                })
                ->values();
        } catch (\Throwable $e) {
            report($e);
            $availability['performance_reviews'] = 'unavailable';

            return collect();
        }
    }

    /**
     * Completed work with the record of completion the server actually holds.
     * Nothing is invented: a receipt number appears only when one was recorded.
     *
     * @param  array<string, string>  $availability
     * @return Collection<int, GovernanceWorkItem>
     */
    public function queryCompletedItems(User $viewer, array &$availability): Collection
    {
        $items = collect();
        $boardMember = $viewer->boardMember;

        // 1. Completed actions
        if (Schema::hasTable('action_items')) {
            try {
                $actions = ActionItem::query()
                    ->where('assigned_to', $viewer->id)
                    ->whereIn('status', ['complete', 'completed'])
                    ->orderByDesc('completed_at')
                    ->limit(50)
                    ->get()
                    ->filter(function (ActionItem $action) use ($viewer) {
                        return $this->recordAccess->canViewActionItem($viewer, $action);
                    });

                foreach ($actions as $action) {
                    $completedAt = $action->completed_at ?? $action->updated_at;

                    $items->push(new GovernanceWorkItem(
                        id: "action:{$action->id}:completed",
                        kind: GovernanceWorkKind::Act,
                        source: [
                            'type' => 'action_item',
                            'id' => $action->id,
                            'reference' => (string) ($action->action_reference ?? ''),
                            'href' => "/governance/actions/{$action->id}",
                        ],
                        title: $this->actionTitle($action),
                        reason: $completedAt ? 'Marked as done on '.GovernanceLabels::date($completedAt).'.' : 'Marked as done.',
                        priority: $action->priority ?? 'medium',
                        status: 'completed',
                        dueAt: $action->due_date?->toIso8601String(),
                        dueDate: $action->due_date?->toDateString(),
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'act',
                            'label' => 'Open action',
                            'href' => "/governance/actions/{$action->id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: (int) ($action->version_number ?? 1),
                        area: GovernanceArea::ActionItems,
                        ownerName: $viewer->name,
                        receipt: [
                            'receipt_id' => $action->completion_receipt ?: null,
                            'completed_at' => $completedAt?->toIso8601String(),
                            'completion_notes' => $action->completion_notes ?: null,
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
                $availability['action_items'] = 'unavailable';
            }
        }

        // 2. Board packs the viewer confirmed reading
        if (Schema::hasTable('board_packs') && $boardMember) {
            try {
                $packs = BoardPack::query()
                    ->where('build_status', 'published')
                    ->with('meeting')
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get();

                foreach ($packs as $pack) {
                    $receipt = $pack->getMemberReceipt($boardMember->id);
                    if (! $receipt || ! $this->boardPackAccess->canView($viewer, $pack)) {
                        continue;
                    }

                    $revision = (int) ($receipt['revision_number'] ?? $pack->revision_number ?? 1);
                    $readAt = $receipt['read_at'] ?? null;

                    $items->push(new GovernanceWorkItem(
                        id: "pack:{$pack->id}:read:completed",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'board_pack',
                            'id' => $pack->id,
                            'reference' => "Version {$revision}",
                            'href' => "/governance/packs/{$pack->id}",
                        ],
                        title: $this->packTitle($pack->meeting?->title, $revision),
                        reason: $readAt
                            ? "You confirmed you read version {$revision} on ".GovernanceLabels::date($readAt).'.'
                            : "You confirmed you read version {$revision}.",
                        priority: 'medium',
                        status: 'completed',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Open pack',
                            'href' => "/governance/packs/{$pack->id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: $revision,
                        // GovernanceArea has no Packs case; the old reference threw inside
                        // this try and wrongly marked board packs "unavailable".
                        area: GovernanceArea::Meetings,
                        ownerName: $viewer->name,
                        receipt: [
                            'receipt_id' => $receipt['receipt_id'] ?? null,
                            'completed_at' => $readAt,
                            'revision_number' => $revision,
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
                $availability['board_packs'] = 'unavailable';
            }
        }

        // 3. Policies the viewer confirmed reading
        if (Schema::hasTable('policy_attestations')
            && Schema::hasTable('governance_policies')
            && $viewer->canDo('governance.policies.view')) {
            try {
                $attestations = DB::table('policy_attestations')
                    ->join('governance_policies', 'policy_attestations.governance_policy_id', '=', 'governance_policies.id')
                    ->where('policy_attestations.user_id', $viewer->id)
                    ->whereNotNull('policy_attestations.acknowledged_at')
                    ->whereNull('governance_policies.deleted_at')
                    ->select(
                        'policy_attestations.id as attestation_id',
                        'policy_attestations.governance_policy_id as policy_id',
                        'policy_attestations.acknowledged_at',
                        'governance_policies.title as policy_title',
                        'governance_policies.policy_code as policy_code',
                        DB::raw('COALESCE(policy_attestations.policy_version, governance_policies.version_number, 1) as policy_version')
                    )
                    ->orderByDesc('policy_attestations.acknowledged_at')
                    ->limit(50)
                    ->get();

                foreach ($attestations as $att) {
                    $version = (int) ($att->policy_version ?? 1);
                    $confirmedAt = $att->acknowledged_at ? Carbon::parse($att->acknowledged_at, 'UTC') : null;

                    $items->push(new GovernanceWorkItem(
                        id: "policy:{$att->attestation_id}:read:completed",
                        kind: GovernanceWorkKind::Read,
                        source: [
                            'type' => 'policy',
                            'id' => (int) $att->policy_id,
                            'reference' => (string) ($att->policy_code ?: "Version {$version}"),
                            'href' => "/governance/policies/{$att->policy_id}",
                        ],
                        title: (string) $att->policy_title,
                        reason: $confirmedAt
                            ? "You confirmed version {$version} on ".GovernanceLabels::date($confirmedAt).'.'
                            : "You confirmed version {$version}.",
                        priority: 'low',
                        status: 'completed',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember?->id,
                        requiredAction: [
                            'key' => 'read',
                            'label' => 'Open policy',
                            'href' => "/governance/policies/{$att->policy_id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: $version,
                        area: GovernanceArea::Policies,
                        ownerName: $viewer->name,
                        receipt: [
                            'receipt_id' => null,
                            'completed_at' => $confirmedAt?->toIso8601String(),
                            'version' => $version,
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
                $availability['policies'] = 'unavailable';
            }
        }

        // 4. Votes the viewer cast. (The table is `votes` — the old
        // `governance_votes` guard never matched, so no vote ever showed here.)
        if ($boardMember && Schema::hasTable((new Vote)->getTable())) {
            try {
                $votes = Vote::query()
                    ->where('board_member_id', $boardMember->id)
                    ->with('resolution.meeting')
                    ->orderByDesc('voted_at')
                    ->limit(50)
                    ->get();

                foreach ($votes as $vote) {
                    $res = $vote->resolution;
                    if ($res === null || ! $this->recordAccess->canViewResolution($viewer, $res)) {
                        continue;
                    }
                    $voteLabel = GovernanceLabels::label('vote', $vote->vote);

                    $items->push(new GovernanceWorkItem(
                        id: "resolution:{$vote->resolution_id}:vote:completed",
                        kind: GovernanceWorkKind::Vote,
                        source: [
                            'type' => 'resolution',
                            'id' => $vote->resolution_id,
                            'reference' => (string) ($res?->resolution_reference ?? ''),
                            'href' => "/governance/resolutions/{$vote->resolution_id}",
                        ],
                        title: (string) ($res?->title ?? 'Resolution'),
                        reason: $vote->voted_at
                            ? "You voted {$voteLabel} on ".GovernanceLabels::date($vote->voted_at, true).'.'
                            : "You voted {$voteLabel}.",
                        priority: 'low',
                        status: 'completed',
                        dueAt: null,
                        dueDate: null,
                        assigneeUserId: $viewer->id,
                        boardMemberId: $boardMember->id,
                        requiredAction: [
                            'key' => 'vote',
                            'label' => 'Open paper',
                            'href' => $res
                                ? $this->decisionWorkspaceHref($viewer, $res)
                                : "/governance/resolutions/{$vote->resolution_id}",
                            'allowed' => true,
                            'blocked_reason' => null,
                        ],
                        sourceVersion: (int) ($res?->version_number ?? 1),
                        area: GovernanceArea::Resolutions,
                        ownerName: $viewer->name,
                        receipt: [
                            // The same server reference the paper's own receipt shows.
                            'receipt_id' => method_exists($vote, 'receiptId') ? $vote->receiptId() : null,
                            'vote' => $vote->vote,
                            'completed_at' => $vote->voted_at?->toIso8601String(),
                            'paper_version' => (int) ($res->version_number ?? 1),
                        ],
                    ));
                }
            } catch (\Throwable $e) {
                report($e);
                $availability['resolutions'] = 'unavailable';
            }
        }

        return $items;
    }

    /**
     * Where a member acts on a decision: the paper inside its meeting
     * workspace when the viewer can open that meeting (the approved
     * one-meeting journey), otherwise the canonical resolution page
     * (e.g. written resolutions outside a meeting).
     */
    public function decisionWorkspaceHref(User $viewer, Resolution $resolution): string
    {
        $meeting = $resolution->relationLoaded('meeting') ? $resolution->meeting : $resolution->meeting()->first();

        if ($meeting instanceof GovernanceMeeting
            && app(ExecutiveMeetingAccessService::class)->canViewMeeting($viewer, $meeting)) {
            return "/governance/meetings/{$meeting->id}?tab=resolutions&paper={$resolution->id}";
        }

        return "/governance/resolutions/{$resolution->id}";
    }

    /** "Board pack for September board meeting (version 2)". */
    protected function packTitle(?string $meetingTitle, int $revision): string
    {
        $title = $meetingTitle ? "Board pack for {$meetingTitle}" : 'Board pack';

        return $revision > 1 ? "{$title} (version {$revision})" : $title;
    }

    protected function packReason(?string $meetingTitle, ?Carbon $meetingTime, bool $isOverdue): string
    {
        $subject = $meetingTitle ? "the board pack for {$meetingTitle}" : 'the board pack';
        $ask = "Read {$subject} and confirm you've read it";

        return match (true) {
            $meetingTime === null => "{$ask}.",
            $isOverdue => "{$ask}. The meeting was on ".GovernanceLabels::date($meetingTime).'.',
            default => "{$ask} before the meeting ".GovernanceWording::relativeDay($meetingTime).'.',
        };
    }

    /** The action's own words first — never its reference code. */
    protected function actionTitle(ActionItem $action): string
    {
        $title = trim((string) ($action->title ?: $action->description));

        return $title === '' ? 'Action' : Str::limit($title, 80);
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
            self::UPCOMING_STATUS => 1500,
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
