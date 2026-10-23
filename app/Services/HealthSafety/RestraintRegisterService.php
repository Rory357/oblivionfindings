<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Materialized reads only. Candidate scans and their IDs live in one current callback. */
final class RestraintRegisterService
{
    public function __construct(private readonly RestraintAccessService $access, private readonly IncidentAccessService $incidents, private readonly HrCurrentStaffService $staff) {}

    public function register(User $viewer, array $input = []): array
    {
        return $this->within($viewer, function ($current, $reads, $sites) use ($input) {
            $this->access->assertOperation($current, 'view');
            $filters = $this->filters($input);
            $this->assertFilters($filters, $current, $reads, $sites, 'view');
            [$eventIds, $planIds] = $this->sourceIds($current, $reads, $sites);
            $events = $this->eventFilters($reads->query(RestraintEvent::whereKey($eventIds)), $filters);
            $plans = $this->planFilters($reads->query(BehaviourSupportPlan::whereKey($planIds)), $filters);
            $eventCounts = $planCounts = [];
            foreach (['all', 'unreviewed', 'out_of_plan', 'injury', 'critical', '30d'] as $tab) {
                $eventCounts[$tab] = $this->eventTab(clone $events, $tab)->count();
            }
            foreach (['all', 'active', 'draft', 'review_due', 'under_review', 'archived'] as $tab) {
                $planCounts[$tab] = $this->planTab(clone $plans, $tab)->count();
            }
            $lens = ($input['lens'] ?? '') === 'plans' ? 'plans' : 'events';
            $tab = is_string($input['tab'] ?? null) ? $input['tab'] : 'all';
            $eventPage = $this->eventTab($events, $lens === 'events' ? $tab : 'all')->orderByDesc('started_at')->orderByDesc('id')->paginate(20, ['*'], 'events_page', $this->page($input, 'events_page'));
            $planPage = $this->planTab($plans, $lens === 'plans' ? $tab : 'all')->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'plans_page', $this->page($input, 'plans_page'));
            $eventPage->through(fn ($row) => $this->eventRow($row, $current, $reads, $sites));
            $planPage->through(fn ($row) => $this->planRow($row, $current, $reads, $sites));

            return ['lens' => $lens, 'tab' => $tab, 'events' => $this->pagination($eventPage, $input), 'plans' => $this->pagination($planPage, $input), 'tabCounts' => ['events' => $eventCounts, 'plans' => $planCounts], 'hero' => $this->hero($eventIds, $planIds, $reads), 'filters' => $filters, ...$this->pickerRows($current, $reads, $sites, $filters, 'view', null), 'detail' => null, 'can' => ['create' => $current->canDo('restraints.create') || $current->canDo('restraints.manage'), 'review' => $current->canDo('restraints.review') || $current->canDo('restraints.manage'), 'manage' => $current->canDo('restraints.manage')]];
        });
    }

    public function summary(User $viewer, Client $client): array
    {
        return $this->within($viewer, function ($current, $reads, $sites) use ($client) {
            $client = $this->access->assertClient($client, $current, $reads, $sites, 'view');
            [$events, $plans] = $this->sourceIds($current, $reads, $sites, (int) $client->id);
            $plan = $reads->query(BehaviourSupportPlan::whereKey($plans))->where('status', 'active')->orderByDesc('created_at')->orderByDesc('id')->first();
            $recent = $reads->query(RestraintEvent::whereKey($events))->orderByDesc('started_at')->orderByDesc('id')->limit(5)->get()->map(function ($row) use ($current, $reads, $sites) {
                $data = $this->eventRow($row, $current, $reads, $sites);

                return array_intersect_key($data, array_flip(['id', 'reference', 'restraint_type', 'severity', 'started_at', 'within_support_plan', 'injury_occurred', 'reviewed_at', 'source_revision', 'needs_review']));
            })->all();

            return ['active_plan' => $plan ? array_intersect_key($this->planRow($plan, $current, $reads, $sites), array_flip(['id', 'reference', 'title', 'status', 'review_date', 'review_state', 'published_version_id', 'authorization_state'])) : null, 'recent_events' => $recent, 'total_events' => count($events)];
        });
    }

    /** Capture pickers use capture authority; an event uses bounded workspace authority. */
    public function pickers(User $viewer, array $filters = [], ?RestraintEvent $event = null): array
    {
        return $this->within($viewer, function ($current, $reads, $sites) use ($filters, $event) {
            $operation = $event ? 'workspace' : 'capture';
            $this->access->assertOperation($current, $operation);
            $fresh = $event ? $reads->query(RestraintEvent::query())->findOrFail($event->id) : null;
            if ($fresh) {
                $this->access->assertEvent($fresh, $current, $reads, $sites, $operation);
                $filters = [...$filters, 'client_id' => $fresh->client_id, 'site_id' => $fresh->site_id];
            }
            $filters = $this->filters($filters);
            $this->assertFilters($filters, $current, $reads, $sites, $operation);

            return $this->pickerRows($current, $reads, $sites, $filters, $operation, $fresh);
        });
    }

    public function pickersForStay(User $viewer, RespiteStay $stay): array
    {
        return $this->within($viewer, function ($current, $reads, $sites) use ($stay) {
            $this->access->assertOperation($current, 'respite_capture');
            $request = Request::create('/');
            $request->setUserResolver(fn () => $current);
            $scope = app(RespiteStayScope::class);
            $fresh = $scope->resolveAuthorizedStay($request, (int) $stay->id, true, $reads);
            $siteId = $scope->siteId($fresh);
            $this->access->assertStay((int) $fresh->id, (int) $fresh->client_id, $siteId, $current, $reads, $sites);
            $this->access->assertClient($fresh->client, $current, $reads, $sites, 'respite_capture');
            $filters = $this->filters(['client_id' => (int) $fresh->client_id, 'site_id' => $siteId]);

            return $this->pickerRows($current, $reads, $sites, $filters, 'respite_capture', null, $fresh);
        });
    }

    /** $writeRow receives only detached CSV cells, including the first header row. */
    public function export(User $viewer, array $input, callable $writeRow): void
    {
        $this->within($viewer, function ($current, $reads, $sites) use ($input, $writeRow) {
            $this->access->assertOperation($current, 'export');
            // Legacy exports without a period export every source, not just the default 30 days.
            $filters = $this->filters(['period' => 'all', ...$input]);
            $this->assertFilters($filters, $current, $reads, $sites, 'export');
            [$eventIds, $planIds] = $this->sourceIds($current, $reads, $sites);
            if (($input['lens'] ?? null) === 'plans') {
                $writeRow(['Reference', 'Client', 'Title', 'Status', 'Restrictive practice', 'Review date', 'Review state']);
                $query = $this->planFilters($reads->query(BehaviourSupportPlan::whereKey($planIds)), $filters);
                if (isset($input['tab'])) {
                    $query = $this->planTab($query, $input['tab']);
                }
                $query->orderByDesc('created_at')->orderByDesc('id');
                $query->chunk(200, function ($rows) use ($current, $reads, $sites, $writeRow) {
                    foreach ($rows as $row) {
                        $data = $this->planRow($row, $current, $reads, $sites);
                        $writeRow([$data['reference'], $data['client']['name'], $data['title'], $data['status'], $data['restrictive_practice_type'], $data['review_date'], $data['review_state']]);
                    }
                });
            } else {
                $writeRow(['Reference', 'Client', 'Site', 'Type', 'Severity', 'Started', 'Ended', 'Duration (min)', 'Within plan', 'Injury', 'Reviewed']);
                $query = $this->eventTab($this->eventFilters($reads->query(RestraintEvent::whereKey($eventIds)), $filters), $input['tab'] ?? 'all')->orderByDesc('started_at')->orderByDesc('id');
                $query->chunk(200, function ($rows) use ($current, $reads, $sites, $writeRow) {
                    foreach ($rows as $row) {
                        $data = $this->eventRow($row, $current, $reads, $sites);
                        $writeRow([$data['reference'], $data['client']['name'], $data['site']['name'] ?? '', $data['restraint_type'], $data['severity'], $row->started_at?->timezone('Pacific/Auckland')->format('Y-m-d H:i'), $row->ended_at?->timezone('Pacific/Auckland')->format('Y-m-d H:i'), $data['duration_minutes'], $data['within_support_plan'] ? 'Yes' : 'No', $data['injury_occurred'] ? 'Yes' : 'No', $data['needs_review'] ? 'No' : 'Yes']);
                    }
                });
            }

            return null;
        });
    }

    private function within(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, RestraintAccessService::PERMISSIONS, fn ($context) => $this->access->withReadContext($context, $operation));
    }

    /** Full source verification once, reused for all aggregate and page queries in this callback. */
    private function sourceIds(User $current, CurrentAuthorizationReads $reads, array $sites, ?int $clientId = null): array
    {
        $result = [[], []];
        foreach ([RestraintEvent::class, BehaviourSupportPlan::class] as $index => $model) {
            $query = $model::query()->whereHas('client', fn ($q) => $q->whereIn('site_id', $sites));
            if ($index === 0) {
                $query->where(fn ($q) => $q->whereIn('site_id', $sites)->when($current->canDo('healthSafety.viewAllSites'), fn ($wide) => $wide->orWhere(fn ($legacy) => $legacy->whereNull('site_id')->whereNull('source_schema_version'))));
            } else {
                $query->where(fn ($q) => $q->whereNull('source_schema_version')->orWhereIn('source_site_id', $sites));
            }
            $reads->query($query)->when($clientId, fn ($q) => $q->where('client_id', $clientId))->chunkById(200, function ($rows) use (&$result, $index, $current, $reads, $sites) {
                foreach ($rows as $row) {
                    if ($this->allowed(fn () => $index === 0 ? $this->access->assertEvent($row, $current, $reads, $sites) : $this->access->assertPlan($row, $current, $reads, $sites))) {
                        $result[$index][] = (int) $row->id;
                    }
                }
            });
        }

        return $result;
    }

    private function filters(array $input): array
    {
        $result = [];
        foreach (['q', 'restraint_type', 'severity', 'within_plan', 'review_state', 'period', 'from', 'to'] as $key) {
            if (isset($input[$key]) && ! is_string($input[$key])) {
                throw ValidationException::withMessages([$key => 'Use a valid filter value.']);
            }
            $result[$key] = trim($input[$key] ?? ($key === 'period' ? '30d' : ''));
        }
        foreach (['client_id', 'site_id'] as $key) {
            $value = $input[$key] ?? null;
            if ($value !== null && $value !== '' && ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value) || filter_var($value, FILTER_VALIDATE_INT) === false)) {
                throw ValidationException::withMessages([$key => 'Use an exact record identifier.']);
            }
            $result[$key] = $value === null || $value === '' ? null : (int) $value;
        }
        foreach (['from', 'to'] as $key) {
            $result[$key] = RestraintDates::day($result[$key] === '' ? null : $result[$key], $key);
        }
        if ($result['from'] && $result['to'] && $result['from'] > $result['to']) {
            throw ValidationException::withMessages(['to' => 'The end date must follow the start date.']);
        }

        return $result;
    }

    private function assertFilters(array $filters, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation): void
    {
        if ($filters['site_id']) {
            abort_unless($reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites)->whereKey($filters['site_id'])->exists(), 403);
        }
        if ($filters['client_id']) {
            $this->access->assertClient($reads->query(Client::query())->findOrFail($filters['client_id']), $current, $reads, $sites, $operation);
        }
    }

    private function window(array $filters): array
    {
        $from = $filters['from'] ? Carbon::parse($filters['from'], 'Pacific/Auckland')->startOfDay()->utc() : match ($filters['period']) {
            'week' => now('Pacific/Auckland')->startOfWeek()->utc(), 'quarter' => now('Pacific/Auckland')->subDays(90)->utc(), 'all' => null, default => now('Pacific/Auckland')->subDays(30)->utc(),
        };
        $to = $filters['to'] ? Carbon::parse($filters['to'], 'Pacific/Auckland')->endOfDay()->utc() : null;

        return [$from, $to];
    }

    private function eventFilters(Builder $query, array $filters): Builder
    {
        [$from, $to] = $this->window($filters);

        return $query->when($filters['client_id'], fn ($q) => $q->where('client_id', $filters['client_id']))->when($filters['site_id'], fn ($q) => $q->where('site_id', $filters['site_id']))->when($filters['restraint_type'], fn ($q) => $q->where('restraint_type', $filters['restraint_type']))->when($filters['severity'], fn ($q) => $q->where('severity', $filters['severity']))->when($filters['within_plan'] === 'yes', fn ($q) => $q->where('within_support_plan', true))->when($filters['within_plan'] === 'no', fn ($q) => $q->where('within_support_plan', false))->when($from, fn ($q) => $q->where('started_at', '>=', $from))->when($to, fn ($q) => $q->where('started_at', '<=', $to))->when($filters['review_state'] === 'unreviewed', fn ($q) => $this->eventTab($q, 'unreviewed'))->when($filters['review_state'] === 'reviewed', fn ($q) => $q->whereNotNull('reviewed_source_revision')->whereColumn('reviewed_source_revision', '>=', 'source_revision'))->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('restraint_description', 'like', '%'.$filters['q'].'%')->orWhere('trigger_description', 'like', '%'.$filters['q'].'%')->orWhereHas('client', fn ($c) => $c->where(fn ($n) => $n->where('first_name', 'like', '%'.$filters['q'].'%')->orWhere('last_name', 'like', '%'.$filters['q'].'%')))));
    }

    private function planFilters(Builder $query, array $filters): Builder
    {
        [$from, $to] = $this->window($filters);

        return $query->when($filters['client_id'], fn ($q) => $q->where('client_id', $filters['client_id']))->when($filters['site_id'], fn ($q) => $q->where(fn ($w) => $w->where('source_site_id', $filters['site_id'])->orWhere(fn ($legacy) => $legacy->whereNull('source_schema_version')->whereHas('client', fn ($c) => $c->where('site_id', $filters['site_id'])))))->when($filters['restraint_type'], fn ($q) => $q->where('restrictive_practice_type', $filters['restraint_type']))->when($from, fn ($q) => $q->where('created_at', '>=', $from))->when($to, fn ($q) => $q->where('created_at', '<=', $to))->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$filters['q'].'%')->orWhereHas('client', fn ($c) => $c->where(fn ($n) => $n->where('first_name', 'like', '%'.$filters['q'].'%')->orWhere('last_name', 'like', '%'.$filters['q'].'%')))))->when(in_array($filters['review_state'], ['due', 'overdue'], true), fn ($q) => $q->where('status', 'active')->whereNotNull('review_date')->where('review_date', $filters['review_state'] === 'overdue' ? '<' : '<=', $filters['review_state'] === 'overdue' ? now('Pacific/Auckland')->toDateString() : now('Pacific/Auckland')->addDays(30)->toDateString()));
    }

    private function eventTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'unreviewed' => $query->where(fn ($q) => $q->whereNull('reviewed_source_revision')->orWhereColumn('reviewed_source_revision', '<', 'source_revision')),
            'out_of_plan' => $query->where('within_support_plan', false), 'injury' => $query->where('injury_occurred', true), 'critical' => $query->where('severity', 'critical'), '30d' => $query->where('started_at', '>=', now('Pacific/Auckland')->subDays(30)->utc()), default => $query,
        };
    }

    private function planTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'active', 'draft', 'under_review', 'archived' => $query->where('status', $tab),
            'review_due' => $query->where('status', 'active')->whereNotNull('review_date')->where('review_date', '<=', now('Pacific/Auckland')->addDays(30)->toDateString()), default => $query->where('status', '!=', 'archived'),
        };
    }

    private function hero(array $events, array $plans, CurrentAuthorizationReads $reads): array
    {
        $e = fn () => $reads->query(RestraintEvent::whereKey($events));
        $p = fn () => $reads->query(BehaviourSupportPlan::whereKey($plans));
        $p30 = now('Pacific/Auckland')->subDays(30)->utc();
        $p60 = now('Pacific/Auckland')->subDays(60)->utc();
        $recent = $e()->where('started_at', '>=', $p30)->count();
        $previous = $e()->where('started_at', '>=', $p60)->where('started_at', '<', $p30)->count();
        $unreviewed = $this->eventTab($e(), 'unreviewed')->count();

        return ['live' => ['events_30d' => $recent, 'out_of_plan' => $e()->where('started_at', '>=', $p30)->where('within_support_plan', false)->count(), 'injuries' => $e()->where('started_at', '>=', $p30)->where('injury_occurred', true)->count(), 'critical' => $e()->where('started_at', '>=', $p30)->where('severity', 'critical')->count()], 'attention' => ['unreviewed' => $unreviewed, 'plans_review_due' => $this->planTab($p(), 'review_due')->count(), 'plans_under_review' => $p()->where('status', 'under_review')->count(), 'clients_no_active_bsp' => $e()->distinct()->pluck('client_id')->diff($p()->where('status', 'active')->distinct()->pluck('client_id'))->count()], 'badges' => ['unreviewed' => $unreviewed, 'plans_overdue' => $p()->where('status', 'active')->whereNotNull('review_date')->where('review_date', '<', now('Pacific/Auckland')->toDateString())->count(), 'reduction_trend_pct' => $previous > 0 ? (int) round(($recent - $previous) / $previous * 100) : 0]];
    }

    private function pickerRows(User $current, CurrentAuthorizationReads $reads, array $sites, array $filters, string $operation, ?RestraintEvent $event, ?RespiteStay $stay = null): array
    {
        $clients = [];
        $reads->query(Client::query())->when($filters['client_id'], fn ($q) => $q->whereKey($filters['client_id']))->when($filters['site_id'] && ! $event && ! $stay, fn ($q) => $q->where('site_id', $filters['site_id']))->orderBy('last_name')->orderBy('id')->chunk(200, function ($rows) use (&$clients, $current, $reads, $sites, $operation) {
            foreach ($rows as $row) {
                if ($this->allowed(fn () => $this->access->assertClient($row, $current, $reads, $sites, $operation))) {
                    $clients[(int) $row->id] = ['id' => (int) $row->id, 'name' => trim($row->first_name.' '.$row->last_name), 'site_id' => (int) $row->site_id];
                }
            }
        });
        $siteIds = $filters['site_id'] ? [$filters['site_id']] : $sites;
        $siteRows = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($siteIds)->orderBy('name')->get()->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all();
        $people = $reviewers = [];
        $reads->query($this->staff->currentUsersQuery())->orderBy('name')->orderBy('id')->chunk(200, function ($rows) use (&$people, &$reviewers, $siteRows, $reads, $event, $current, $sites) {
            foreach ($rows as $row) {
                foreach ($siteRows as $site) {
                    if ($this->allowed(fn () => $this->access->eligibleStaff((int) $row->id, $site['id'], $reads))) {
                        $people[(int) $row->id] = ['id' => (int) $row->id, 'name' => $row->name];
                    }
                    if ($event && $this->allowed(fn () => $this->access->assertStaffId((int) $row->id, $current, $reads, $sites, 'review_owner', $event))) {
                        $reviewers[(int) $row->id] = ['id' => (int) $row->id, 'name' => $row->name];
                    }
                }
            }
        });
        $incidents = [];
        $query = $this->incidents->apply(ClientIncident::query(), $current, $reads)->whereIn('client_id', array_keys($clients))->orderByDesc('occurred_at')->orderByDesc('id');
        $query->chunk(100, function ($rows) use (&$incidents, $current, $reads, $event, $siteIds, $operation, $stay) {
            foreach ($rows as $row) {
                if (count($incidents) >= 100) {
                    return false;
                }
                if (! in_array((int) $row->site_id, $siteIds, true) || ($stay && (int) $row->respite_stay_id !== (int) $stay->id) || ($event && ($row->respite_stay_id === null ? null : (int) $row->respite_stay_id) !== ($event->stay_id === null ? null : (int) $event->stay_id)) || ($operation !== 'view' && ! Gate::forUser($current)->allows('update', $row)) || ($event && ! $this->allowed(fn () => $this->access->assertIncident($event, $row, $current, $reads, true)))) {
                    continue;
                }
                $ref = $row->reference_number ?? 'INC-'.str_pad((string) $row->id, 4, '0', STR_PAD_LEFT);
                $incidents[] = ['id' => (int) $row->id, 'client_id' => (int) $row->client_id, 'reference' => $ref, 'label' => $ref.' | '.ucfirst(str_replace('_', ' ', (string) $row->type)).' | '.$row->occurred_at?->timezone('Pacific/Auckland')->format('d M Y')];
            }

            return count($incidents) < 100;
        });
        $plans = [];
        $reads->query(BehaviourSupportPlan::whereIn('client_id', array_keys($clients)))->where('status', '!=', 'archived')->orderByDesc('created_at')->orderByDesc('id')->chunk(200, function ($rows) use (&$plans, $current, $reads, $sites, $operation, $event, $stay) {
            foreach ($rows as $row) {
                if (! $this->allowed(fn () => $this->access->assertPlan($row, $current, $reads, $sites, $operation))) {
                    continue;
                }
                $contextSite = $event ? (int) $event->site_id : ($stay ? app(RespiteStayScope::class)->siteId($stay) : null);
                $contextStay = $event?->stay_id ?? $stay?->id;
                if ($contextSite !== null && (($row->source_schema_version === null ? (int) $row->client->site_id : (int) $row->source_site_id) !== $contextSite || ($row->source_stay_id !== null && (int) $row->source_stay_id !== (int) $contextStay))) {
                    continue;
                }
                $versions = ['candidate_version_id' => null, 'published_version_id' => null, 'authorization_state' => 'unknown'];
                foreach (['candidate' => 'current_candidate_version_id', 'published' => 'current_published_version_id'] as $kind => $field) {
                    $version = $reads->query(BehaviourSupportPlanVersion::query())->where('behaviour_support_plan_id', $row->id)->where('kind', $kind)->find($row->{$field});
                    if ($version && $this->allowed(fn () => $this->access->assertVersion($version, $row, $current, $reads, $sites))) {
                        $versions[$kind.'_version_id'] = (int) $version->id;
                    }
                }
                if ($operation !== 'view' && $versions['published_version_id']) {
                    $proof = null;
                    if ($this->allowed(function () use (&$proof, $row, $current, $reads, $sites, $event, $versions, $contextSite, $contextStay) {
                        $proof = app(BehaviourSupportPlanService::class)->resolveForEvent($row->client, (int) ($contextSite ?? $row->client->site_id), $contextStay === null ? null : (int) $contextStay, $event?->started_at?->copy() ?? now()->utc(), true, (int) $row->id, $versions['published_version_id'], $current, $reads, $sites);
                    })) {
                        $versions['authorization_state'] = $proof['authorization_state'];
                    }
                }
                $plans[] = ['id' => (int) $row->id, 'client_id' => (int) $row->client_id, 'title' => $row->title, 'status' => $row->status, 'reference' => $this->ref($row, 'BSP', 3), 'restrictive_practice_type' => $row->restrictive_practice_type, ...$versions];
            }
        });

        return ['clients' => array_values($clients), 'sites' => $siteRows, 'staff' => array_values($people), 'reviewers' => array_values($reviewers), 'followupOwners' => array_values($reviewers), 'incidents' => $incidents, 'plansForPicker' => $plans];
    }

    private function eventRow(RestraintEvent $row, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $this->access->assertEvent($row, $current, $reads, $sites);
        $site = $row->site_id ? $reads->query(Site::query())->find($row->site_id) : null;
        $incident = $row->related_incident_id ? $reads->query(ClientIncident::query())->find($row->related_incident_id) : null;
        $incidentId = $incident && (int) $incident->client_id === (int) $row->client_id && (int) $incident->site_id === (int) $row->site_id && ($incident->respite_stay_id === null ? null : (int) $incident->respite_stay_id) === ($row->stay_id === null ? null : (int) $row->stay_id) && $this->incidents->canView($current, $incident, $reads) ? (int) $incident->id : null;
        $plan = $row->behaviour_support_plan_id ? $reads->query(BehaviourSupportPlan::withTrashed())->find($row->behaviour_support_plan_id) : null;
        $planId = $plan && $this->allowed(fn () => $this->access->assertPlan($plan, $current, $reads, $sites)) ? (int) $plan->id : null;

        return ['id' => (int) $row->id, 'reference' => $this->ref($row, 'RE', 3), 'client' => ['id' => (int) $row->client->id, 'name' => trim($row->client->first_name.' '.$row->client->last_name)], 'site' => $site ? ['id' => (int) $site->id, 'name' => $site->name] : null, 'restraint_type' => $row->restraint_type, 'severity' => $row->severity, 'started_at' => $row->started_at?->toIso8601String(), 'ended_at' => $row->ended_at?->toIso8601String(), 'duration_minutes' => $row->duration_minutes, 'within_support_plan' => (bool) $row->within_support_plan, 'injury_occurred' => (bool) $row->injury_occurred, 'reviewed_at' => $row->reviewed_at?->toIso8601String(), 'behaviour_support_plan_id' => $planId, 'related_incident_id' => $incidentId, 'source_revision' => (int) $row->source_revision, 'needs_review' => $row->needsReview(), 'flags' => ['unreviewed' => $row->needsReview(), 'out_of_plan' => ! $row->within_support_plan, 'injury' => (bool) $row->injury_occurred, 'linked_incident' => $incidentId !== null]];
    }

    private function planRow(BehaviourSupportPlan $row, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $this->access->assertPlan($row, $current, $reads, $sites);
        $published = $reads->query(BehaviourSupportPlanVersion::query())->where('behaviour_support_plan_id', $row->id)->where('kind', 'published')->find($row->current_published_version_id);
        $publishedId = $published && $this->allowed(fn () => $this->access->assertVersion($published, $row, $current, $reads, $sites)) ? (int) $published->id : null;
        $today = now('Pacific/Auckland')->toDateString();
        $date = $row->review_date?->format('Y-m-d');
        $state = $row->status !== 'active' || ! $date ? 'ok' : ($date < $today ? 'overdue' : ($date <= now('Pacific/Auckland')->addDays(30)->toDateString() ? 'due' : 'ok'));

        return ['id' => (int) $row->id, 'reference' => $this->ref($row, 'BSP', 3), 'title' => $row->title, 'client' => ['id' => (int) $row->client->id, 'name' => trim($row->client->first_name.' '.$row->client->last_name)], 'status' => $row->status, 'restrictive_practice_type' => $row->restrictive_practice_type, 'review_date' => $date, 'review_state' => $state, 'published_version_id' => $publishedId, 'authorization_state' => 'unknown'];
    }

    private function pagination(LengthAwarePaginator $page, array $input): array
    {
        $page->withPath(route('health-safety.restraints.index'))->appends(array_intersect_key($input, array_flip(['lens', 'tab', 'q', 'client_id', 'site_id', 'restraint_type', 'severity', 'within_plan', 'review_state', 'period', 'from', 'to', 'events_page', 'plans_page'])));

        return $page->toArray();
    }

    private function page(array $input, string $key): int
    {
        return max(1, (int) ($input[$key] ?? 1));
    }

    private function ref(RestraintEvent|BehaviourSupportPlan $row, string $prefix, int $width): string
    {
        return $row->reference_number ?? $prefix.'-'.str_pad((string) $row->id, $width, '0', STR_PAD_LEFT);
    }

    private function allowed(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return false;
        }
    }
}
