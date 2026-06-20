<?php

namespace App\Services\Sites\Calendar\Providers;

use App\Models\HsCommittee;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Services\Sites\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
        if ($siteIds === []) {
            return [];
        }

        $items = [];
        $base = '/health-safety/worker-participation';

        /* ---- Committee meetings (scheduled_at) ---------------------- */
        $committees = HsCommittee::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->with([
                'site:id,name,type',
                'meetings' => fn ($q) => $q->whereBetween('scheduled_at', [$start, $end])->with('attendeeUsers:id'),
            ])
            ->get();

        foreach ($committees as $committee) {
            foreach ($committee->meetings as $meeting) {
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
                    site: $this->siteArray($committee->site),
                    link: "{$base}?tab=meetings&meeting={$meeting->id}",
                    attendeeIds: $meeting->attendeeUsers->pluck('id')->map(fn ($id) => (int) $id)->all(),
                );
            }

            // Cadence gap: if no meeting is scheduled within the cadence window,
            // surface the implied "meeting due" date (HSC 3-month duty).
            $next = $this->nextCadenceDate($committee);
            if ($next && $this->inRange($next, $start, $end) && $committee->meetings->isEmpty()) {
                $items[] = new CalendarItem(
                    id: "participation-cadence-{$committee->id}",
                    source: 'participation',
                    group: 'auto',
                    title: 'Committee meeting due: '.Str::limit($committee->name, 36),
                    start: $this->isoDate($next),
                    allDay: true,
                    status: $next->lt(Carbon::today()) ? 'overdue' : 'scheduled',
                    site: $this->siteArray($committee->site),
                    link: "{$base}?tab=meetings",
                    priority: $next->lt(Carbon::today()) ? 'high' : null,
                );
            }
        }

        /* ---- Consultations (consultation_date) ---------------------- */
        $consultations = HsConsultation::query()
            ->whereIn('site_id', $siteIds)
            ->whereBetween('consultation_date', [$start->toDateString(), $end->toDateString()])
            ->with('site:id,name,type')
            ->get();

        foreach ($consultations as $c) {
            $items[] = new CalendarItem(
                id: "participation-consultation-{$c->id}",
                source: 'participation',
                group: 'auto',
                title: 'Consultation: '.Str::limit($c->title, 40),
                start: $this->isoDate($c->consultation_date),
                allDay: true,
                // Map the participation lifecycle onto toned calendar statuses so
                // chips render coloured (open=scheduled/info, mid=pending/warning,
                // closed=completed).
                status: match ($c->status) {
                    'closed' => 'completed',
                    'open' => 'scheduled',
                    default => 'pending',
                },
                site: $this->siteArray($c->site),
                link: "{$base}?tab=consultations&consultation={$c->id}",
            );
        }

        /* ---- HSR term expiry (re-election due, <=3yr term) ---------- */
        $reps = HsRepresentative::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->whereNotNull('term_expires_at')
            ->whereBetween('term_expires_at', [$start->toDateString(), $end->toDateString()])
            ->with(['site:id,name,type', 'user:id,name'])
            ->get();

        foreach ($reps as $rep) {
            $due = Carbon::parse($rep->term_expires_at);
            $items[] = new CalendarItem(
                id: "participation-rep-term-{$rep->id}",
                source: 'participation',
                group: 'auto',
                title: 'HSR term ends: '.($rep->user?->name ?? 'Representative'),
                start: $this->isoDate($due),
                allDay: true,
                status: $this->dueStatus($due, false),
                owner: $this->ownerArray($rep->user),
                site: $this->siteArray($rep->site),
                link: "{$base}?tab=representatives&representative={$rep->id}",
            );
        }

        return $items;
    }

    private function nextCadenceDate(HsCommittee $committee): ?Carbon
    {
        $last = $committee->meetings()->max('scheduled_at');
        $from = $last ? Carbon::parse($last) : ($committee->established_at ? Carbon::parse($committee->established_at) : now());

        return match ($committee->meeting_frequency) {
            'weekly' => $from->copy()->addWeek(),
            'fortnightly' => $from->copy()->addWeeks(2),
            'monthly' => $from->copy()->addMonth(),
            'quarterly' => $from->copy()->addMonths(3),
            default => $from->copy()->addMonths(3), // HSWA backstop: >= every 3 months
        };
    }
}
