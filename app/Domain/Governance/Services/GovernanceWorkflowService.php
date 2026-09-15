<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Enums\GovernanceArea;
use App\Domain\Governance\Enums\GovernanceWorkKind;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Domain\Governance\Models\Vote;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Governance\Support\GovernanceWording;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Board priorities (Home) and the meeting preparation checklist.
 *
 * Audience rule: every priority source is filtered by the viewer's matching
 * register permission / record audience — the same gate as the page its link
 * opens — so a count or title never leaks and no link returns 403. Meeting and
 * resolution administration only reaches people who can carry it out.
 *
 * Wording rule (vocabulary.md): titles lead with a short plain phrase and the
 * record's own title — never a reference code (codes travel in
 * `source.reference` and render last, muted); money via GovernanceLabels::money,
 * enums via GovernanceLabels::label, NZ dates; sentence-case button labels.
 */
class GovernanceWorkflowService
{
    public function __construct(
        protected BoardPackAccessService $boardPackAccess,
        protected ?GovernanceWorkQuery $workQuery = null,
    ) {
        $this->workQuery ??= app(GovernanceWorkQuery::class);
    }

    public function workQuery(): GovernanceWorkQuery
    {
        return $this->workQuery;
    }

    /** Priority tabs the dashboard panel can page through. */
    public const PRIORITY_TABS = ['all', 'meetings', 'actions', 'risks', 'compliance', 'policies'];

    /**
     * Ranked board priorities for one viewer.
     *
     * `actions` is one page of the ranked list for `$tab` (`$limit` per page).
     * `summary` always describes the viewer's complete population, and
     * `pagination` states exactly how much of the tab has been returned so a
     * client can reach every counted item — a total is never a promise the
     * payload cannot keep.
     */
    public function dashboardWorkflow(User|int|null $user = null, int $limit = 100, int $page = 1, string $tab = 'all'): array
    {
        if (is_int($user)) {
            $user = User::find($user);
        }

        $actions = collect()
            ->merge($this->meetingActions($user))
            ->merge($this->resolutionActions($user))
            ->merge($this->riskActions($user))
            ->merge($this->complianceActions($user))
            ->merge($this->budgetActions($user))
            ->merge($this->policyActions($user))
            ->merge($this->actionItemActions($user));

        $total = $actions->count();
        $critical = $actions->where('priority', 'critical')->count();
        $overdue = $actions->where('status', 'overdue')->count();
        $actionItemsOverdue = $actions->filter(fn (array $a) => ($a['source']['type'] ?? null) === 'action_item' && ($a['status'] ?? null) === 'overdue')->count();

        $byTab = collect(self::PRIORITY_TABS)
            ->mapWithKeys(fn (string $key) => [$key => $actions->filter(fn (array $a) => $this->matchesPriorityTab($key, $a))->count()])
            ->all();

        $tab = in_array($tab, self::PRIORITY_TABS, true) ? $tab : 'all';
        $perPage = max(1, $limit);
        $page = max(1, $page);

        // Stable sort: filtering the ranked population and ranking the filtered
        // subset give the same order, so tab pages agree with the "all" ranking.
        $ranked = $actions
            ->filter(fn (array $a) => $this->matchesPriorityTab($tab, $a))
            ->sortByDesc(fn (array $action) => $this->actionRank($action))
            ->values();

        $tabTotal = $ranked->count();
        $lastPage = max(1, (int) ceil($tabTotal / $perPage));
        $pageItems = $ranked->slice(($page - 1) * $perPage, $perPage)->values();
        $from = $pageItems->isEmpty() ? 0 : (($page - 1) * $perPage) + 1;

        return [
            'summary' => [
                'total' => $total,
                'critical' => $critical,
                'overdue' => $overdue,
                'action_items_overdue' => $actionItemsOverdue,
                'by_tab' => $byTab,
            ],
            'actions' => $pageItems->all(),
            'pagination' => [
                'tab' => $tab,
                'page' => $page,
                'per_page' => $perPage,
                'total' => $tabTotal,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $from === 0 ? 0 : $from + $pageItems->count() - 1,
                'has_more' => $page < $lastPage,
            ],
        ];
    }

    /**
     * Single definition of which ranked priorities belong to a dashboard tab;
     * used for both the tab counts and the paged tab lists.
     */
    protected function matchesPriorityTab(string $tab, array $action): bool
    {
        $areaKey = $action['area_key'] ?? null;
        $area = $action['area'] ?? null;

        return match ($tab) {
            'meetings' => $areaKey === 'meetings' || $area === 'Meetings',
            'actions' => in_array($areaKey, ['action_items', 'actions'], true) || $area === 'Action Items',
            'risks' => in_array($areaKey, ['risks', 'risk_register'], true) || in_array($area, ['Risks', 'Risk Register'], true),
            'compliance' => $areaKey === 'compliance' || $area === 'Compliance',
            'policies' => $areaKey === 'policies' || $area === 'Policies',
            default => true,
        };
    }

