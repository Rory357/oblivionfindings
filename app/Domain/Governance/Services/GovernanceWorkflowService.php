<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Enums\GovernanceArea;
use App\Domain\Governance\Enums\GovernanceWorkKind;
use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

    public function dashboardWorkflow(User|int|null $user = null, int $limit = 15): array
    {
        if (is_int($user)) {
            $user = User::find($user);
        }

        $actions = collect()
            ->merge($this->meetingActions($user))
            ->merge($this->resolutionActions($user))
            ->merge($this->riskActions())
            ->merge($this->complianceActions())
            ->merge($this->budgetActions())
            ->merge($this->actionItemActions($user));

        $total = $actions->count();
        $critical = $actions->where('priority', 'critical')->count();
        $overdue = $actions->where('status', 'overdue')->count();
        $actionItemsOverdue = $actions->filter(fn (array $a) => ($a['source']['type'] ?? null) === 'action_item' && ($a['status'] ?? null) === 'overdue')->count();

        $ranked = $actions
            ->sortByDesc(fn (array $action) => $this->actionRank($action))
            ->values();

        return [
            'summary' => [
                'total' => $total,
                'critical' => $critical,
                'overdue' => $overdue,
                'action_items_overdue' => $actionItemsOverdue,
            ],
            'actions' => $ranked->take($limit)->values()->all(),
        ];
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

        $agendaCount = $meeting->agendaItems->count();
        $attendanceCount = $meeting->attendances->count();
        $quorum = $meeting->calculateQuorum();
        $pack = $user
            ? $this->boardPackAccess->visiblePack($user, $meeting->boardPack)
            : null;
        $includePackItems = $user !== null
            && ($this->boardPackAccess->canManage($user) || $pack !== null);
        $ceoReport = $meeting->ceoReport;
        $minutes = $meeting->minutes;
        $resolutions = $meeting->resolutions->whereIn('status', ['draft', 'open'])->count();
        $isPastMeeting = $meeting->scheduled_at?->isPast() ?? false;
        $previousMeeting = $this->previousMeeting($meeting);
        $previousOpenActions = $this->previousMeetingOpenActions($previousMeeting);

        $minutesStatus = $minutes?->status;
        $minutesApproved = in_array($minutesStatus, ['approved', 'signed', 'archived'], true);
        $minutesSigned = in_array($minutesStatus, ['signed', 'archived'], true);
        $ceoSubmitted = in_array($ceoReport?->status, ['submitted', 'included_in_pack'], true);
        $packDistributedCount = count(array_unique($pack?->distributed_to ?? []));
        $packReadCount = $pack?->readCount() ?? 0;

        $items = collect([
            [
                'key' => 'agenda',
                'label' => 'Agenda prepared',
                'status' => $agendaCount > 0 ? 'done' : 'todo',
                'detail' => $agendaCount > 0
                    ? "{$agendaCount} agenda item(s) ready for board pre-read."
                    : 'Add agenda items so pack generation has content.',
                'action_label' => 'Open Agenda',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=agenda",
                'blocked_by' => null,
            ],
            [
                'key' => 'quorum',
                'label' => 'Attendance and quorum confirmed',
                'status' => $quorum['met'] ? 'done' : ($attendanceCount > 0 ? 'in_progress' : 'todo'),
                'detail' => $attendanceCount > 0
                    ? "Present {$quorum['present']} / Required {$quorum['required']}."
                    : 'Attendance is unrecorded. Will be marked when meeting commences.',
                'action_label' => ($user && $user->can('update', $meeting)) ? 'Record Attendance' : 'View Attendance',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=attendance",
                'blocked_by' => ($user && $user->can('update', $meeting)) ? null : ($attendanceCount > 0 ? null : 'Awaiting secretary to record attendance'),
            ],
            [
                'key' => 'ceo_report',
                'label' => 'CEO report ready',
                'status' => (! $meeting->isFullBoard() && ! $meeting->ceoReport && ! $meeting->ceo_report_deadline)
                    ? 'not_applicable'
                    : ($ceoSubmitted ? 'done' : 'todo'),
                'detail' => (! $meeting->isFullBoard() && ! $meeting->ceoReport && ! $meeting->ceo_report_deadline)
                    ? 'CEO report is not required for committee meetings (Not applicable).'
                    : ($ceoSubmitted
                        ? 'CEO report has been submitted for board pre-read.'
                        : ($meeting->ceo_report_deadline
                            ? 'CEO report is still pending. Due '.$meeting->ceo_report_deadline->format('j M Y g:i A').'.'
                            : 'CEO report is pending for this meeting.')),
                'action_label' => 'Open CEO Report',
                'action_url' => $ceoReport ? "/governance/ceo-reports/{$ceoReport->id}" : '/governance/ceo-reports',
                'blocked_by' => null,
            ],
            [
                'key' => 'pack_generated',
                'label' => 'Board pack generated',
                'status' => $pack !== null ? 'done' : ($agendaCount > 0 ? 'todo' : 'blocked'),
                'detail' => $pack !== null
                    ? 'Pack generated and available for distribution.'
                    : 'Generate board pack once agenda is ready.',
                'action_label' => 'Open Pack Section',
                'action_url' => "/governance/meetings/{$meeting->id}",
                'blocked_by' => $agendaCount > 0 ? null : 'Agenda is empty',
            ],
            [
                'key' => 'pack_distributed',
                'label' => 'Board pack distributed',
                'status' => $pack?->distributed_at ? 'done' : ($pack !== null ? 'todo' : 'blocked'),
                'detail' => $pack?->distributed_at
                    ? "Pack sent to {$packDistributedCount} board member(s); {$packReadCount} marked as read."
                    : 'Distribute pack to start pre-read and voting preparation.',
                'action_label' => $pack !== null ? 'Open Pack' : 'Open Meeting',
                'action_url' => $pack !== null
                    ? "/governance/packs/{$pack->id}"
                    : "/governance/meetings/{$meeting->id}",
                'blocked_by' => $pack !== null ? null : 'Pack not generated',
            ],
            [
                'key' => 'resolutions',
                'label' => 'Decision resolutions prepared',
                'status' => $resolutions > 0 ? 'done' : 'todo',
                'detail' => $resolutions > 0
                    ? "{$resolutions} resolution(s) ready/open."
                    : 'No decision resolutions tabled yet for this meeting.',
                'action_label' => 'Open Resolutions',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=resolutions",
                'blocked_by' => null,
            ],
            [
                'key' => 'minutes_drafted',
                'label' => 'Minutes drafted',
                'status' => $minutes !== null ? 'done' : ($isPastMeeting ? 'todo' : 'blocked'),
                'detail' => $minutes !== null
                    ? "Minutes currently {$minutes->status}."
                    : 'Draft minutes immediately after the meeting.',
                'action_label' => 'Open Minutes',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=minutes",
                'blocked_by' => $minutes === null && ! $isPastMeeting ? 'Meeting has not occurred yet' : null,
            ],
            [
                'key' => 'minutes_approved',
                'label' => 'Minutes approved',
                'status' => $minutesApproved ? 'done' : ($minutes !== null ? 'todo' : 'blocked'),
                'detail' => $minutesApproved
                    ? 'Minutes approved by chair/delegate.'
                    : 'Submit and approve draft minutes.',
                'action_label' => 'Review Minutes',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=minutes",
                'blocked_by' => $minutes !== null ? null : 'Minutes not drafted',
            ],
            [
                'key' => 'minutes_signed',
                'label' => 'Minutes signed and archived',
                'status' => $minutesSigned ? 'done' : ($minutesApproved ? 'todo' : 'blocked'),
                'detail' => $minutesSigned
                    ? 'Minutes are signed/archived for audit trail.'
                    : 'Sign approved minutes to close the meeting cycle.',
                'action_label' => 'Finalize Minutes',
                'action_url' => "/governance/meetings/{$meeting->id}?tab=minutes",
                'blocked_by' => $minutesApproved ? null : 'Minutes not approved',
            ],
            [
                'key' => 'follow_through',
                'label' => 'Previous meeting follow-through reviewed',
                'status' => $previousOpenActions->isEmpty() ? 'done' : 'todo',
                'detail' => $previousMeeting
                    ? ($previousOpenActions->isEmpty()
                        ? "No open action items remain from {$previousMeeting->title}."
                        : "{$previousOpenActions->count()} action item(s) are still open from {$previousMeeting->title}.")
                    : 'No previous meeting in this committee/cycle.',
                'action_label' => 'Open Action Items',
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
        $canRecordAttendance = $user !== null && $user->can('update', $meeting);

        $nextStep = null;

        // Role-appropriate priority for invited board members
        if ($isInvitedMember && ! $canRecordAttendance) {
            if ($userRsvp === null && ! $isPastMeeting) {
                $nextStep = [
                    'key' => 'rsvp',
                    'label' => 'Submit meeting RSVP',
                    'status' => 'todo',
                    'detail' => 'Confirm your attendance or send apologies for this meeting.',
                    'action_label' => 'Submit RSVP',
                    'action_url' => "/governance/meetings/{$meeting->id}?tab=attendance",
                    'blocked_by' => null,
                ];
            } elseif ($pack !== null && $pack->distributed_at && ! in_array($user->id, $pack->read_by_user_ids ?? [], true)) {
                $nextStep = [
                    'key' => 'pack_read',
                    'label' => 'Read board pack',
                    'status' => 'todo',
                    'detail' => 'Review distributed board pack and papers prior to the meeting.',
                    'action_label' => 'Read Pack',
                    'action_url' => "/governance/packs/{$pack->id}",
                    'blocked_by' => null,
                ];
            } else {
                $openResolutions = $meeting->resolutions->whereIn('status', ['open', 'voting_open']);
                if ($openResolutions->isNotEmpty()) {
                    $votedCount = \App\Domain\Governance\Models\ResolutionVote::whereIn('resolution_id', $openResolutions->pluck('id'))
                        ->where('board_member_id', $boardMember->id)
                        ->count();
                    if ($votedCount < $openResolutions->count()) {
                        $nextStep = [
                            'key' => 'vote_resolutions',
                            'label' => 'Review and vote on resolutions',
                            'status' => 'todo',
                            'detail' => 'Review proposed motions and cast your vote before the deadline.',
                            'action_label' => 'Review & Vote',
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
                $nextStep['label'] = 'Awaiting attendance recording';
                $nextStep['detail'] = 'Attendance will be recorded by the board secretary or chair.';
                $nextStep['action_label'] = 'View Attendance';
                $nextStep['blocked_by'] = 'Awaiting secretary to record attendance';
            }
        }

        return [
            'counts' => [
                'done' => $items->where('status', 'done')->count(),
                'remaining' => $items->whereIn('status', ['todo', 'in_progress'])->count(),
                'blocked' => $items->where('status', 'blocked')->count(),
            ],
            'next_step' => $nextStep,
            'items' => $items->values()->all(),
        ];
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
            ->with(['boardPack', 'minutes', 'chair.user'])
            ->withCount(['agendaItems', 'attendances'])
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->orderBy('scheduled_at');

        if ($user !== null) {
            app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class)
                ->scopeMeetings($meetingQuery, $user);
        }

        $meetings = $meetingQuery->get();

        $actions = collect();
        $canManagePacks = $user !== null && $this->boardPackAccess->canManage($user);

        foreach ($meetings as $meeting) {
            $daysToMeeting = (int) now()->startOfDay()->diffInDays($meeting->scheduled_at?->startOfDay(), false);
            $isSoon = $daysToMeeting <= 7;
            $isPast = $daysToMeeting < 0;
            $quorum = $meeting->calculateQuorum();

            if ($meeting->agenda_items_count === 0) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:agenda",
                    'Meetings',
                    "Prepare agenda for {$meeting->title}",
                    'No agenda items are recorded yet.',
                    $isSoon ? 'critical' : 'high',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Open Meeting',
                    "/governance/meetings/{$meeting->id}?tab=agenda",
                    $meeting->chair?->user?->name
                ));
            }

            if ($canManagePacks && $meeting->agenda_items_count > 0 && $meeting->boardPack === null && $daysToMeeting <= 14) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:pack-generate",
                    'Meetings',
                    "Generate board pack for {$meeting->title}",
                    'Agenda is ready but no board pack has been generated.',
                    $isSoon ? 'high' : 'medium',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Open Meeting',
                    "/governance/meetings/{$meeting->id}",
                    $meeting->chair?->user?->name
                ));
            }

            if ($canManagePacks && $meeting->boardPack !== null && $meeting->boardPack->distributed_at === null && $isSoon) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:pack-distribute",
                    'Meetings',
                    "Distribute board pack for {$meeting->title}",
                    'Pack generated but not distributed to board members.',
                    'high',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Open Pack',
                    "/governance/packs/{$meeting->boardPack->id}",
                    $meeting->chair?->user?->name
                ));
            }

            if (! $quorum['met'] && $isSoon) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:quorum",
                    'Meetings',
                    "Confirm attendance/quorum for {$meeting->title}",
                    "Present {$quorum['present']} / Required {$quorum['required']}.",
                    $isSoon ? 'high' : 'medium',
                    $this->dueStatus($meeting->scheduled_at),
                    $meeting->scheduled_at,
                    'Record Attendance',
                    "/governance/meetings/{$meeting->id}?tab=attendance",
                    $meeting->chair?->user?->name
                ));
            }

            if ($isPast && $meeting->minutes === null) {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:minutes-draft",
                    'Meetings',
                    "Draft minutes for {$meeting->title}",
                    'Meeting has passed but minutes are not drafted.',
                    'critical',
                    'overdue',
                    $meeting->scheduled_at,
                    'Open Minutes',
                    "/governance/meetings/{$meeting->id}?tab=minutes",
                    $meeting->secretary?->user?->name
                ));
            }

            if ($meeting->minutes !== null && $meeting->minutes->status === 'draft') {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:minutes-approve",
                    'Meetings',
                    "Approve minutes for {$meeting->title}",
                    'Draft minutes are waiting for approval.',
                    'high',
                    $isPast ? 'overdue' : 'pending',
                    $meeting->scheduled_at,
                    'Open Minutes',
                    "/governance/meetings/{$meeting->id}?tab=minutes",
                    $meeting->chair?->user?->name
                ));
            }

            if ($meeting->minutes !== null && $meeting->minutes->status === 'approved') {
                $actions->push($this->makeAction(
                    "meeting:{$meeting->id}:minutes-sign",
                    'Meetings',
                    "Sign minutes for {$meeting->title}",
                    'Minutes approved but not yet signed.',
                    'medium',
                    $isPast ? 'due_soon' : 'pending',
                    $meeting->scheduled_at,
                    'Open Minutes',
                    "/governance/meetings/{$meeting->id}?tab=minutes",
                    $meeting->chair?->user?->name
                ));
            }
        }

        return $actions;
    }

    protected function resolutionActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('resolutions')) {
            return collect();
        }

        $query = Resolution::query()
            ->with('proposedBy:id,name')
            ->whereIn('status', ['open', 'draft'])
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
            ->orderBy('deadline');

        if ($user !== null) {
            app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class)
                ->scopeResolutions($query, $user);
        }

        $resolutions = $query->get();

        return $resolutions->map(function (Resolution $resolution): array {
            $dueStatus = $resolution->status === 'open'
                ? $this->dueStatus($resolution->deadline)
                : 'pending';

            $priority = match (true) {
                $resolution->status === 'open' && $dueStatus === 'overdue' => 'critical',
                $resolution->status === 'open' && $dueStatus === 'due_soon' => 'high',
                $resolution->status === 'open' => 'medium',
                default => 'medium',
            };

            $title = $resolution->status === 'open'
                ? "Close voting for {$resolution->resolution_reference}"
                : "Open voting for draft {$resolution->resolution_reference}";

            $detail = $resolution->status === 'open'
                ? "Resolution: {$resolution->title}"
                : "Draft resolution has not entered voting yet: {$resolution->title}";

            return $this->makeAction(
                "resolution:{$resolution->id}",
                GovernanceArea::Resolutions,
                $title,
                $detail,
                $priority,
                $dueStatus,
                $resolution->deadline,
                'Open Resolution',
                "/governance/resolutions/{$resolution->id}",
                $resolution->proposedBy?->name,
                kind: GovernanceWorkKind::Vote,
                source: [
                    'type' => 'resolution',
                    'id' => $resolution->id,
                    'reference' => $resolution->resolution_reference ?? "RES-{$resolution->id}",
                    'href' => "/governance/resolutions/{$resolution->id}",
                ],
            );
        });
    }

    protected function riskActions(): Collection
    {
        if (! Schema::hasTable('risk_register_entries')) {
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

            return $this->makeAction(
                "risk:{$risk->id}",
                GovernanceArea::Risks,
                "Review risk {$risk->risk_reference}",
                "Above appetite (score {$risk->residual_score}): {$risk->title}",
                $priority,
                $dueStatus,
                $risk->next_review_date,
                'Open Risk',
                "/governance/risks/{$risk->id}",
                $risk->riskOwner?->name,
                kind: GovernanceWorkKind::Know,
                source: [
                    'type' => 'risk',
                    'id' => $risk->id,
                    'reference' => $risk->risk_reference ?? "R-{$risk->id}",
                    'href' => "/governance/risks/{$risk->id}",
                ],
            );
        });
    }

    protected function complianceActions(): Collection
    {
        if (! Schema::hasTable('compliance_obligations')) {
            return collect();
        }

        $obligations = ComplianceObligation::query()
            ->with('owner:id,name')
            ->where('status', '!=', 'complete')
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

            return $this->makeAction(
                "compliance:{$obligation->id}",
                GovernanceArea::Compliance,
                "Complete obligation {$obligation->obligation_code}",
                "{$obligation->obligation_title} ({$obligation->getFrameworkLabel()})",
                $priority,
                $dueStatus,
                $obligation->due_date,
                'Open Obligation',
                "/governance/compliance/{$obligation->id}",
                $obligation->owner?->name,
                kind: GovernanceWorkKind::Act,
                source: [
                    'type' => 'compliance',
                    'id' => $obligation->id,
                    'reference' => $obligation->obligation_code ?? "COMP-{$obligation->id}",
                    'href' => "/governance/compliance/{$obligation->id}",
                ],
            );
        });
    }

    protected function budgetActions(): Collection
    {
        if (! Schema::hasTable('budgets')) {
            return collect();
        }

        $actions = collect();

        $proposedBudgets = Budget::query()
            ->where('status', 'proposed')
            ->orderByDesc('proposed_at')
            ->limit(4)
            ->get();

        foreach ($proposedBudgets as $budget) {
            $actions->push($this->makeAction(
                "budget:{$budget->id}:proposed",
                'Budgets',
                "Board decision on proposed budget {$budget->fiscal_year}",
                "{$budget->title} is waiting for board approval.",
                'high',
                'pending',
                null,
                'Open Budget',
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
                $detail = Str::limit($adjustment->reason, 120);

                $actions->push($this->makeAction(
                    "budget-adjustment:{$adjustment->id}",
                    'Budgets',
                    "Review budget adjustment ({$adjustment->adjustment_type})",
                    "\${$adjustment->amount} - {$detail}",
                    $priority,
                    'pending',
                    null,
                    'Open Budget',
                    "/governance/budgets/{$adjustment->budget_id}",
                    null
                ));
            }
        }

        return $actions;
    }

    protected function actionItemActions(?User $user = null): Collection
    {
        if (! Schema::hasTable('action_items')) {
            return collect();
        }

        $query = ActionItem::query()
            ->with('assignedTo:id,name')
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->orderBy('due_date');

        if ($user !== null) {
            app(\App\Domain\Governance\Services\GovernanceRecordAccessService::class)
                ->scopeActionItems($query, $user);
        }

        $items = $query->get();

        return $items->map(function (ActionItem $item): array {
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

            return $this->makeAction(
                "action-item:{$item->id}",
                GovernanceArea::ActionItems,
                "Complete {$item->action_reference}",
                Str::limit($item->description, 120),
                $priority,
                $dueStatus,
                $item->due_date,
                'Open Action',
                "/governance/actions/{$item->id}",
                $item->assignedTo?->name,
                assigneeUserId: $item->assigned_to,
                kind: GovernanceWorkKind::Act,
                source: [
                    'type' => 'action_item',
                    'id' => $item->id,
                    'reference' => $item->action_reference ?? "ACT-{$item->id}",
                    'href' => "/governance/actions/{$item->id}",
                ],
            );
        });
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
                'reference' => $id,
                'href' => $actionUrl,
            ],
        ];
    }

    protected function dueStatus(mixed $date): string
    {
        $carbon = $this->parseDate($date);
        if ($carbon === null) {
            return 'pending';
        }

        $days = now()->startOfDay()->diffInDays($carbon->copy()->startOfDay(), false);

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
