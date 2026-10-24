<?php

namespace App\Services\HealthSafety;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** One operating organisation: every count, body and name uses the original participation source proof. */
final class WorkerParticipationRegisterReadService
{
    private const TABS = ['representatives', 'meetings', 'consultations'];
    private const FOCUS_BY_TAB = ['representatives' => ['training_below_two'], 'meetings' => ['minutes_missing', 'actions_outstanding'], 'consultations' => []];
    private const CONSULT_STAGES = ['open', 'feedback_received', 'actioned', 'closed'];

    public function __construct(private readonly WorkerParticipationAccessService $access) {}

    /** Complete detached index props, also suitable for the canonical Inertia partial-response cluster. */
    public function index(Request $request, ?HealthSafetyReadContext $context = null): array
    {
        $values = $request->validate([
            'site_id' => ['nullable', 'integer', 'min:1'],
            'representative' => ['nullable', 'integer', 'min:1'],
            'meeting' => ['nullable', 'integer', 'min:1'],
            'consultation' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'focus' => ['nullable', 'string', 'in:training_below_two,minutes_missing,actions_outstanding'],
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:255'],
            'period' => ['nullable', 'string', 'max:255'],
        ]);
        $tab = in_array($request->input('tab'), self::TABS, true) ? $request->input('tab') : 'representatives';
        $focus = $values['focus'] ?? null;
        $focus = in_array($focus, self::FOCUS_BY_TAB[$tab], true) ? $focus : null;
        $focus === null ? $request->query->remove('focus') : $request->query->set('focus', $focus);
        $filters = ['tab' => $tab, 'site_id' => isset($values['site_id']) ? (int) $values['site_id'] : null,
            'status' => ($values['status'] ?? null) ?: null, 'period' => $request->input('period', 'quarter'),
            'q' => ($values['q'] ?? null) ?: null, 'focus' => $focus];

        return $this->access->materialize($request->user(), function (User $current, $reads, HealthSafetyReadContext $active) use ($request, $values, $filters, $tab): array {
            $site = $filters['site_id'];
            $manage = $current->canDo('hazards.manage');
            $metrics = $this->metrics($active, $site);
            return ['filters' => $filters, 'tab' => $tab,
                'tabCounts' => $metrics['tabCounts'],
                'rows' => $this->rows($request, $filters, $active),
                'hero' => $metrics['hero'],
                'detail' => $this->detail($values, $active, $site),
                'can' => ['manage' => $manage],
                'sites' => $this->access->sitesQuery($active)->orderBy('name')->get(['id', 'name'])->toArray(),
                'staff' => $manage ? $this->access->pickerUsers($active)->orderBy('name')->get(['users.id', 'users.name'])->toArray() : [],
                'committees' => $manage ? $this->access->committeeChoices($active) : [],
                'participation_viewer_id' => (int) $current->id];
        }, $context, $filters['site_id'], true);
    }

    private function query(string $kind, HealthSafetyReadContext $context, ?int $site): Builder
    {
        return $this->access->query($kind, $context, $site);
    }

    private function focus(Builder $query, ?string $focus): Builder
    {
        return match ($focus) {
            'training_below_two' => $query->where('status', 'active')->where('training_days_completed', '<', 2),
            'minutes_missing' => $query->where('status', 'completed')->where(fn (Builder $document) => $document->whereNull('minutes_document_path')->orWhereRaw('LENGTH(minutes_document_path) = 0')),
            'actions_outstanding' => $query->where('status', '!=', 'cancelled')->where('actions_due_count', '>', 0),
            default => $query,
        };
    }