    public function meetingChecklist(GovernanceMeeting $meeting, ?User $user = null): array
    {
        $meeting->loadMissing([
            'agendaItems',
            'attendances',
            'boardPack',
            'ceoReport',
            'minutes',
            'resolutions',
        ]);

        // Counts and completed steps come from the same permitted records the
        // viewer's meeting workspace renders — a confidential agenda item or a
        // resolution the viewer cannot open never makes a step look ready.
        $agendaCount = app(ExecutiveMeetingAccessService::class)
            ->visibleAgendaItems($user, $meeting)
            ->count();
        $visibleResolutions = $user !== null
            ? $meeting->resolutions->filter(
                fn (Resolution $resolution) => app(GovernanceRecordAccessService::class)->canViewResolution($user, $resolution)
            )
            : $meeting->resolutions;
        $attendanceCount = $meeting->attendances->count();
        $quorum = $meeting->calculateQuorum();
        $pack = $user
            ? $this->boardPackAccess->visiblePack($user, $meeting->boardPack)
            : null;
        $includePackItems = $user !== null
            && ($this->boardPackAccess->canManage($user) || $pack !== null);
        $ceoReport = $meeting->ceoReport;
        $minutes = $meeting->minutes;
        $resolutions = $visibleResolutions->whereIn('status', ['draft', 'open'])->count();
        $isPastMeeting = $meeting->scheduled_at?->isPast() ?? false;
        $previousMeeting = $this->previousMeeting($meeting);
        $previousOpenActions = $this->previousMeetingOpenActions($previousMeeting);

        $minutesStatus = $minutes?->status;
        $minutesApproved = in_array($minutesStatus, ['approved', 'signed', 'archived'], true);
        $minutesSigned = in_array($minutesStatus, ['signed', 'archived'], true);
        $ceoSubmitted = in_array($ceoReport?->status, ['submitted', 'included_in_pack'], true);
        $ceoNotNeeded = ! $meeting->isFullBoard() && ! $meeting->ceoReport && ! $meeting->ceo_report_deadline;
        $packDistributedCount = count(array_unique($pack?->distributed_to ?? []));
        $packReadCount = $pack?->readCount() ?? 0;
        $canRecordAttendance = $user !== null && $user->can('update', $meeting);

        $items = collect([
            [
                'key' => 'agenda',
                'label' => 'Agenda prepared',
                'status' => $agendaCount > 0 ? 'done' : 'todo',
                'detail' => $agendaCount > 0
                    ? GovernanceWording::count($agendaCount, 'agenda item').' '.($agendaCount === 1 ? 'is' : 'are').' ready.'
                    : 'Add agenda items so the board pack has something in it.',
                'action_label' => 'Open agenda',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=agenda",
                'blocked_by' => null,
            ],
            [
                'key' => 'quorum',
                'label' => 'Attendance recorded',
                'status' => $quorum['met'] ? 'done' : ($attendanceCount > 0 ? 'in_progress' : 'todo'),
                'detail' => match (true) {
                    $attendanceCount === 0 => "Attendance hasn't been recorded yet. It's recorded at the meeting.",
                    (bool) $quorum['met'] => GovernanceWording::count((int) $quorum['present'], 'member')
                        ." recorded as present — {$quorum['required']} were needed for decisions to be valid.",
                    default => "{$quorum['present']} of the {$quorum['required']} members needed for decisions to be valid are recorded as present.",
                },
                'action_label' => $canRecordAttendance ? 'Record attendance' : 'View attendance',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=attendance",
                'blocked_by' => $canRecordAttendance ? null : ($attendanceCount > 0 ? null : 'Waiting for the secretary to record attendance'),
            ],
            [
                'key' => 'ceo_report',
                'label' => 'CEO report ready',
                'status' => $ceoNotNeeded ? 'not_applicable' : ($ceoSubmitted ? 'done' : 'todo'),
                'detail' => match (true) {
                    $ceoNotNeeded => 'Committee meetings don\'t need a CEO report.',
                    $ceoSubmitted => 'The CEO report has been submitted.',
                    $meeting->ceo_report_deadline !== null => 'The CEO report is due '.GovernanceLabels::date($meeting->ceo_report_deadline, true).'.',
                    default => 'The CEO report hasn\'t been submitted yet.',
                },
                'action_label' => 'Open CEO report',
                'action_url' => $ceoReport ? "/governance/ceo-reports/{$ceoReport->id}" : '/governance/ceo-reports',
                'blocked_by' => null,
            ],
            [
                'key' => 'pack_generated',
                'label' => 'Board pack prepared',
                'status' => $pack !== null ? 'done' : ($agendaCount > 0 ? 'todo' : 'blocked'),
                'detail' => $pack !== null
                    ? 'The board pack is ready to send.'
                    : 'Generate a draft pack once the agenda is ready. Nothing is sent to members until it is distributed.',
                'action_label' => 'Open meeting',
                'action_url' => "/governance/meetings/{$meeting->id}",
                'blocked_by' => $agendaCount > 0 ? null : 'The agenda is empty',
            ],
            [
                'key' => 'pack_distributed',
                'label' => 'Board pack sent to members',
                'status' => $pack?->distributed_at ? 'done' : ($pack !== null ? 'todo' : 'blocked'),
                'detail' => $pack?->distributed_at
                    ? 'Sent to '.GovernanceWording::count($packDistributedCount, 'member').'; '
                        .$packReadCount.' '.($packReadCount === 1 ? 'has' : 'have').' confirmed reading it.'
                    : 'Send the board pack so members can read it before the meeting.',
                'action_label' => $pack !== null ? 'Open board pack' : 'Open meeting',
                'action_url' => $pack !== null
                    ? "/governance/packs/{$pack->id}"
                    : "/governance/meetings/{$meeting->id}",
                'blocked_by' => $pack !== null ? null : 'The board pack hasn\'t been prepared',
            ],
            [
                'key' => 'resolutions',
                'label' => 'Resolutions ready',
                'status' => $resolutions > 0 ? 'done' : 'todo',
                'detail' => $resolutions > 0
                    ? GovernanceWording::count($resolutions, 'resolution').' '.($resolutions === 1 ? 'is' : 'are').' in draft or open for voting.'
                    : 'No resolutions have been added to this meeting yet.',
                'action_label' => 'Open resolutions',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=resolutions",
                'blocked_by' => null,
            ],
            [
                'key' => 'minutes_drafted',
                'label' => 'Minutes written',
                'status' => $minutes !== null ? 'done' : ($isPastMeeting ? 'todo' : 'blocked'),
                'detail' => match ($minutesStatus) {
                    null => 'Write the minutes after the meeting.',
                    'draft' => 'A draft of the minutes has been started.',
                    'reviewed' => 'The draft minutes have been sent to the chair for approval.',
                    'approved' => 'The minutes are approved.',
                    'signed' => 'The minutes are approved and signed.',
                    'archived' => 'The minutes are signed and archived.',
                    default => 'The minutes have been started.',
                },
                'action_label' => 'Open minutes',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=minutes",
                'blocked_by' => $minutes === null && ! $isPastMeeting ? 'The meeting hasn\'t happened yet' : null,
            ],
            [
                'key' => 'minutes_approved',
                'label' => 'Minutes approved',
                'status' => $minutesApproved ? 'done' : ($minutes !== null ? 'todo' : 'blocked'),
                'detail' => match (true) {
                    $minutesApproved => 'The minutes have been approved.',
                    $minutesStatus === 'reviewed' => 'Waiting for the chair to approve the minutes.',
                    default => 'Send the draft minutes to the chair for approval.',
                },
                'action_label' => 'Review minutes',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=minutes",
                'blocked_by' => $minutes !== null ? null : 'The minutes haven\'t been written',
            ],
            [
                'key' => 'minutes_signed',
                'label' => 'Minutes signed',
                'status' => $minutesSigned ? 'done' : ($minutesApproved ? 'todo' : 'blocked'),
                'detail' => $minutesSigned
                    ? 'The minutes are signed and filed.'
                    : 'Sign the approved minutes to finish this meeting.',
                'action_label' => 'Finalise minutes',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=minutes",
                'blocked_by' => $minutesApproved ? null : 'The minutes haven\'t been approved',
            ],
            [
                'key' => 'follow_through',
                'label' => 'Actions from the last meeting checked',
                'status' => $previousOpenActions->isEmpty() ? 'done' : 'todo',
                'detail' => $previousMeeting
                    ? ($previousOpenActions->isEmpty()
                        ? "No actions are still open from {$previousMeeting->title}."
                        : GovernanceWording::count($previousOpenActions->count(), 'action').' '
                            .($previousOpenActions->count() === 1 ? 'is' : 'are')." still open from {$previousMeeting->title}.")
                    : 'There is no earlier meeting to check.',
                'action_label' => 'Open actions',
                'action_url' => '/governance/actions',
                'blocked_by' => null,
            ],
        ]);

        if (! $includePackItems) {
            $items = $items->reject(
                fn (array $item) => in_array($item['key'], ['pack_generated', 'pack_distributed'], true),
            );
        }

        $boardMember = $user?->boardMember;
        $isInvitedMember = $boardMember !== null && $meeting->isInvited($boardMember);
        $userRsvp = $isInvitedMember ? $meeting->rsvps->firstWhere('board_member_id', $boardMember->id) : null;

        $nextStep = null;

        // Role-appropriate priority for invited board members
        if ($isInvitedMember && ! $canRecordAttendance) {
            if ($userRsvp === null && ! $isPastMeeting) {
                $nextStep = [
                    'key' => 'rsvp',
                    'label' => 'Reply to the meeting invitation',
                    'status' => 'todo',
                    'detail' => "Let the secretary know whether you're attending, or send apologies.",
                    'action_label' => 'Respond',
                    'action_url' => "/governance/meetings/{$meeting->id}?tab=attendance",
                    'blocked_by' => null,
                ];
            } elseif ($pack !== null && $pack->distributed_at && ! $pack->hasMemberRead($boardMember->id)) {
                $nextStep = [
                    'key' => 'pack_read',
                    'label' => 'Read the board pack',
                    'status' => 'todo',
                    'detail' => "Read the board pack and confirm you've read it before the meeting.",
                    'action_label' => 'Read pack',
                    'action_url' => "/governance/packs/{$pack->id}",
                    'blocked_by' => null,
                ];
            } else {
                $openResolutions = $visibleResolutions
                    ->whereIn('status', ['open', 'voting_open'])
                    ->filter(fn (Resolution $resolution) => ($resolution->purpose ?? 'decision') === 'decision');
                if ($openResolutions->isNotEmpty()) {
                    // Voting or declaring a conflict both settle a paper for this member.
                    $settledIds = Vote::query()
                        ->whereIn('resolution_id', $openResolutions->pluck('id'))
                        ->where('board_member_id', $boardMember->id)
                        ->pluck('resolution_id')
                        ->merge(ConflictDeclaration::query()
                            ->whereIn('resolution_id', $openResolutions->pluck('id'))
                            ->where('board_member_id', $boardMember->id)
                            ->pluck('resolution_id'))
                        ->map(fn ($id) => (int) $id)
                        ->unique();
                    if ($settledIds->count() < $openResolutions->count()) {
                        $nextStep = [
                            'key' => 'vote_resolutions',
                            'label' => 'Vote on resolutions',
                            'status' => 'todo',
                            'detail' => 'Read each paper and vote before voting closes.',
                            'action_label' => 'Vote',
                            'action_url' => "/governance/meetings/{$meeting->id}?tab=resolutions",
                            'blocked_by' => null,
                        ];
                    }
                }
            }
        }

        if (! $nextStep) {
            $nextStep = $items->first(fn (array $item) => ! in_array($item['status'], ['done', 'blocked', 'not_applicable'], true))
                ?? $items->first(fn (array $item) => $item['status'] === 'blocked');

            if ($nextStep && $nextStep['key'] === 'quorum' && ! $canRecordAttendance) {
                $nextStep['label'] = 'Waiting for attendance to be recorded';
                $nextStep['detail'] = 'The secretary or chair records attendance at the meeting.';
                $nextStep['action_label'] = 'View attendance';
                $nextStep['blocked_by'] = 'Waiting for the secretary to record attendance';
            }
        }

        // Every step carries its status in words, so no screen shows a raw key.
        $withLabel = fn (array $step): array => [...$step, 'status_label' => self::checklistStatusLabel((string) $step['status'])];

        return [
            'counts' => [
                'done' => $items->where('status', 'done')->count(),
                'remaining' => $items->whereIn('status', ['todo', 'in_progress'])->count(),
                'blocked' => $items->where('status', 'blocked')->count(),
            ],
            'next_step' => $nextStep ? $withLabel($nextStep) : null,
            'items' => $items->map($withLabel)->values()->all(),
        ];
    }

