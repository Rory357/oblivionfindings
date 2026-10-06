<?php

namespace App\Services\Medication\Alerts;

use App\Models\Client;
use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\MedicationAlertRecipient;
use App\Models\User;
use App\Services\Medication\MedicationRecordAccess;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The alert log (P11 v5 Alerts & access › Alert log, B2 chunk 6): every
 * medication alert at the houses someone sees — who was told, how, and who
 * attended — in words, newest first. It never changes the medication record.
 *
 * Scoped to the viewer's houses; an alert not about a house shows only to
 * someone with all-sites authority. A controlled-medicine alert keeps what
 * it was about, who was told and what happened from anyone without
 * controlled-medicine access (EM-12): those never leave the server.
 *
 * The log is kept as long as the audit log (Q11): `oblivion:prune-retention`
 * removes dealt-with alerts past that window.
 */
class MedicationAlertLog
{
    /** The tab lists the last 30 days, and anything still not attended. */
    public const DAYS = 30;

    public const PER_PAGE = 25;

    public function __construct(private readonly MedicationRecordAccess $records) {}

    /** The Overview counts the last 3 days (v5). */
    public const RECENT_DAYS = 3;

    private const TIMEZONE = 'Pacific/Auckland';

    /** Why someone was told, in the log's words (v5: "Mere Kahu (rostered)"). */
    private const REASON = [
        MedicationAlertCatalogue::ROSTERED => 'rostered',
        MedicationAlertCatalogue::HOUSE_LEAD => 'house lead',
        MedicationAlertCatalogue::ON_CALL => 'on call',
        MedicationAlertCatalogue::CLINICAL_LEAD => 'clinical lead',
        MedicationAlertCatalogue::PROVIDER_MANAGER => 'provider manager',
        MedicationAlertCatalogue::STOCK_STAFF => 'updates stock',
        MedicationAlertCatalogue::STAFF_MEMBER => 'the staff member',
        'named' => 'named',
        'extra' => 'house extra',
        MedicationAlertRecipients::FALLBACK => 'medication settings manager',
    ];

    private const CHANNEL = ['inapp' => 'in-app', 'email' => 'email', 'push' => 'push'];

    /**
     * @param  list<int>  $siteIds  Houses the viewer sees.
     * @return array<string, mixed> Paginated, concealed rows and filter-preserving links.
     */
    public function page(User $actor, array $siteIds, bool $allSites, CarbonInterface $now, array $filters = []): array
    {
        $canSeeControlled = $actor->canDo('medications.controlled.view');
        $query = $this->scoped($actor, $siteIds, $allSites);
        if (($filters['range'] ?? 'recent') !== 'all') {
            $query->where(fn (Builder $recent) => $recent
                ->where('raised_at', '>=', Carbon::instance($now)->subDays(self::DAYS))
                ->orWhereNotNull('open_key'));
        }
        if (! empty($filters['house'])) {
            $query->where('site_id', (int) $filters['house']);
        }
        match ($filters['show'] ?? 'all') {
            'open' => $query->whereNotNull('open_key')->whereNull('attended_at'),
            'attended' => $query->where(fn (Builder $q) => $q->whereNotNull('attended_at')->orWhereNull('open_key')),
            'afterhours' => $query->where('after_hours', true),
            default => null,
        };
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            // Hidden details must not influence search results or counts.
            $query->where(function (Builder $q) use ($search, $canSeeControlled): void {
                $q->where(function (Builder $visible) use ($search, $canSeeControlled): void {
                    if (! $canSeeControlled) {
                        $visible->where('controlled', false);
                    }
                    $visible->where(fn (Builder $text) => $text
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('message', 'like', '%'.$search.'%'));
                });
                if (! $canSeeControlled && str_contains(mb_strtolower('Controlled-medicine alert'), mb_strtolower($search))) {
                    $q->orWhere('controlled', true);
                }
            });
        }
        $page = $query->with([
            'site:id,name',
            'recipients' => fn ($q) => $q->orderBy('step')->orderBy('id'),
            'events' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id'),
        ])->orderByDesc('raised_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'alert_page')
            ->appends(['log' => 1, 'log_house' => $filters['house'] ?? '', 'log_show' => $filters['show'] ?? 'all', 'log_range' => $filters['range'] ?? 'recent', 'log_q' => $search])
            ->fragment('alerts/log');
        $names = $this->names($page->getCollection()->filter(fn (MedicationAlert $a): bool => ! $a->controlled || $canSeeControlled));
        $page->setCollection($page->getCollection()->map(fn (MedicationAlert $alert): array => $this->row($alert, $names, $canSeeControlled, $now)));

        return $page->toArray();
    }

    /** Counts follow the same current person and house access as the log. */
    public function summary(User $actor, array $siteIds, bool $allSites, CarbonInterface $now): array
    {
        $since = Carbon::instance($now)->subDays(self::RECENT_DAYS);
        $row = $this->scoped($actor, $siteIds, $allSites)
            ->selectRaw('sum(case when raised_at >= ? then 1 else 0 end) as recent', [$since])
            ->selectRaw('sum(case when open_key is not null and attended_at is null then 1 else 0 end) as open_count')
            ->toBase()->first();

        return ['recent' => (int) ($row->recent ?? 0), 'open' => (int) ($row->open_count ?? 0)];
    }

