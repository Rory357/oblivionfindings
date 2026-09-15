<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\MeetingRsvp;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Governance\Support\GovernanceWording;
use App\Models\User;
use Carbon\Carbon;

/**
 * The Governance calendar feed, shaped for the shared Site Calendar.
 *
 * Audience: a source is only offered to viewers who can open its register
 * (the route permission of the entries' links) and is scoped like that
 * register — executive-session meetings and their resolutions stay hidden —
 * so no entry links to a page that returns 403.
 *
 * Wording (vocabulary.md): titles are the records' own titles, with no
 * "[Vote deadline]" prefixes; `typeLabel` carries the plain type ("Full board
 * meeting", "Privacy Act 2020"); `eventType` stays null so a raw key never
 * reaches the page; `statusLabel` refines the status chip ("Minutes due").
 */
class GovernanceCalendarQuery
{
    public const SOURCES = ['meetings', 'decisions', 'obligations', 'policies'];

    /** Source → the register permission its entries link into. */
    public const SOURCE_PERMISSIONS = [
        'meetings' => 'governance.meetings.view',
        'decisions' => 'governance.resolutions.view',
        'obligations' => 'governance.compliance.view',
        'policies' => 'governance.policies.view',
    ];

    public function __construct(
        private readonly GovernanceRecordAccessService $recordAccess,
    ) {}

    /**
     * The calendar sources this viewer may see, in display order.
     *
     * @return array<int, string>
     */
    public function sourcesFor(User $viewer): array
    {
        return array_values(array_filter(
            self::SOURCES,
            fn (string $source) => $viewer->canDo(self::SOURCE_PERMISSIONS[$source]),
        ));
    }