    /** A meeting checklist step's status in plain words. */
    public static function checklistStatusLabel(string $status): string
    {
        return match ($status) {
            'done' => 'Done',
            'in_progress' => 'In progress',
            'todo' => 'To do',
            'blocked' => 'Waiting on an earlier step',
            'not_applicable' => 'Not needed',
            default => GovernanceLabels::humanise($status),
        };
    }

    protected function previousMeeting(GovernanceMeeting $meeting): ?GovernanceMeeting
    {
        $query = GovernanceMeeting::query()
            ->where('scheduled_at', '<', $meeting->scheduled_at)
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('scheduled_at');

        if ($meeting->board_committee_id) {
            $query->where('board_committee_id', $meeting->board_committee_id);
        } else {
            $query->whereNull('board_committee_id')
                ->where('meeting_type', $meeting->meeting_type);
        }

        return $query->first();
    }

    protected function previousMeetingOpenActions(?GovernanceMeeting $meeting): Collection
    {
        if (! $meeting || ! Schema::hasTable('action_items')) {
            return collect();
        }

        $resolutionIds = $meeting->resolutions()->pluck('id');

        return ActionItem::query()
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->where(function ($query) use ($meeting, $resolutionIds) {
                $query
                    ->where(function ($subQuery) use ($meeting) {
                        $subQuery->where('source_type', 'meeting')
                            ->where('source_id', $meeting->id);
                    })
                    ->orWhere(function ($subQuery) use ($resolutionIds) {
                        $subQuery->where('source_type', 'resolution')
                            ->whereIn('source_id', $resolutionIds);
                    });
            })
            ->get();
    }