    /** Preserve recent plus every upcoming meeting/consultation; representatives remain unbounded. */
    private function periodStart(?string $period): ?Carbon
    {
        $now = now((string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC'));
        return match ($period) {
            'week' => $now->startOfWeek(), '30d' => $now->subDays(30)->startOfDay(),
            'year' => $now->startOfYear(), 'quarter' => $now->startOfQuarter(), default => null,
        };
    }

    private function rows(Request $request, array $filters, HealthSafetyReadContext $context): array
    {
        $kind = $filters['tab']; $site = $filters['site_id'];
        $query = $this->focus($this->query($kind, $context, $site), $filters['focus'])
            ->when($filters['status'], fn (Builder $rows, $status) => $rows->where('status', $status));
        if ($kind === 'representatives') {
            if ($filters['q']) {
                $names = $this->access->relatedQuery($kind, 'user', $context, $site)
                    ->whereColumn('users.id', 'hs_representatives.user_id')->where('users.name', 'like', '%'.$filters['q'].'%')->selectRaw('1');
                $query->where(fn (Builder $match) => $match->whereExists($names->toBase())->orWhere('work_group', 'like', '%'.$filters['q'].'%'));
            }
            $query->orderByDesc('created_at');
        } else {
            $date = $kind === 'meetings' ? 'scheduled_at' : 'consultation_date';
            $query->when($this->periodStart($filters['period']), fn (Builder $rows, $start) => $rows->where($date, '>=',
                $kind === 'meetings' ? $start->utc()->format('Y-m-d H:i:s.u') : $start->format('Y-m-d')));
            if ($filters['q']) {
                if ($kind === 'meetings') {
                    $names = $this->access->relatedQuery($kind, 'committee', $context, $site)
                        ->whereColumn('hs_committees.id', 'hs_committee_meetings.hs_committee_id')->where('hs_committees.name', 'like', '%'.$filters['q'].'%')->selectRaw('1');
                    $query->whereExists($names->toBase());
                } else { $query->where('title', 'like', '%'.$filters['q'].'%'); }
            }
            $query->orderByDesc($date);
        }
        $page = $query->orderByDesc('id')->paginate(20, ['*'], 'page', (int) $request->input('page', 1))->withQueryString();
        $models = $page->getCollection();
        $relations = $this->references($kind, $models, $context, $site);
        $attendees = $kind === 'meetings' ? $this->attendeeCounts($models->modelKeys(), $context, $site) : [];
        $page->setCollection($models->map(function ($row) use ($kind, $relations, $attendees): array {
            $data = $this->dateValues($kind, $row);
            if ($kind === 'representatives') {
                $data['user'] = $relations['user'][$row->user_id] ?? null;
                $data['site'] = $relations['site'][$row->site_id] ?? null;
                $data['elected_at'] = $row->elected_at?->format('Y-m-d');
            } elseif ($kind === 'consultations') {
                $data['site'] = $relations['site'][$row->site_id] ?? null;
                $data['consultation_date'] = $row->consultation_date?->format('Y-m-d');
            } else {
                $data['committee'] = $relations['committee'][$row->hs_committee_id] ?? null;
                $data['attendees_count'] = $attendees[$row->id] ?? 0;
            }
            $data['site_ownership'] = $this->ownership($kind, $data);
            return $data;
        }));
        return $page->toArray();
    }

    /** Names are batched by their recorded foreign keys and remain correlated to original admitted roots. */
    private function references(string $kind, Collection $models, HealthSafetyReadContext $context, ?int $site, bool $detail = false): array
    {
        $foreign = match ($kind) {
            'representatives' => ['user' => 'user_id', 'site' => 'site_id'],
            'meetings' => ['committee' => 'hs_committee_id'],
            default => ['site' => 'site_id'],
        };
        if ($detail && $kind !== 'consultations') { $foreign['creator'] = 'created_by'; }
        if ($detail && $kind === 'consultations') { $foreign['initiator'] = 'initiated_by'; }
        $out = [];
        foreach ($foreign as $relation => $column) {
            $ids = $models->pluck($column)->filter(fn ($id) => $id !== null)->unique()->values()->all();
            $out[$relation] = [];
            if ($ids === []) { continue; }
            $columns = $relation === 'committee' ? ['id', 'name', 'site_id'] : ['id', 'name'];
            $rows = $this->access->relatedQuery($kind, $relation, $context, $site)->whereKey($ids)->get($columns);
            foreach ($rows as $row) { $out[$relation][$row->id] = $row->toArray(); }
        }
        return $out;
    }

    /** The actual pivot SELECT is joined to User and correlated to the same original meeting query. */
    private function attendeeQuery(array $ids, HealthSafetyReadContext $context, ?int $site): Builder
    {
        $parents = $this->query('meetings', $context, $site)->whereKey($ids)
            ->whereColumn('hs_committee_meetings.id', 'hs_meeting_attendees.meeting_id')->selectRaw('1');
        return $this->access->relatedQuery('meetings', 'attendees', $context, $site)
            ->join('hs_meeting_attendees', 'hs_meeting_attendees.user_id', '=', 'users.id')
            ->whereIn('hs_meeting_attendees.meeting_id', $ids)->whereExists($parents->toBase());
    }

    private function attendeeCounts(array $ids, HealthSafetyReadContext $context, ?int $site): array
    {
        if ($ids === []) { return []; }
        $rows = $this->attendeeQuery($ids, $context, $site)->select('hs_meeting_attendees.meeting_id')
            ->selectRaw('COUNT(*) AS aggregate')->groupBy('hs_meeting_attendees.meeting_id')->get();
        $out = []; foreach ($rows as $row) { $out[$row->meeting_id] = (int) $row->aggregate; }
        return $out;
    }

    /** Five guarded aggregate statements preserve all original operands without repeating each source count. */
    private function metrics(HealthSafetyReadContext $context, ?int $site): array
    {
        $quarter = $this->periodStart('quarter');
        $quarterEnd = $quarter->copy()->addQuarter()->utc()->format('Y-m-d H:i:s.u');
        $quarterStart = $quarter->utc()->format('Y-m-d H:i:s.u');
        $reps = $this->query('representatives', $context, $site)->selectRaw('COUNT(*) AS tab_count,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active_reps,
            COUNT(DISTINCT CASE WHEN status = ? THEN site_id ELSE NULL END) AS covered_sites,
            SUM(CASE WHEN status = ? AND training_days_completed < 2 THEN 1 ELSE 0 END) AS training_below', ['active', 'active', 'active'])->get()->first();
        $meetings = $this->query('meetings', $context, $site)->selectRaw('COUNT(*) AS tab_count,
            SUM(CASE WHEN status = ? AND (minutes_document_path IS NULL OR LENGTH(minutes_document_path) = 0) THEN 1 ELSE 0 END) AS missing_minutes,
            SUM(CASE WHEN status != ? AND actions_due_count > 0 THEN 1 ELSE 0 END) AS outstanding_meetings,
            SUM(CASE WHEN status != ? AND actions_due_count > 0 THEN actions_due_count ELSE 0 END) AS outstanding_actions,
            SUM(CASE WHEN scheduled_at >= ? AND scheduled_at < ? THEN 1 ELSE 0 END) AS quarter_meetings',
            ['completed', 'cancelled', 'cancelled', $quarterStart, $quarterEnd])->get()->first();
        $consultations = $this->query('consultations', $context, $site)->selectRaw('COUNT(*) AS tab_count,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS open_consultations,
            SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS awaiting_feedback', ['open', 'open', 'feedback_received'])->get()->first();
        $activeReps = (int) $reps->active_reps;
        $coveredSites = (int) $reps->covered_sites;
        $totalSites = $this->access->sitesQuery($context, $site)->count();
        $minutes = (int) $meetings->missing_minutes;
        $awaiting = (int) $consultations->awaiting_feedback;
        return ['tabCounts' => ['representatives' => (int) $reps->tab_count, 'meetings' => (int) $meetings->tab_count, 'consultations' => (int) $consultations->tab_count],
            'hero' => ['clusters' => [
            'participation' => ['active_reps' => $activeReps, 'sites_without_rep' => max(0, $totalSites - $coveredSites),
                'committees' => $this->query('committees', $context, $site)->where('status', 'active')->count(),
                'meetings_quarter' => (int) $meetings->quarter_meetings],
            'consultation' => ['open' => (int) $consultations->open_consultations,
                'awaiting_feedback' => $awaiting, 'overdue_actions' => (int) $meetings->outstanding_actions,
                'meetings_with_outstanding_actions' => (int) $meetings->outstanding_meetings, 'minutes_outstanding' => $minutes]],
            'badges' => ['reps_coverage_pct' => $totalSites > 0 ? (int) round($coveredSites / $totalSites * 100) : 0,
                'sites_total' => $totalSites, 'sites_covered' => $coveredSites, 'minutes_overdue' => $minutes,
                'consultations_awaiting' => $awaiting,
                'training_below_minimum' => (int) $reps->training_below]]];
    }

    private function detail(array $values, HealthSafetyReadContext $context, ?int $site): ?array
    {
        foreach (['consultation' => 'consultations', 'meeting' => 'meetings', 'representative' => 'representatives'] as $key => $kind) {
            if (! isset($values[$key])) { continue; }
            $row = $this->query($kind, $context, $site)->whereKey((int) $values[$key])->first();
            abort_unless($row, 404);
            $data = $this->dateValues($kind, $row);
            $relations = $this->references($kind, collect([$row]), $context, $site, true);
            if ($kind === 'consultations') {
                $data['site'] = $relations['site'][$row->site_id] ?? null;
                $data['initiated_by_name'] = $relations['initiator'][$row->initiated_by]['name'] ?? null;
                $data['stage_index'] = array_search($row->status, self::CONSULT_STAGES, true) ?: 0;
            } elseif ($kind === 'representatives') {
                $data['user'] = $relations['user'][$row->user_id] ?? null;
                $data['site'] = $relations['site'][$row->site_id] ?? null;
                $data['creator'] = $relations['creator'][$row->created_by] ?? null;
            } else {
                $data['committee'] = $relations['committee'][$row->hs_committee_id] ?? null;
                $data['creator'] = $relations['creator'][$row->created_by] ?? null;
                $attendees = $this->attendeeQuery([(int) $row->id], $context, $site)->get(['users.id', 'users.name',
                    'hs_meeting_attendees.meeting_id as pivot_meeting_id', 'hs_meeting_attendees.user_id as pivot_user_id',
                    'hs_meeting_attendees.response as pivot_response', 'hs_meeting_attendees.attended as pivot_attended',
                    'hs_meeting_attendees.created_at as pivot_created_at', 'hs_meeting_attendees.updated_at as pivot_updated_at']);
                $data['attendee_users'] = $attendees->map(function ($attendee) use ($row): array {
                    $named = $attendee->toArray();
                    foreach (array_keys($named) as $column) { if (str_starts_with($column, 'pivot_')) { unset($named[$column]); } }
                    $named['pivot'] = Pivot::fromAttributes($row, ['meeting_id' => $attendee->pivot_meeting_id, 'user_id' => $attendee->pivot_user_id,
                        'response' => $attendee->pivot_response, 'attended' => $attendee->pivot_attended,
                        'created_at' => $attendee->pivot_created_at, 'updated_at' => $attendee->pivot_updated_at], 'hs_meeting_attendees', true)->toArray();
                    return $named;
                })->all();
            }
            $data['site_ownership'] = $this->ownership($kind, $data);
            return ['kind' => $key, 'data' => $data];
        }
        return null;
    }

    /** SQL DATE values are calendar dates, while scheduled/ended/created values remain instants. */
    private function dateValues(string $kind, $row): array
    {
        $data = $row->toArray();
        $columns = match ($kind) {
            'representatives' => ['elected_at', 'term_expires_at', 'initial_training_completed_at'],
            'consultations' => ['consultation_date'],
            default => [],
        };
        foreach ($columns as $column) { $data[$column] = $row->{$column}?->format('Y-m-d'); }
        return $data;
    }

    private function ownership(string $kind, array $data): string
    {
        $site = $kind === 'meetings' ? ($data['committee']['site_id'] ?? null) : ($data['site_id'] ?? null);
        return $site === null ? 'unconfirmed' : 'recorded';
    }
}