    /**
     * Query normalized calendar items for the requested window and viewer.
     *
     * @param  array<int, string>|null  $sources
     * @return array{events: array<int, array<string, mixed>>, totals: array<string, int>, availability: array<string, string>}
     */
    public function queryItems(
        Carbon $start,
        Carbon $end,
        User $viewer,
        ?array $sources = null,
        ?int $committeeId = null,
    ): array {
        $requested = $sources
            ? array_values(array_intersect(self::SOURCES, array_map('trim', $sources)))
            : self::SOURCES;
        $active = array_values(array_intersect($requested, $this->sourcesFor($viewer)));

        $events = [];
        $availability = [];

        foreach ($active as $source) {
            try {
                $sourceEvents = match ($source) {
                    'meetings' => $this->meetingEvents($start, $end, $viewer, $committeeId),
                    'decisions' => $this->decisionEvents($start, $end, $viewer, $committeeId),
                    'obligations' => $this->obligationEvents($start, $end, $viewer),
                    'policies' => $this->policyEvents($start, $end, $viewer),
                };
                $events = array_merge($events, $sourceEvents);
                $availability[$source] = 'available';
            } catch (\Throwable $e) {
                report($e);
                // Shown as "not available" on the page — never as an empty, all-clear period.
                $availability[$source] = 'unavailable';
            }
        }

        $count = fn (callable $filter) => count(array_filter($events, $filter));

        return [
            'events' => $events,
            'totals' => [
                'total' => count($events),
                'meetings' => $count(fn ($e) => $e['source'] === 'meetings'),
                'decisions' => $count(fn ($e) => $e['source'] === 'decisions'),
                'obligations' => $count(fn ($e) => $e['source'] === 'obligations'),
                'policies' => $count(fn ($e) => $e['source'] === 'policies'),
                'overdue' => $count(fn ($e) => $e['status'] === 'overdue'),
                'completed' => $count(fn ($e) => $e['status'] === 'completed'),
                'mine' => $count(fn ($e) => ! empty($e['mine'])),
            ],
            'availability' => $availability,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function meetingEvents(Carbon $start, Carbon $end, User $viewer, ?int $committeeId): array
    {
        $query = GovernanceMeeting::query()
            ->with(['chair.user'])
            ->where('scheduled_at', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->where('scheduled_at', '>=', $start)
                    ->orWhereRaw('DATE_ADD(scheduled_at, INTERVAL COALESCE(duration_minutes, 60) MINUTE) >= ?', [$start]);
            });

        $this->recordAccess->scopeMeetings($query, $viewer);

        if ($committeeId !== null) {
            $query->where('board_committee_id', $committeeId);
        }

        $meetings = $query->orderBy('scheduled_at')->get();

        $boardMember = $viewer->boardMember;
        $respondedIds = $boardMember !== null && $meetings->isNotEmpty()
            ? MeetingRsvp::query()
                ->where('board_member_id', $boardMember->id)
                ->whereIn('governance_meeting_id', $meetings->pluck('id'))
                ->pluck('governance_meeting_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $events = [];
        foreach ($meetings as $meeting) {
            $startTime = $meeting->scheduled_at;
            $endTime = $startTime?->copy()->addMinutes($meeting->duration_minutes ?? 60);
            [$status, $statusLabel] = $this->meetingStatus($meeting, $endTime);

            // "Mine": meetings I run or created, and meetings I'm invited to or have replied to.
            $isMine = (int) $meeting->created_by === (int) $viewer->id
                || ($boardMember !== null && (
                    (int) $meeting->chair_id === (int) $boardMember->id
                    || (int) $meeting->secretary_id === (int) $boardMember->id
                    || in_array((int) $meeting->id, $respondedIds, true)
                    || $meeting->isInvited($boardMember)
                ));

            $events[] = [
                'id' => "governance:meeting:{$meeting->id}",
                'source' => 'meetings',
                'group' => 'auto',
                'title' => $meeting->title,
                'start' => $startTime?->toIso8601String(),
                'end' => $endTime?->toIso8601String(),
                'allDay' => false,
                'status' => $status,
                'statusLabel' => $statusLabel,
                'owner' => $meeting->chair?->user ? [
                    'id' => $meeting->chair->user->id,
                    'name' => $meeting->chair->user->name,
                ] : null,
                'room' => $meeting->location,
                'ref' => null,
                'site' => null,
                'link' => "/governance/meetings/{$meeting->id}",
                'editable' => false,
                'eventType' => null,
                'typeLabel' => GovernanceLabels::label('meeting_type', $meeting->meeting_type),
                'desc' => $meeting->notes,
                'quorum_met' => $meeting->quorum_met,
                'mine' => $isMine,
                // The shared calendar counts "Mine" from owner/attendees.
                'attendeeIds' => $isMine ? [(int) $viewer->id] : [],
            ];
        }

        return $events;
    }

    /**
     * A meeting that has finished isn't "overdue" — its minutes are due.
     *
     * @return array{0: string, 1: ?string}
     */
    private function meetingStatus(GovernanceMeeting $meeting, ?Carbon $endTime): array
    {
        $status = (string) $meeting->status;

        if ($status === 'cancelled') {
            return ['cancelled', null];
        }

        if (in_array($status, ['minutes_approved', 'minutes_signed', 'archived', 'completed', 'signed'], true)) {
            return ['completed', GovernanceLabels::label('meeting_status', $status)];
        }

        if ($endTime !== null && $endTime->isPast()) {
            return ['pending', 'Minutes due'];
        }

        if ($meeting->scheduled_at?->isPast()) {
            return ['scheduled', 'In progress'];
        }

        return ['scheduled', null];
    }

    /** @return array<int, array<string, mixed>> */
    private function decisionEvents(Carbon $start, Carbon $end, User $viewer, ?int $committeeId): array
    {
        $query = Resolution::query()
            ->with('meeting')
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [$start, $end]);

        $this->recordAccess->scopeResolutions($query, $viewer);

        if ($committeeId !== null) {
            $query->where('board_committee_id', $committeeId);
        }

        $workQuery = app(GovernanceWorkQuery::class);
        $canOpenMeetings = $viewer->canDo('governance.meetings.view');

        $events = [];
        foreach ($query->orderBy('deadline')->get() as $res) {
            $isCancelled = in_array($res->status, ['cancelled', 'withdrawn'], true);
            $isClosed = in_array($res->status, ['carried', 'defeated', 'closed', 'implemented', 'archived'], true);
            $hasPassed = $res->deadline->isPast();

            [$status, $statusLabel] = match (true) {
                $isCancelled => ['cancelled', null],
                $isClosed => ['completed', $res->status === 'closed' && $res->outcome
                    ? GovernanceLabels::label('resolution_outcome', $res->outcome)
                    : GovernanceLabels::label('resolution_status', $res->status)],
                $hasPassed && $res->status === 'open' => ['overdue', 'Result not recorded'],
                $hasPassed => ['overdue', 'Deadline passed'],
                $res->status === 'open' => ['scheduled', 'Open for voting'],
                default => ['scheduled', GovernanceLabels::label('resolution_status', $res->status)],
            };

            $hasTime = $res->deadline->format('H:i:s') !== '00:00:00';
            $deadline = $hasTime ? $res->deadline->toIso8601String() : $res->deadline->toDateString();
            $decisionType = $res->decision_type && ! in_array($res->decision_type, ['resolution', 'motion'], true)
                ? ' · '.GovernanceLabels::label('decision_type', $res->decision_type)
                : '';

            $events[] = [
                'id' => "governance:decision:{$res->id}",
                'source' => 'decisions',
                'group' => 'auto',
                'title' => $res->title,
                'start' => $deadline,
                'end' => $deadline,
                'allDay' => ! $hasTime,
                'status' => $status,
                'statusLabel' => $statusLabel,
                'owner' => null,
                'room' => null,
                'ref' => $res->resolution_reference ?: null,
                'site' => null,
                'link' => $canOpenMeetings
                    ? $workQuery->decisionWorkspaceHref($viewer, $res)
                    : "/governance/resolutions/{$res->id}",
                'editable' => false,
                'eventType' => null,
                'typeLabel' => 'Voting deadline'.$decisionType,
                'desc' => $res->context,
                'mine' => false,
            ];
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function obligationEvents(Carbon $start, Carbon $end, User $viewer): array
    {
        $obligations = ComplianceObligation::query()
            ->with('owner')
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('due_date')
            ->get();

        $events = [];
        foreach ($obligations as $o) {
            $dueDate = $o->due_date?->toDateString();
            $isCancelled = $o->status === 'cancelled';
            $isComplete = $o->status === 'complete';
            $daysLeft = GovernanceWording::daysFromToday($dueDate);
            $isOverdue = ! $isComplete && ! $isCancelled
                && ($o->status === 'overdue' || ($daysLeft !== null && $daysLeft < 0));

            $events[] = [
                'id' => "governance:obligation:{$o->id}",
                'source' => 'obligations',
                'group' => 'auto',
                'title' => $o->obligation_title,
                'start' => $dueDate,
                'end' => $dueDate,
                'allDay' => true,
                'status' => $isCancelled ? 'cancelled' : ($isComplete ? 'completed' : ($isOverdue ? 'overdue' : 'scheduled')),
                'statusLabel' => $isComplete ? 'Done' : null,
                'owner' => $o->owner ? [
                    'id' => $o->owner->id,
                    'name' => $o->owner->name,
                ] : null,
                'room' => null,
                'ref' => $o->obligation_code ?: null,
                'site' => null,
                'link' => "/governance/compliance/{$o->id}",
                'editable' => false,
                'eventType' => null,
                'typeLabel' => GovernanceLabels::label('compliance_framework', $o->framework),
                'desc' => $o->description ?? null,
                'mine' => (int) $o->owner_id === (int) $viewer->id,
            ];
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function policyEvents(Carbon $start, Carbon $end, User $viewer): array
    {
        $from = $start->toDateString();
        $to = $end->toDateString();

        // The review date is next_review_date, falling back to review_due —
        // the same date the entry is placed on.
        $policies = GovernancePolicy::query()
            ->with('owner')
            ->whereNotIn('status', ['superseded', 'archived'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('next_review_date', [$from, $to])
                    ->orWhere(function ($fallback) use ($from, $to) {
                        $fallback->whereNull('next_review_date')
                            ->whereBetween('review_due', [$from, $to]);
                    });
            })
            ->get();

        $events = [];
        foreach ($policies as $p) {
            $reviewDate = ($p->next_review_date ?? $p->review_due)?->toDateString();
            if ($reviewDate === null) {
                continue;
            }

            $daysLeft = GovernanceWording::daysFromToday($reviewDate);
            $isOverdue = $daysLeft !== null && $daysLeft < 0;

            $events[] = [
                'id' => "governance:policy:{$p->id}",
                'source' => 'policies',
                'group' => 'auto',
                'title' => $p->title,
                'start' => $reviewDate,
                'end' => $reviewDate,
                'allDay' => true,
                'status' => $isOverdue ? 'overdue' : 'scheduled',
                'statusLabel' => $isOverdue ? 'Review overdue' : 'Review due',
                'owner' => $p->owner ? [
                    'id' => $p->owner->id,
                    'name' => $p->owner->name,
                ] : null,
                'room' => null,
                'ref' => $p->policy_code ?: null,
                'site' => null,
                'link' => "/governance/policies/{$p->id}",
                'editable' => false,
                'eventType' => null,
                'typeLabel' => 'Policy review'.($p->category ? ' · '.GovernanceLabels::label('policy_category', $p->category) : ''),
                'desc' => $p->purpose ?? null,
                'mine' => (int) $p->owner_id === (int) $viewer->id,
            ];
        }

        return $events;
    }
}