    protected function meetingActions(?User $user): Collection
    {
        if (! Schema::hasTable('governance_meetings')) {
            return collect();
        }

        $meetingQuery = GovernanceMeeting::query()
            ->with(['boardPack', 'minutes', 'chair.user', 'secretary.user'])
            ->withCount(['agendaItems', 'attendances'])
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->orderBy('scheduled_at');

        if ($user !== null) {
            app(GovernanceRecordAccessService::class)->scopeMeetings($meetingQuery, $user);
        }

        $meetings = $meetingQuery->get();

        $actions = collect();
        $canManagePacks = $user !== null && $this->boardPackAccess->canManage($user);

        foreach ($meetings as $meeting) {
            // NZ calendar days until the meeting (0 = today, negative = held).
            $daysToMeeting = GovernanceWording::daysFromToday($meeting->scheduled_at);
            $isSoon = $daysToMeeting !== null && $daysToMeeting <= 7;
            $isPast = $daysToMeeting !== null && $daysToMeeting < 0;
            $hasStartedToday = $daysToMeeting !== null && $daysToMeeting <= 0;
            $minutesSettled = in_array($meeting->minutes?->status, ['approved', 'signed', 'archived'], true)
                || in_array($meeting->status, ['minutes_approved', 'minutes_signed'], true);
            $title = (string) $meeting->title;

            // Meeting administration tasks are priorities only for people who
            // can carry them out (the same abilities the meeting workspace
            // uses); ordinary members never see "Record attendance" or
            // "Write minutes" work they cannot do. No viewer = board-wide.
            $canAdminister = $user === null || $user->can('update', $meeting);
            $canDraftMinutes = $user === null || $user->can('manageMinutes', $meeting);
            $canApproveMinutes = $user === null || $user->can('approveMinutes', $meeting);
            $canSignMinutes = $user === null || $user->can('signMinutes', $meeting);

            if ($canAdminister && $meeting->agenda_items_count === 0 && ! $minutesSettled) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:agenda",
                    'Meetings',
                    "Add agenda items for {$title}",
                    $this->meetingWhen($meeting).' No agenda items have been added yet.',
                    $isSoon ? 'critical' : 'high',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Open meeting',
                    "/governance/meetings/{$meeting->id}?tab=agenda",
                    $meeting->chair?->user?->name
                ));
            }

            if ($canManagePacks && $meeting->agenda_items_count > 0 && $meeting->boardPack === null
                && $daysToMeeting !== null && $daysToMeeting <= 14 && ! $isPast) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:pack-generate",
                    'Meetings',
                    "Prepare the board pack for {$title}",
                    $this->meetingWhen($meeting).' The agenda is ready but the board pack hasn\'t been prepared.',
                    $isSoon ? 'high' : 'medium',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Open meeting',
                    "/governance/meetings/{$meeting->id}",
                    $meeting->chair?->user?->name
                ));
            }

            if ($canManagePacks && $meeting->boardPack !== null && $meeting->boardPack->distributed_at === null && $isSoon && ! $isPast) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:pack-distribute",
                    'Meetings',
                    "Send the board pack for {$title}",
                    $this->meetingWhen($meeting).' The board pack is ready but hasn\'t been sent to members.',
                    'high',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Open board pack',
                    "/governance/packs/{$meeting->boardPack->id}",
                    $meeting->chair?->user?->name
                ));
            }

            // Attendance can only be recorded once the meeting is under way, so
            // this is raised on (or after) the meeting day — never a week early.
            $quorum = $hasStartedToday && $canAdminister && ! $minutesSettled ? $meeting->calculateQuorum() : null;
            if ($quorum !== null && ! $quorum['met']) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:quorum",
                    'Meetings',
                    "Record who attended {$title}",
                    "{$quorum['present']} of the {$quorum['required']} members needed for decisions to be valid are recorded as present.",
                    'high',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Record attendance',
                    "/governance/meetings/{$meeting->id}?tab=attendance",
                    $meeting->secretary?->user?->name ?? $meeting->chair?->user?->name
                ));
            }

            if ($canDraftMinutes && $isPast && $meeting->minutes === null) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:minutes-draft",
                    'Meetings',
                    "Write the minutes for {$title}",
                    'The meeting was on '.GovernanceLabels::date($meeting->scheduled_at).' and no minutes have been started.',
                    'critical',
                    'overdue',
                    $meeting->scheduled_at,
                    'Open minutes',
                    "/governance/meetings/{$meeting->id}?tab=minutes",
                    $meeting->secretary?->user?->name
                ));
            }

            if ($canApproveMinutes && $meeting->minutes !== null && $meeting->minutes->status === 'draft') {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:minutes-approve",
                    'Meetings',
                    "Approve the minutes for {$title}",
                    'The draft minutes are waiting for approval.',
                    'high',
                    $isPast ? 'overdue' : 'pending',
                    $meeting->scheduled_at,
                    'Open minutes',
                    "/governance/meetings/{$meeting->id}?tab=minutes",
                    $meeting->chair?->user?->name
                ));
            }

            if ($canSignMinutes && $meeting->minutes !== null && $meeting->minutes->status === 'approved') {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:minutes-sign",
                    'Meetings',
                    "Sign the minutes for {$title}",
                    'The minutes are approved but not signed yet.',
                    'medium',
                    $isPast ? 'due_soon' : 'pending',
                    $meeting->scheduled_at,
                    'Open minutes',
                    "/governance/meetings/{$meeting->id}?tab=minutes",
                    $meeting->chair?->user?->name
                ));
            }
        }

        return $actions;
    }

    /**
     * Opening and closing voting are chair/secretary jobs: a resolution only
     * becomes a priority for viewers who pass ResolutionPolicy::openVoting /
     * closeVoting AND hold the route permission (governance.resolutions.manage).
     * Members' own votes live in My work, never here.
     */
    protected function resolutionActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('resolutions')) {
            return collect();
        }

        if ($user !== null && ! $user->canDo('governance.resolutions.manage')) {
            return collect();
        }

        $query = Resolution::query()
            ->with(['proposedBy:id,name', 'meeting'])
            ->whereIn('status', ['open', 'draft'])
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
            ->orderBy('deadline');

        if ($user !== null) {
            app(GovernanceRecordAccessService::class)->scopeResolutions($query, $user);
        }

        return $query->get()
            ->filter(fn (Resolution $resolution) => $user === null
                || $user->can($resolution->status === 'open' ? 'closeVoting' : 'openVoting', $resolution))
            ->map(function (Resolution $resolution) use ($user): array {
                $isOpen = $resolution->status === 'open';
                $deadline = $resolution->deadline;
                $dueStatus = $isOpen ? $this->dueStatus($deadline) : 'pending';
                $title = (string) $resolution->title;

                $priority = match (true) {
                    $isOpen && $dueStatus === 'overdue' => 'critical',
                    $isOpen && $dueStatus === 'due_soon' => 'high',
                    default => 'medium',
                };

                $forDecision = ($resolution->purpose ?? 'decision') === 'decision';

                [$heading, $detail] = match (true) {
                    ! $isOpen && $forDecision => [
                        "Draft resolution: {$title}",
                        'Voting hasn\'t opened yet. Open voting when the paper is ready.',
                    ],
                    ! $isOpen => [
                        "Draft paper: {$title}",
                        GovernanceLabels::label('resolution_purpose', $resolution->purpose)
                            .' — publish it to members when it\'s ready. It doesn\'t go to a vote.',
                    ],
                    $deadline === null => [
                        "Open for voting: {$title}",
                        'No voting deadline has been set. Close voting when the board is ready to record the result.',
                    ],
                    $deadline->isPast() => [
                        "Voting has ended: {$title}",
                        'The voting deadline was '.GovernanceLabels::date($deadline, true).'. Close voting to record the result.',
                    ],
                    default => [
                        'Voting closes '.GovernanceWording::relativeDay($deadline).": {$title}",
                        'Voting closes '.GovernanceLabels::date($deadline, true).'. Close voting after that to record the result.',
                    ],
                };

                $href = $user !== null
                    ? $this->workQuery->decisionWorkspaceHref($user, $resolution)
                    : "/governance/resolutions/{$resolution->id}";

                return $this->makeAction(
                    "resolution:{$resolution->id}",
                    GovernanceArea::Resolutions,
                    $heading,
                    $detail,
                    $priority,
                    $dueStatus,
                    $deadline,
                    'Open paper',
                    $href,
                    $resolution->proposedBy?->name,
                    kind: GovernanceWorkKind::Act,
                    source: [
                        'type' => 'resolution',
                        'id' => $resolution->id,
                        'reference' => (string) ($resolution->resolution_reference ?? ''),
                        'href' => "/governance/resolutions/{$resolution->id}",
                    ],
                );
            })
            ->values();
    }

    /** Risks above the board's limit — only for viewers who can open the risk register. */
    protected function riskActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('risk_register_entries')) {
            return collect();
        }

        if ($user !== null && ! $user->canDo('governance.risks.view')) {
            return collect();
        }

        $risks = RiskRegisterEntry::query()
            ->with('riskOwner:id,name')
            ->active()
            ->where('within_appetite', false)
            ->orderByDesc('residual_score')
            ->get();

        return $risks->map(function (RiskRegisterEntry $risk): array {
            $dueStatus = $this->dueStatus($risk->next_review_date);
            $priority = $risk->residual_score >= 20 || $dueStatus === 'overdue' ? 'critical' : 'high';
            $reviewDate = $risk->next_review_date?->toDateString();

            $detail = $risk->appetite_threshold !== null
                ? "Risk after controls is {$risk->residual_score} — the board's limit is {$risk->appetite_threshold}."
                : 'This risk is higher than the board has agreed to accept.';
            if ($reviewDate !== null) {
                $detail .= $dueStatus === 'overdue'
                    ? ' Its review was due on '.GovernanceLabels::date($reviewDate).'.'
                    : ' Next review '.GovernanceWording::relativeDay($reviewDate).' ('.GovernanceLabels::date($reviewDate).').';
            }

            return $this->makeAction(
                "risk:{$risk->id}",
                GovernanceArea::Risks,
                "Risk above the board's limit: {$risk->title}",
                $detail,
                $priority,
                $dueStatus,
                $risk->next_review_date,
                'Open risk',
                "/governance/risks/{$risk->id}",
                $risk->riskOwner?->name,
                kind: GovernanceWorkKind::Know,
                source: [
                    'type' => 'risk',
                    'id' => $risk->id,
                    'reference' => (string) ($risk->risk_reference ?? ''),
                    'href' => "/governance/risks/{$risk->id}",
                ],
            );
        })->values();
    }

    /** Requirements due within 30 days or overdue — only for viewers who can open Compliance. */
    protected function complianceActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('compliance_obligations')) {
            return collect();
        }

        if ($user !== null && ! $user->can('viewAny', ComplianceObligation::class)) {
            return collect();
        }

        $obligations = ComplianceObligation::query()
            ->with('owner:id,name')
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->whereDate('due_date', '<=', now()->addDays(30))
            ->orderBy('due_date')
            ->get();

        return $obligations->map(function (ComplianceObligation $obligation): array {
            $dueStatus = $this->dueStatus($obligation->due_date);
            $priority = match ($dueStatus) {
                'overdue' => 'critical',
                'due_soon' => 'high',
                default => 'medium',
            };
            $dueDate = $obligation->due_date?->toDateString();
            $title = (string) $obligation->obligation_title;

            $heading = $dueStatus === 'overdue'
                ? "Requirement overdue: {$title}"
                : 'Requirement due '.GovernanceWording::relativeDay($dueDate).": {$title}";

            return $this->makeAction(
                "compliance:{$obligation->id}",
                GovernanceArea::Compliance,
                $heading,
                GovernanceLabels::label('compliance_framework', $obligation->framework)
                    .' · '.($dueStatus === 'overdue' ? 'Was due ' : 'Due ').GovernanceLabels::date($dueDate),
                $priority,
                $dueStatus,
                $obligation->due_date,
                'Open requirement',
                "/governance/compliance/{$obligation->id}",
                $obligation->owner?->name,
                kind: GovernanceWorkKind::Act,
                source: [
                    'type' => 'compliance',
                    'id' => $obligation->id,
                    'reference' => (string) ($obligation->obligation_code ?? ''),
                    'href' => "/governance/compliance/{$obligation->id}",
                ],
            );
        })->values();
    }

    /** Budgets and budget changes waiting for the board — only for viewers who can open Budgets. */
    protected function budgetActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('budgets')) {
            return collect();
        }

        if ($user !== null && ! $user->can('viewAny', Budget::class)) {
            return collect();
        }

        $actions = collect();

        $proposedBudgets = Budget::query()
            ->where('status', 'proposed')
            ->orderByDesc('proposed_at')
            ->limit(4)
            ->get();

        foreach ($proposedBudgets as $budget) {
            $year = $this->financialYear($budget->fiscal_year);

            $actions->push($this->makeAction(
                "budget:{$budget->id}:proposed",
                GovernanceArea::Budgets,
                "Budget waiting for the board: {$budget->title}",
                trim(($year !== null ? "The {$year} budget" : 'This budget')
                    .($budget->total_budget !== null ? ' of '.GovernanceLabels::money($budget->total_budget) : '')
                    .' is waiting for the board\'s approval.'),
                'high',
                'pending',
                null,
                'Open budget',
                "/governance/budgets/{$budget->id}",
                null
            ));
        }

        if (Schema::hasTable('budget_adjustments')) {
            $pendingAdjustments = BudgetAdjustment::query()
                ->with('budget:id,title')
                ->whereIn('status', ['submitted', 'under_review', 'pending_board_approval'])
                ->orderByDesc('created_at')
                ->limit(6)
                ->get();

            foreach ($pendingAdjustments as $adjustment) {
                $priority = $adjustment->threshold_applies ? 'high' : 'medium';
                $budgetTitle = $adjustment->budget?->title ?? 'a budget';
                $reason = trim((string) $adjustment->reason);

                $actions->push($this->makeAction(
                    "budget-adjustment:{$adjustment->id}",
                    GovernanceArea::Budgets,
                    "Budget change waiting for approval: {$budgetTitle}",
                    GovernanceLabels::label('budget_change_type', $adjustment->adjustment_type)
                        .' of '.GovernanceLabels::money($adjustment->amount)
                        .($reason !== '' ? ' — '.Str::limit($reason, 120) : ''),
                    $priority,
                    'pending',
                    null,
                    'Review budget change',
                    // Straight to the budget's changes tab (older ?tab=adjustments links still work).
                    "/governance/budgets/{$adjustment->budget_id}?tab=changes",
                    null
                ));
            }
        }

        return $actions;
    }

    /** Policy reviews due within 30 days or overdue — only for viewers who can open Policies. */
    protected function policyActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('governance_policies')) {
            return collect();
        }

        if ($user !== null && ! $user->can('viewAny', GovernancePolicy::class)) {
            return collect();
        }

        $horizon = now()->addDays(30)->toDateString();

        $policies = GovernancePolicy::query()
            ->with('owner:id,name')
            ->whereIn('status', ['approved', 'active', 'published'])
            ->where(function ($query) use ($horizon) {
                $query->whereDate('next_review_date', '<=', $horizon)
                    ->orWhere(function ($fallback) use ($horizon) {
                        $fallback->whereNull('next_review_date')
                            ->whereDate('review_due', '<=', $horizon);
                    });
            })
            ->get();

        return $policies->map(function (GovernancePolicy $policy): array {
            $reviewDate = ($policy->next_review_date ?? $policy->review_due)?->toDateString();
            $dueStatus = $this->dueStatus($reviewDate);
            $title = (string) $policy->title;

            $heading = $dueStatus === 'overdue'
                ? "Policy review overdue: {$title}"
                : 'Policy review due '.GovernanceWording::relativeDay($reviewDate).": {$title}";

            return $this->makeAction(
                "policy:{$policy->id}:review",
                GovernanceArea::Policies,
                $heading,
                GovernanceLabels::label('policy_category', $policy->category).' policy · '
                    .($dueStatus === 'overdue' ? 'Review was due ' : 'Review due ').GovernanceLabels::date($reviewDate),
                $dueStatus === 'overdue' ? 'high' : 'medium',
                $dueStatus,
                $reviewDate,
                'Open policy',
                "/governance/policies/{$policy->id}",
                $policy->owner?->name,
                kind: GovernanceWorkKind::Know,
                source: [
                    'type' => 'policy',
                    'id' => $policy->id,
                    'reference' => (string) ($policy->policy_code ?? ''),
                    'href' => "/governance/policies/{$policy->id}",
                ],
            );
        })->values();
    }

    /** Open board actions — only for viewers who can open the Actions register. */
    protected function actionItemActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('action_items')) {
            return collect();
        }

        if ($user !== null && ! $user->canDo('governance.actions.view')) {
            return collect();
        }

        $query = ActionItem::query()
            ->with('assignedTo:id,name')
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->orderBy('due_date');

        if ($user !== null) {
            app(GovernanceRecordAccessService::class)->scopeActionItems($query, $user);
        }

        $items = $query->get();

        return $items->map(function (ActionItem $item) use ($user): array {
            $isBlocked = $item->status === 'blocked';
            $dueStatus = $isBlocked ? 'blocked' : $this->dueStatus($item->due_date);
            $priority = match (true) {
                $dueStatus === 'overdue' && in_array($item->priority, ['critical', 'high'], true) => 'critical',
                $dueStatus === 'overdue' => 'high',
                $isBlocked => 'high',
                $item->priority === 'critical' => 'critical',
                $item->priority === 'high' => 'high',
                default => 'medium',
            };

            $title = Str::limit(trim((string) ($item->title ?: $item->description)) ?: 'Action', 80);
            $dueDate = $item->due_date?->toDateString();

            $heading = match (true) {
                $isBlocked => "Action blocked: {$title}",
                $dueStatus === 'overdue' => "Action overdue: {$title}",
                $dueDate !== null => 'Action due '.GovernanceWording::relativeDay($dueDate).": {$title}",
                default => "Open action: {$title}",
            };

            $detail = match (true) {
                $isBlocked => 'Blocked — '.rtrim((string) ($item->blocked_reason ?: 'no reason recorded'), '.').'.',
                $dueStatus === 'overdue' => 'Was due '.GovernanceLabels::date($dueDate).'.',
                $dueDate !== null => 'Due '.GovernanceLabels::date($dueDate).'.',
                default => 'No due date set.',
            };

            // A written title means the description adds detail worth showing.
            if ($item->getRawOriginal('title') && $item->description && ! $isBlocked) {
                $detail .= ' '.Str::limit((string) $item->description, 120);
            }

            $isMine = $user !== null && (int) $item->assigned_to === (int) $user->id;

            return $this->makeAction(
                "action-item:{$item->id}",
                GovernanceArea::ActionItems,
                $heading,
                $detail,
                $priority,
                $dueStatus,
                $item->due_date,
                $isMine ? 'Update action' : 'Open action',
                "/governance/actions/{$item->id}",
                $item->assignedTo?->name,
                assigneeUserId: $item->assigned_to,
                kind: GovernanceWorkKind::Act,
                source: [
                    'type' => 'action_item',
                    'id' => $item->id,
                    'reference' => (string) ($item->action_reference ?? ''),
                    'href' => "/governance/actions/{$item->id}",
                ],
            );
        })->values();
    }

    protected function makeAction(
        string $id,
        GovernanceArea|string $area,
        string $title,
        string $detail,
        string $priority,
        string $status,
        mixed $dueDate,
        string $actionLabel,
        string $actionUrl,
        ?string $owner = null,
        ?int $assigneeUserId = null,
        ?int $boardMemberId = null,
        GovernanceWorkKind|string $kind = GovernanceWorkKind::Act,
        ?array $source = null,
    ): array {
        $parsedDueDate = $this->parseDate($dueDate);
        $areaLabel = $area instanceof GovernanceArea ? $area->label() : (GovernanceArea::tryFrom($area)?->label() ?? (string) $area);
        $areaKey = $area instanceof GovernanceArea ? $area->value : strtolower(str_replace(' ', '_', (string) $area));
        $kindStr = $kind instanceof GovernanceWorkKind ? $kind->value : (string) $kind;

        return [
            'id' => $id,
            'kind' => $kindStr,
            'area' => $areaLabel,
            'area_key' => $areaKey,
            'title' => $title,
            'detail' => $detail,
            'priority' => $priority,
            'status' => $status,
            'due_date' => $parsedDueDate?->toDateString(),
            'due_at' => $parsedDueDate?->toIso8601String(),
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'owner' => $owner,
            'assignee_user_id' => $assigneeUserId,
            'board_member_id' => $boardMemberId,
            'source' => $source ?? [
                'type' => $areaKey,
                'id' => (int) filter_var($id, FILTER_SANITIZE_NUMBER_INT),
                // Internal ids ("meeting:12:agenda") are not references — never show them.
                'reference' => '',
                'href' => $actionUrl,
            ],
        ];
    }

    /** "In 4 days — 18 September 2026." for meeting preparation details. */
    protected function meetingWhen(GovernanceMeeting $meeting): string
    {
        if ($meeting->scheduled_at === null) {
            return 'The meeting date is still to be confirmed.';
        }

        return 'The meeting is '.GovernanceWording::relativeDay($meeting->scheduled_at)
            .' ('.GovernanceLabels::date($meeting->scheduled_at).').';
    }

    protected function financialYear(mixed $fiscalYear): ?string
    {
        if ($fiscalYear === null || $fiscalYear === '') {
            return null;
        }

        try {
            $label = GovernanceLabels::financialYear(is_numeric($fiscalYear) ? (int) $fiscalYear : (string) $fiscalYear);
        } catch (\Throwable) {
            return null;
        }

        return $label === 'Not set' ? null : $label;
    }

    /** Due state from NZ calendar days: overdue (past), due soon (≤ 7 days), pending. */
    protected function dueStatus(mixed $date): string
    {
        $days = GovernanceWording::daysFromToday($date instanceof \Carbon\CarbonInterface || is_string($date) ? $date : null);
        if ($days === null) {
            return 'pending';
        }

        return match (true) {
            $days < 0 => 'overdue',
            $days <= 7 => 'due_soon',
            default => 'pending',
        };
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    protected function actionRank(array $action): int
    {
        $priority = match ($action['priority']) {
            'critical' => 300,
            'high' => 200,
            'medium' => 100,
            default => 50,
        };

        $status = match ($action['status']) {
            'overdue' => 40,
            'due_soon' => 20,
            default => 10,
        };

        $dueDateWeight = 0;
        if (! empty($action['due_date'])) {
            $days = now()->startOfDay()->diffInDays(Carbon::parse($action['due_date'])->startOfDay(), false);
            $dueDateWeight = max(0, 30 - max($days, 0));
        }

        return $priority + $status + $dueDateWeight;
    }
}
