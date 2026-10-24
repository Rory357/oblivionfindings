<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\HsCommittee;
use App\Models\User;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\WorkerParticipationAccessService;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Surfaces HSWA worker-participation obligations on the unified Site Calendar:
 * committee meetings, the next meeting implied by a committee's cadence (HSC must
 * meet >= once every 3 months), consultation dates, and HSR term-expiry
 * (re-election due). Read-only — never persists calendar rows; each item
 * deep-links back to /health-safety/worker-participation.
 *
 * Registered in SiteCalendarAggregator::defaultProviders(); the 'participation'
 * source lives in CalendarSources (+ --src-participation tokens in app.css).
 */
class WorkerParticipationObligationProvider extends ObligationProvider
{
    public function sourceKey(): string
    {
        return 'participation';
    }

    public function obligations(array $siteIds, Carbon $start, Carbon $end): array
    {
        throw new LogicException('Participation calendar requires an explicit canonical viewer and source read.');
    }

    /** Scalar facts only; presentation objects may be reconstructed after the original source callback closes. */
    public function readForViewer(array $siteIds, Carbon $start, Carbon $end, mixed $viewer, ?HealthSafetyReadContext $context = null, ?WorkerParticipationAccessService $access = null): array
    {
        if ($access !== null) {
            if ($context === null || ! $viewer instanceof User) { throw new LogicException('Retained participation access requires its original live context and viewer.'); }
            $context->assertActive();
            $context->assertViewer($viewer);
            $access->assertOriginal($context);
        }
        if (! $viewer instanceof User) {
            return ['items' => [], 'source_access' => ['status' => 'restricted', 'reason' => 'Participation requires an authorised staff viewer.']];
        }
        $access ??= app(WorkerParticipationAccessService::class);
        $start = $start->copy(); $end = $end->copy();
        try {
            $items = $access->materializeSubset($viewer, $siteIds,
                fn ($current, $reads, $active): array => $this->factsInContext($access, $active, $start, $end), $context);
            return ['items' => $items, 'source_access' => ['status' => 'available', 'reason' => null]];
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return ['items' => [], 'source_access' => ['status' => $error->getStatusCode() === 403 ? 'restricted' : 'unavailable',
                'reason' => $error->getStatusCode() === 403 ? 'Participation is restricted by source access.' : 'Participation source evidence changed or is unavailable.']];
        }
    }