    private function scoped(User $actor, array $siteIds, bool $allSites): Builder
    {
        // A historic alert cannot expose a person who moved house, or whose
        // record this reader cannot open now. This is also the count boundary.
        $clients = Client::query()->whereIn('site_id', $siteIds)->pluck('id');
        $readable = $this->records->readableClientIds($actor, $clients);

        return MedicationAlert::query()
            ->where(function (Builder $where) use ($siteIds, $allSites): void {
                $where->whereIn('site_id', $siteIds);
                if ($allSites) {
                    $where->orWhereNull('site_id');
                }
            })
            ->where(fn (Builder $where) => $where->whereNull('client_id')->orWhereIn('client_id', $readable));
    }

    /**
     * Everyone the loaded alerts name, in one query.
     *
     * @param  Collection<int, MedicationAlert>  $alerts
     * @return array<int, string>
     */
    private function names(Collection $alerts): array
    {
        $ids = [];
        foreach ($alerts as $alert) {
            $ids[] = $alert->attended_by;
            foreach ($alert->recipients as $recipient) {
                $ids[] = $recipient->user_id;
            }
            foreach ($alert->events as $event) {
                $ids[] = $event->user_id;
                foreach (['user_ids', 'not_sent'] as $key) {
                    array_push($ids, ...($event->detail[$key] ?? []));
                }
                foreach (['told', 'unreachable'] as $key) {
                    foreach ($event->detail[$key] ?? [] as $entry) {
                        $ids[] = is_array($entry) ? ($entry['user_id'] ?? null) : $entry;
                    }
                }
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', array_filter($ids)))));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function row(MedicationAlert $alert, array $names, bool $canSeeControlled, CarbonInterface $now): array
    {
        $concealed = (bool) $alert->controlled && ! $canSeeControlled;
        $raised = Carbon::instance($alert->raised_at ?? $alert->created_at);
        $status = match (true) {
            $alert->status === MedicationAlert::STATUS_DEALT_WITH || $alert->open_key === null => 'dealt_with',
            $alert->attended_at !== null => 'attended',
            default => 'open',
        };
        $row = [
            'id' => (int) $alert->id,
            'concealed' => $concealed,
            'site_id' => $alert->site_id !== null ? (int) $alert->site_id : null,
            'site_name' => $alert->site?->name,
            'raised_at' => $raised->toIso8601String(),
            'sent' => $this->when($raised, $now),
            'after_hours' => (bool) $alert->after_hours,
            'status' => $status,
            'waited' => $status === 'open' ? $this->waited($raised, $now) : null,
            'attended_at' => $alert->attended_at !== null ? $this->time($alert->attended_at, $raised) : null,
            'dealt_with_at' => $alert->dealt_with_at !== null ? $this->time($alert->dealt_with_at, $raised) : null,
            'reached_nobody' => (bool) $alert->reached_nobody,
        ];
        if ($concealed) {
            return [...$row, 'type' => null, 'label' => null, 'about' => null, 'action_url' => null, 'told' => [], 'via' => [], 'attended_by' => null, 'events' => []];
        }
        $told = $alert->recipients
            ->filter(fn (MedicationAlertRecipient $r): bool => $r->told_at !== null)
            ->pluck('user_id')
            ->unique()
            ->map(fn (mixed $id): string => $names[(int) $id] ?? 'A former staff member')
            ->values()
            ->all();
        $via = $this->channels($alert->recipients->filter(fn (MedicationAlertRecipient $r): bool => $r->told_at !== null)->flatMap(fn (MedicationAlertRecipient $r): array => $r->channels ?? [])->all());

        return [
            ...$row,
            'type' => (string) $alert->type,
            'label' => MedicationAlertCatalogue::get((string) $alert->type)['label'] ?? (string) $alert->title,
            'about' => (string) $alert->message,
            'action_url' => $this->safeActionUrl($alert->action_url),
            'told' => $told,
            'via' => $via,
            'attended_by' => $alert->attended_by !== null ? ($names[(int) $alert->attended_by] ?? 'A former staff member') : null,
            'events' => $alert->events
                ->map(fn (MedicationAlertEvent $event): ?array => $this->event($event, $names, $raised))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * One thing that happened, in words, or null when it adds nothing.
     *
     * @param  array<int, string>  $names
     * @return array{id: int, occurred_at: string, at: string, what: string, who: string}|null
     */
    private function event(MedicationAlertEvent $event, array $names, Carbon $raised): ?array
    {
        $d = $event->detail ?? [];
        $name = fn (mixed $id): string => $names[(int) $id] ?? 'A former staff member';
        $list = fn (array $ids): string => implode(', ', array_map($name, $ids));
        $people = function (array $told) use ($name): string {
            $words = array_map(
                fn (array $t): string => $name($t['user_id'] ?? 0).(isset(self::REASON[$t['reason'] ?? '']) ? ' ('.self::REASON[$t['reason']].')' : ''),
                $told,
            );
            $via = $this->channels(array_merge(...array_map(fn (array $t): array => $t['channels'] ?? [], $told ?: [[]])));

            return implode(', ', $words).($via !== [] ? ' · '.$this->and($via) : '');
        };
        $by = $event->user_id !== null ? $name($event->user_id) : '';
        [$what, $who] = match ($event->event) {
            MedicationAlertEvent::SENT => ($d['told'] ?? []) === [] ? [null, ''] : ['Sent', $people($d['told'])],
            MedicationAlertEvent::NOT_TOLD_CONTROLLED => ['Not told — no controlled-medicine access', $list($d['user_ids'] ?? [])],
            MedicationAlertEvent::NOT_REACHABLE => ['Couldn’t be reached', $list($d['user_ids'] ?? []).' — in-app is off, and no work email or push is set up'],
            MedicationAlertEvent::FALLBACK => ['Nobody in its groups here — sent to medication settings managers', ''],
            MedicationAlertEvent::NOBODY_TOLD => ['Nobody could be told', (string) ($d['reason'] ?? '')],
            MedicationAlertEvent::HELD => ['Email and push held until '.$this->time(Carbon::parse($d['until'] ?? $event->occurred_at), $raised), $list($d['user_ids'] ?? []).' · quiet hours'],
            MedicationAlertEvent::RELEASED => ($d['told'] ?? []) === []
                ? ['Quiet hours ended — nothing sent', 'They can no longer get it']
                : ['Email and push sent after quiet hours', $people($d['told'])],
            MedicationAlertEvent::HELD_NOT_SENT => ['Held email and push not sent — dealt with first', $list($d['user_ids'] ?? [])],
            MedicationAlertEvent::OPENED => ['Opened', $by],
            MedicationAlertEvent::ACKNOWLEDGED => ['Acknowledged', $by],
            MedicationAlertEvent::RE_ALERTED => ['Re-alerted', ($d['told'] ?? []) === [] ? 'Nobody could be told again' : $people($d['told'])],
            MedicationAlertEvent::ESCALATED => ['Escalated', ($d['told'] ?? []) === [] ? 'Nobody else could be told' : $people($d['told'])],
            MedicationAlertEvent::DEALT_WITH => ['Dealt with'.(($d['outcome'] ?? '') !== '' ? ' — '.lcfirst((string) $d['outcome']) : ''), $by],
            MedicationAlertEvent::CARRIED_OVER => ['Carried over at deploy — nothing was sent', ''],
            default => [null, ''],
        };

        return $what === null ? null : [
            'id' => (int) $event->id,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'at' => $this->time(Carbon::instance($event->occurred_at), $raised),
            'what' => $what,
            'who' => $who,
        ];
    }

    /** Canonical source links stay inside this application. */
    private function safeActionUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme']) || ! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }

        return $url;
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function channels(array $channels): array
    {
        return array_values(array_map(
            fn (string $c): string => self::CHANNEL[$c],
            array_values(array_filter(array_keys(self::CHANNEL), fn (string $c): bool => in_array($c, $channels, true))),
        ));
    }

    /** @param list<string> $words "in-app", "in-app and email", "in-app, email and push" */
    private function and(array $words): string
    {
        return count($words) > 1 ? implode(', ', array_slice($words, 0, -1)).' and '.end($words) : ($words[0] ?? '');
    }

    /** "Today 9:00 am", "Yesterday 4:10 pm", "Thu 1 Oct 9:00 am". */
    private function when(CarbonInterface $at, CarbonInterface $now): string
    {
        $local = Carbon::instance($at)->setTimezone(self::TIMEZONE);
        $today = Carbon::instance($now)->setTimezone(self::TIMEZONE);
        $day = match (true) {
            $local->isSameDay($today) => 'Today',
            $local->isSameDay($today->copy()->subDay()) => 'Yesterday',
            default => $local->format($local->year === $today->year ? 'D j M' : 'D j M Y'),
        };

        return $day.' '.$local->format('g:i a');
    }

    /** An event's time: "9:05 am" the day it was raised, else "Fri 2 Oct, 9:05 am". */
    private function time(CarbonInterface $at, CarbonInterface $raised): string
    {
        $local = Carbon::instance($at)->setTimezone(self::TIMEZONE);

        return $local->isSameDay(Carbon::instance($raised)->setTimezone(self::TIMEZONE))
            ? $local->format('g:i a')
            : $local->format($local->year === Carbon::instance($raised)->setTimezone(self::TIMEZONE)->year ? 'D j M, g:i a' : 'D j M Y, g:i a');
    }

    /** "12 min", "3 h 12 min", "2 days 4 h". */
    private function waited(CarbonInterface $raised, CarbonInterface $now): string
    {
        $minutes = max(0, (int) floor(Carbon::instance($raised)->diffInMinutes($now, true)));
        if ($minutes < 60) {
            return $minutes.' min';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
        }
        $days = intdiv($minutes, 1440);

        return $days.' '.($days === 1 ? 'day' : 'days').(intdiv($minutes % 1440, 60) ? ' '.intdiv($minutes % 1440, 60).' h' : '');
    }
}
