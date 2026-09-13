<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Carbon\Carbon;

class GovernanceCalendarQuery
{
    public function __construct(
        private readonly GovernanceRecordAccessService $recordAccess,
    ) {}

    /**
     * Query normalized calendar items for the requested window and viewer.
     *
     * @param  array<int, string>|null  $sources
     * @return array{events: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function queryItems(
        Carbon $start,
        Carbon $end,
        User $viewer,
        ?array $sources = null,
        ?int $committeeId = null,
    ): array {
        $requestedSources = $sources ? array_map('trim', $sources) : ['meetings', 'decisions', 'obligations', 'policies'];
        $events = [];

        // 1. Meetings
        if (in_array('meetings', $requestedSources, true)) {
            $meetingQuery = GovernanceMeeting::query()
                ->with(['chair.user', 'secretary.user'])
                ->where('scheduled_at', '<=', $end)
                ->where(function ($q) use ($start) {
                    $q->where('scheduled_at', '>=', $start)
                        ->orWhereRaw('DATE_ADD(scheduled_at, INTERVAL COALESCE(duration_minutes, 60) MINUTE) >= ?', [$start]);
                });

            $meetingQuery = $this->recordAccess->scopeMeetings($meetingQuery, $viewer);

            if ($committeeId !== null) {
                $meetingQuery->where('board_committee_id', $committeeId);
            }

            $meetings = $meetingQuery->orderBy('scheduled_at')->get();

            foreach ($meetings as $meeting) {
                $startTime = $meeting->scheduled_at;
                $endTime = $startTime ? $startTime->copy()->addMinutes($meeting->duration_minutes ?? 60) : null;
                $isOwner = $meeting->chair?->user_id === $viewer->id || $meeting->created_by === $viewer->id;

                $events[] = [
                    'id' => "governance:meeting:{$meeting->id}",
                    'source' => 'meetings',
                    'group' => 'manual',
                    'title' => $meeting->title,
                    'start' => $startTime?->toIso8601String(),
                    'end' => $endTime?->toIso8601String(),
                    'allDay' => false,
                    'status' => $meeting->status === 'cancelled'
                        ? 'cancelled'
                        : (in_array($meeting->status, ['completed', 'signed', 'archived'], true)
                            ? 'completed'
                            : ($startTime && $startTime->isPast() && ! in_array($meeting->status, ['completed', 'signed', 'archived'], true) ? 'overdue' : 'scheduled')),
                    'owner' => $meeting->chair?->user ? [
                        'id' => $meeting->chair->user->id,
                        'name' => $meeting->chair->user->name,
                    ] : null,
                    'room' => $meeting->location,
                    'ref' => 'MTG-'.str_pad((string) $meeting->id, 4, '0', STR_PAD_LEFT),
                    'site' => null,
                    'link' => "/governance/meetings/{$meeting->id}",
                    'editable' => false,
                    'eventType' => $meeting->meeting_type,
                    'desc' => $meeting->notes,
                    'quorum_met' => $meeting->quorum_met,
                    'mine' => $isOwner,
                ];
            }
        }

        // 2. Decisions (Resolution deadlines)
        if (in_array('decisions', $requestedSources, true)) {
            $resQuery = Resolution::query()
                ->whereNotNull('deadline')
                ->whereBetween('deadline', [$start, $end]);

            $resQuery = $this->recordAccess->scopeResolutions($resQuery, $viewer);

            if ($committeeId !== null) {
                $resQuery->where('board_committee_id', $committeeId);
            }

            $resolutions = $resQuery->orderBy('deadline')->get();

            foreach ($resolutions as $res) {
                $isCancelled = $res->status === 'cancelled';
                $isClosed = in_array($res->status, ['carried', 'defeated', 'closed', 'implemented', 'archived'], true);
                $isOverdue = ! $isClosed && ! $isCancelled && $res->deadline && $res->deadline->isPast();
                $hasTime = $res->deadline && $res->deadline->format('H:i:s') !== '00:00:00';
                $deadlineStr = $hasTime ? $res->deadline->toIso8601String() : $res->deadline?->toDateString();

                $events[] = [
                    'id' => "governance:decision:{$res->id}",
                    'source' => 'decisions',
                    'group' => 'auto',
                    'title' => "[Vote deadline] {$res->title}",
                    'start' => $deadlineStr,
                    'end' => $deadlineStr,
                    'allDay' => ! $hasTime,
                    'status' => $isCancelled ? 'cancelled' : ($isClosed ? 'completed' : ($isOverdue ? 'overdue' : 'scheduled')),
                    'owner' => null,
                    'room' => null,
                    'ref' => $res->resolution_reference ?? ('RES-'.str_pad((string) $res->id, 4, '0', STR_PAD_LEFT)),
                    'site' => null,
                    'link' => "/governance/resolutions/{$res->id}",
                    'editable' => false,
                    'eventType' => $res->decision_type ?? 'resolution',
                    'desc' => $res->context,
                    'mine' => false,
                ];
            }
        }

        // 3. Obligations (Compliance obligations due in range)
        if (in_array('obligations', $requestedSources, true)) {
            $oblQuery = ComplianceObligation::query()
                ->with('owner')
                ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()]);

            $obligations = $oblQuery->orderBy('due_date')->get();

            foreach ($obligations as $o) {
                $dueDate = $o->due_date?->toDateString();
                $isCancelled = $o->status === 'cancelled';
                $isComplete = $o->status === 'complete';
                $isOverdue = $o->status === 'overdue' || (! $isComplete && ! $isCancelled && $o->due_date && $o->due_date->isPast());
                $isMine = (int) $o->owner_id === (int) $viewer->id;

                $events[] = [
                    'id' => "governance:obligation:{$o->id}",
                    'source' => 'obligations',
                    'group' => 'auto',
                    'title' => $o->obligation_title,
                    'start' => $dueDate,
                    'end' => $dueDate,
                    'allDay' => true,
                    'status' => $isCancelled ? 'cancelled' : ($isComplete ? 'completed' : ($isOverdue ? 'overdue' : 'scheduled')),
                    'owner' => $o->owner ? [
                        'id' => $o->owner->id,
                        'name' => $o->owner->name,
                    ] : null,
                    'room' => null,
                    'ref' => 'OBL-'.str_pad((string) $o->id, 4, '0', STR_PAD_LEFT),
                    'site' => null,
                    'link' => "/governance/compliance/{$o->id}",
                    'editable' => false,
                    'eventType' => $o->framework,
                    'desc' => $o->obligation_description ?? null,
                    'mine' => $isMine,
                ];
            }
        }

        // 4. Policy reviews (Policies due for review in range)
        if (in_array('policies', $requestedSources, true)) {
            $polQuery = GovernancePolicy::query()
                ->with('owner')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('next_review_date', [$start->toDateString(), $end->toDateString()])
                        ->orWhereBetween('review_due', [$start->toDateString(), $end->toDateString()]);
                });

            $policies = $polQuery->get();

            foreach ($policies as $p) {
                $reviewDateObj = $p->next_review_date ?? $p->review_due;
                $reviewDate = $reviewDateObj?->toDateString();
                $isOverdue = $reviewDateObj && $reviewDateObj->isPast();
                $isMine = (int) $p->owner_id === (int) $viewer->id;

                $events[] = [
                    'id' => "governance:policy:{$p->id}",
                    'source' => 'policies',
                    'group' => 'auto',
                    'title' => "[Policy review] {$p->title}",
                    'start' => $reviewDate,
                    'end' => $reviewDate,
                    'allDay' => true,
                    'status' => $isOverdue ? 'overdue' : 'scheduled',
                    'owner' => $p->owner ? [
                        'id' => $p->owner->id,
                        'name' => $p->owner->name,
                    ] : null,
                    'room' => null,
                    'ref' => $p->policy_code ?? ('POL-'.str_pad((string) $p->id, 4, '0', STR_PAD_LEFT)),
                    'site' => null,
                    'link' => "/governance/policies/{$p->id}",
                    'editable' => false,
                    'eventType' => $p->category ?? 'policy',
                    'desc' => $p->purpose ?? null,
                    'mine' => $isMine,
                ];
            }
        }

        // Totals
        $totals = [
            'total' => count($events),
            'meetings' => count(array_filter($events, fn ($e) => $e['source'] === 'meetings')),
            'decisions' => count(array_filter($events, fn ($e) => $e['source'] === 'decisions')),
            'obligations' => count(array_filter($events, fn ($e) => $e['source'] === 'obligations')),
            'policies' => count(array_filter($events, fn ($e) => $e['source'] === 'policies')),
            'overdue' => count(array_filter($events, fn ($e) => $e['status'] === 'overdue')),
            'completed' => count(array_filter($events, fn ($e) => $e['status'] === 'completed')),
            'mine' => count(array_filter($events, fn ($e) => ! empty($e['mine']))),
        ];

        return [
            'events' => $events,
            'totals' => $totals,
        ];
    }
}