    private function factsInContext(WorkerParticipationAccessService $access, HealthSafetyReadContext $context, Carbon $start, Carbon $end): array
    {
        $items = [];
        $base = '/health-safety/worker-participation';
        $workerTimezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        $workerToday = Carbon::today($workerTimezone)->toDateString();

        /* ---- Committee meetings (scheduled_at) ---------------------- */
        $committees = $access->query('committees', $context)
            ->where('status', 'active')
            ->orderBy('id')->get(['id', 'name', 'site_id', 'meeting_frequency', 'established_at']);

        foreach ($committees as $committee) {
            $site = $access->relatedQuery('committees', 'site', $context, null, $committee->id)->select(['sites.id', 'sites.name', 'sites.type'])->first();
            $meetings = $access->relatedQuery('committees', 'meetings', $context, null, $committee->id)
                ->whereBetween('scheduled_at', [$start, $end])->orderBy('scheduled_at')->orderBy('id')
                ->get(['id', 'hs_committee_id', 'scheduled_at', 'status', 'location']);
            foreach ($meetings as $meeting) {
                if (! $this->inRange($meeting->scheduled_at, $start, $end)) {
                    continue;
                }
                $items[] = new CalendarItem(
                    id: "participation-meeting-{$meeting->id}",
                    source: 'participation',
                    group: 'auto',
                    title: 'H&S committee: '.Str::limit($committee->name, 40),
                    start: $this->isoDate($meeting->scheduled_at),
                    allDay: false,
                    status: match ($meeting->status) {
                        'cancelled' => 'cancelled',
                        'completed' => 'completed',
                        default => 'scheduled',
                    },
                    room: $meeting->location,
                    site: $this->siteArray($site),
                    link: "{$base}?tab=meetings&meeting={$meeting->id}",
                    attendeeIds: array_map('intval', $access->relatedQuery('meetings', 'attendees', $context, null, $meeting->id)
                        ->select('users.id')->orderBy('users.id')->get()->modelKeys()),
                );
            }

            // Cadence gap: if no meeting is scheduled within the cadence window,
            // surface the implied "meeting due" date (HSC 3-month duty).
            $next = $this->nextCadenceDate($committee, $access, $context);
            if ($next && $this->inRange($next, $start, $end) && $meetings->isEmpty()) {
                $items[] = new CalendarItem(
                    id: "participation-cadence-{$committee->id}",
                    source: 'participation',
                    group: 'auto',
                    title: 'Committee meeting due: '.Str::limit($committee->name, 36),
                    // A DATE operand carries its local day, not a midnight instant to shift again.
                    start: $next->toDateString(),
                    allDay: true,
                    status: $next->toDateString() < $workerToday ? 'overdue' : 'scheduled',
                    site: $this->siteArray($site),
                    link: "{$base}?tab=meetings",
                    priority: $next->toDateString() < $workerToday ? 'high' : null,
                );
            }
        }

        /* ---- Consultations (consultation_date) ---------------------- */
        $consultations = $access->query('consultations', $context)
            ->whereBetween('consultation_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('consultation_date')->orderBy('id')->get(['id', 'title', 'consultation_date', 'status', 'site_id']);

        foreach ($consultations as $c) {
            $site = $access->relatedQuery('consultations', 'site', $context, null, $c->id)->select(['sites.id', 'sites.name', 'sites.type'])->first();
            $items[] = new CalendarItem(
                id: "participation-consultation-{$c->id}",
                source: 'participation',
                group: 'auto',
                title: 'Consultation: '.Str::limit($c->title, 40),
                start: $c->consultation_date->toDateString(),
                allDay: true,
                // Map the participation lifecycle onto toned calendar statuses so
                // chips render coloured (open=scheduled/info, mid=pending/warning,
                // closed=completed).
                status: match ($c->status) {
                    'closed' => 'completed',
                    'open' => 'scheduled',
                    default => 'pending',
                },
                site: $this->siteArray($site),
                link: "{$base}?tab=consultations&consultation={$c->id}",
            );
        }

        /* ---- HSR term expiry (re-election due, <=3yr term) ---------- */
        $reps = $access->query('representatives', $context)
            ->where('status', 'active')
            ->whereNotNull('term_expires_at')
            ->whereBetween('term_expires_at', [$start->toDateString(), $end->toDateString()])
            ->orderBy('term_expires_at')->orderBy('id')->get(['id', 'user_id', 'site_id', 'term_expires_at']);

        foreach ($reps as $rep) {
            $owner = $access->relatedQuery('representatives', 'user', $context, null, $rep->id)->first();
            $site = $access->relatedQuery('representatives', 'site', $context, null, $rep->id)->select(['sites.id', 'sites.name', 'sites.type'])->first();
            $due = Carbon::parse($rep->term_expires_at);
            $items[] = new CalendarItem(
                id: "participation-rep-term-{$rep->id}",
                source: 'participation',
                group: 'auto',
                title: 'HSR term ends: '.($owner?->name ?? 'Representative'),
                start: $due->toDateString(),
                allDay: true,
                status: $due->toDateString() < $workerToday ? 'overdue' : 'scheduled',
                owner: $this->ownerArray($owner),
                site: $this->siteArray($site),
                link: "{$base}?tab=representatives&representative={$rep->id}",
            );
        }

        return array_map(fn (CalendarItem $item): array => $item->toArray(), $items);
    }

    private function nextCadenceDate(HsCommittee $committee, WorkerParticipationAccessService $access, HealthSafetyReadContext $context): ?Carbon
    {
        $last = $access->relatedQuery('committees', 'meetings', $context, null, $committee->id)->max('scheduled_at');
        $workerTimezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        // Stored meeting instants are UTC; cadence arithmetic belongs to the worker's civil calendar.
        // Established-at is a DATE, so attach the zone without shifting its literal day.
        $from = $last ? Carbon::parse($last, 'UTC')->setTimezone($workerTimezone)
            : ($committee->established_at ? Carbon::parse($committee->established_at->toDateString(), $workerTimezone) : now($workerTimezone));

        return match ($committee->meeting_frequency) {
            'weekly' => $from->copy()->addWeek(),
            'fortnightly' => $from->copy()->addWeeks(2),
            'monthly' => $from->copy()->addMonth(),
            'quarterly' => $from->copy()->addMonths(3),
            default => $from->copy()->addMonths(3), // HSWA backstop: >= every 3 months
        };
    }
}
