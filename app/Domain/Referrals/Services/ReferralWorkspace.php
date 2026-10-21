<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Lists, counts and filter options for the Referrals workspace (referrals,
 * funding assistance and follow-ups). Every query starts from ReferralAccess
 * scope, so counts, rows and search results only ever cover records the
 * viewer could open; restricted and foreign records never contribute.
 */
final class ReferralWorkspace
{
    public const VIEWS = ['referrals', 'funding', 'follow_ups'];

    public const REFERRAL_QUEUES = ['open', 'all', 'mine', 'unassigned', 'overdue', 'due_today', 'awaiting_contact', 'waiting', 'capacity_waitlist', 'ready_for_handover', 'funding_help', 'central'];

    public const FUNDING_QUEUES = ['open', 'mine', 'unassigned', 'overdue'];

    public const FOLLOW_UP_QUEUES = ['open', 'mine', 'overdue', 'due_today', 'upcoming'];

    public const SOURCES = ['website', 'phone', 'email', 'in_person', 'agency', 'internal'];

    public const OPEN_FUNDING = ['exploring', 'preparing', 'submitted', 'awaiting_assessment', 'awaiting_decision', 'decision_received'];

    /** Saved views (spec §5): fixed queue presets; permissions still apply whenever one is opened. */
    public const SAVED_VIEWS = [
        'due_today' => 'Follow up today',
        'funding_help' => 'Funding help',
        'waiting' => 'Waiting for someone else',
        'ready_for_handover' => 'Ready for handover',
        'capacity_waitlist' => 'Capacity waitlist',
        'mine' => 'Mine',
        'unassigned' => 'Unassigned',
        'overdue' => 'Overdue',
    ];

    private const PER_PAGE = 30;

    private const LANE_PREVIEW = 8;

    public function __construct(
        private readonly ReferralAccess $access,
        private readonly ReferralWorkQueue $queue,
        private readonly ReferralSearchIndex $search,
        private readonly ReferralPresenter $presenter,
    ) {}

    /** Validate and normalise workspace filters; a queue that means nothing for the view falls back to "open". */
    public function filters(array $input): array
    {
        $data = Validator::make($input, [
            'view' => ['nullable', Rule::in(self::VIEWS)],
            'queue' => ['nullable', Rule::in(array_unique([...self::REFERRAL_QUEUES, ...self::FUNDING_QUEUES, ...self::FOLLOW_UP_QUEUES]))],
            'stage' => ['nullable', Rule::in(ReferralSettings::STAGES)],
            'site_id' => 'nullable|integer|min:1',
            'owner' => ['nullable', 'regex:/^(unassigned|[1-9][0-9]{0,9})$/'],
            'source' => ['nullable', Rule::in(self::SOURCES)],
            'hold_reason' => ['nullable', Rule::in(array_column(ReferralSettings::choices(app(ReferralSettings::class)->effective()?->configuration, 'hold'), 'id'))],
            'priority' => ['nullable', 'string', 'max:24', 'regex:/^[a-z_]+$/'],
            'received_from' => 'nullable|date_format:Y-m-d',
            'received_to' => 'nullable|date_format:Y-m-d|after_or_equal:received_from',
            'pathway' => ['nullable', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/'],
            'funding_status' => ['nullable', Rule::in(self::OPEN_FUNDING)],
            'audience' => ['nullable', Rule::in(['intake', 'assessment', 'funding'])],
            'q' => 'nullable|string|max:80',
            'reference' => 'nullable|string|max:26',
            'layout' => ['nullable', Rule::in(['cards', 'table', 'list', 'pipeline'])],
            'focus' => 'nullable|ulid',
            'act' => ['nullable', Rule::in(['assign', 'contact', 'transition', 'priority'])],
            'page' => 'nullable|integer|min:1|max:10000',
        ])->validate();
        $data['view'] ??= 'referrals';
        $queues = match ($data['view']) {
            'funding' => self::FUNDING_QUEUES, 'follow_ups' => self::FOLLOW_UP_QUEUES, default => self::REFERRAL_QUEUES,
        };
        $data['queue'] = in_array($data['queue'] ?? 'open', $queues, true) ? ($data['queue'] ?? 'open') : 'open';
        $layout = $data['layout'] ?? 'table';
        $data['layout'] = $layout === 'list' || ($layout === 'pipeline' && $data['view'] !== 'referrals') ? 'table' : $layout;
        if (empty($data['q']) && ! empty($data['reference'])) {
            $data['q'] = $data['reference'];
        }
        unset($data['reference']);

        return array_filter($data, fn ($value) => $value !== null && $value !== '');
    }

    /** Header meters: always the viewer's whole scope, so each number matches the view it links to. */
    public function stats(User $actor): array
    {
        $base = $this->access->query($actor);

        return [
            'open' => $this->applyQueue(clone $base, $actor, 'open')->count(),
            'unassigned' => $this->applyQueue(clone $base, $actor, 'unassigned')->count(),
            'overdue' => $this->applyQueue(clone $base, $actor, 'overdue')->count(),
            'ready_for_handover' => $this->applyQueue(clone $base, $actor, 'ready_for_handover')->count(),
            'awaiting_contact' => $this->applyQueue(clone $base, $actor, 'awaiting_contact')->count(),
        ];
    }

    /** @return list<array{key: string, label: string, count: int}> */
    public function savedViews(User $actor, array $stats): array
    {
        $base = $this->access->query($actor);
        $known = ['unassigned' => $stats['unassigned'], 'overdue' => $stats['overdue'], 'ready_for_handover' => $stats['ready_for_handover']];
        $views = [];
        foreach (self::SAVED_VIEWS as $key => $label) {
            $views[] = ['key' => $key, 'label' => $label, 'count' => $known[$key] ?? $this->applyQueue(clone $base, $actor, $key)->count()];
        }

        return $views;
    }

    /** Rail counters: open referrals, open funding cases (when permitted) and open follow-ups. */
    public function railCounts(User $actor, array $stats): array
    {
        return [
            'referrals' => $stats['open'],
            'funding' => $this->access->can($actor, 'funding.view') ? $this->fundingQuery($actor, ['queue' => 'open'])->count() : null,
            'follow_ups' => DB::query()->fromSub($this->followUpUnion($actor, []), 'follow_ups')->count(),
        ];
    }

    public function referrals(User $actor, array $filters): Builder
    {
        $query = $this->scope($actor, $filters);
        $this->applyQueue($query, $actor, $filters['queue'] ?? 'open');
        if (isset($filters['stage'])) {
            $query->where('care_referrals.stage', $filters['stage']);
        }
        $this->applyReferralFilters($query, $filters);

        return $query;
    }

    public function referralPage(User $actor, array $filters): LengthAwarePaginator
    {
        $page = $this->referrals($actor, $filters)->with(['site:id,name', 'owner:id,name'])
            ->orderByDesc('care_referrals.created_at')->orderByDesc('care_referrals.id')
            ->paginate(self::PER_PAGE)->withQueryString();
        $rows = $this->rows($actor, collect($page->items()));
        $page->setCollection(collect($rows));

        return $page;
    }

    /** Pipeline lanes: complete stage totals for the current filters (bar stage) with bounded previews. */
    public function pipeline(User $actor, array $filters, array $stages): array
    {
        $filters = array_diff_key($filters, ['stage' => true]);
        $totals = $this->referrals($actor, $filters)->toBase()->select('care_referrals.stage', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('care_referrals.stage')->pluck('aggregate', 'stage');
        $late = $this->overdueConstraint($this->referrals($actor, $filters), $actor)->toBase()
            ->select('care_referrals.stage', DB::raw('COUNT(*) as aggregate'))->groupBy('care_referrals.stage')->pluck('aggregate', 'stage');
        $lanes = [];
        foreach ($stages as $stage) {
            $preview = (int) ($totals[$stage['id']] ?? 0) > 0
                ? $this->referrals($actor, [...$filters, 'stage' => $stage['id']])->with(['site:id,name', 'owner:id,name'])
                    ->orderByRaw('care_referrals.next_due_at IS NULL')->orderBy('care_referrals.next_due_at')->orderBy('care_referrals.id')
                    ->limit(self::LANE_PREVIEW)->get()
                : collect();
            $lanes[] = ['stage' => $stage['id'], 'label' => $stage['label'], 'total' => (int) ($totals[$stage['id']] ?? 0),
                'overdue' => (int) ($late[$stage['id']] ?? 0), 'data' => $this->rows($actor, $preview)];
        }

        return $lanes;
    }

    /** Open funding cases on referrals the viewer can see; requires the funding view permission. */
    public function fundingPage(User $actor, array $filters): LengthAwarePaginator
    {
        abort_unless($this->access->can($actor, 'funding.view'), 403);
        $page = $this->fundingQuery($actor, $filters)
            ->addSelect(['next_due_at' => ReferralWorkItem::query()->select('due_at')->whereColumn('funding_case_id', 'care_referral_funding_cases.id')
                ->where('status', 'open')->orderBy('due_at')->limit(1)])
            ->with('navigator:id,name')
            ->orderByRaw('next_due_at IS NULL')->orderBy('next_due_at')->orderBy('care_referral_funding_cases.id')
            ->paginate(self::PER_PAGE)->withQueryString();
        $cases = collect($page->items());
        $referrals = CareReferral::query()->with(['site:id,name', 'owner:id,name'])->whereIn('id', $cases->pluck('referral_id')->unique())->get()->keyBy('id');
        $work = ReferralWorkItem::query()->whereIn('funding_case_id', $cases->pluck('id'))->where('status', 'open')
            ->orderBy('due_at')->orderBy('id')->get()->groupBy('funding_case_id')->map->first();
        $page->setCollection($cases->map(function (ReferralFundingCase $case) use ($referrals, $work): array {
            $referral = $referrals[$case->referral_id];
            $next = $work[$case->id] ?? null;

            return [
                'id' => $case->id, 'reference' => $case->reference, 'pathway' => $case->pathway,
                'pathway_label' => $case->details['pathway_label'] ?? ucfirst(str_replace('_', ' ', $case->pathway)),
                'status' => $case->status, 'personal_income' => $case->personal_income,
                'navigator_id' => $case->navigator_id, 'navigator_name' => $case->navigator?->name,
                'next_action' => $next?->details['title'] ?? null, 'next_due_at' => $next?->due_at?->toISOString(), 'next_work_id' => $next?->id,
                'is_overdue' => $next !== null && $next->due_at->isPast(),
                'referral' => $this->referralLink($referral),
            ];
        }));

        return $page;
    }

    /** Open follow-ups and next actions the viewer may see, soonest first, with honest due-group totals. */
    public function followUpPage(User $actor, array $filters): array
    {
        $base = DB::query()->fromSub($this->followUpUnion($actor, $filters), 'follow_ups');
        if (isset($filters['owner'])) {
            $filters['owner'] === 'unassigned' ? $base->whereNull('owner_id') : $base->where('owner_id', (int) $filters['owner']);
        }
        if (($filters['queue'] ?? 'open') === 'mine') {
            $base->where('owner_id', $actor->id);
        }
        [$todayStart, $todayEnd] = $this->today();
        $now = CarbonImmutable::now();
        // Group totals cover the whole view before any due-state narrowing.
        $groups = (clone $base)->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END), 0) as overdue, COALESCE(SUM(CASE WHEN due_at >= ? AND due_at < ? THEN 1 ELSE 0 END), 0) as today',
            [$now, $now, $todayEnd])->first();
        match ($filters['queue'] ?? 'open') {
            'overdue' => $base->where('due_at', '<', $now),
            'due_today' => $base->where('due_at', '>=', $todayStart)->where('due_at', '<', $todayEnd),
            'upcoming' => $base->where('due_at', '>=', $now),
            default => null,
        };
        $page = $base->orderBy('due_at')->orderBy('row_type')->orderBy('row_id')->paginate(self::PER_PAGE)->withQueryString();
        $items = collect($page->items());
        $work = ReferralWorkItem::query()->with('owner:id,name')->whereIn('id', $items->where('row_type', 'work')->pluck('row_id'))->get()->keyBy('id');
        $referrals = CareReferral::query()->with(['site:id,name', 'owner:id,name'])->whereIn('id', $items->pluck('referral_id')->unique())->get()->keyBy('id');
        $page->setCollection($items->map(function ($row) use ($work, $referrals): array {
            $referral = $referrals[$row->referral_id];
            $item = $row->row_type === 'work' ? $work[$row->row_id] : null;
            $due = $item?->due_at ?? $referral->next_due_at;

            return [
                'key' => $row->row_type.'-'.$row->row_id, 'type' => $row->row_type, 'id' => (int) $row->row_id,
                'title' => $item ? ($item->details['title'] ?? 'Referral follow-up') : $referral->next_action,
                'kind' => $row->kind, 'audience' => $row->audience,
                'due_at' => $due?->toISOString(), 'ends_at' => $item?->ends_at?->toISOString(),
                'owner_id' => $item ? $item->owner_id : $referral->owner_id,
                'owner_name' => $item ? $item->owner?->name : $referral->owner?->name,
                'is_overdue' => $due !== null && $due->isPast(),
                'href' => '/operations/referrals/'.$referral->reference.($item ? '?work='.$item->id : '?tab=work'),
                'referral' => $this->referralLink($referral),
            ];
        }));

        return ['page' => $page, 'groups' => ['total' => (int) $groups->total, 'overdue' => (int) $groups->overdue, 'today' => (int) $groups->today]];
    }

    /** Filter-row choices drawn from the viewer's own scope (never another site's staff or values). */
    public function options(User $actor): array
    {
        $scope = $this->access->query($actor);
        $ownerIds = (clone $scope)->whereNotNull('owner_id')->select('owner_id');
        $owners = User::query()->where(function ($query) use ($ownerIds, $actor): void {
            $query->whereIn('id', $ownerIds)->orWhereIn('id', $this->queue->work($actor)->where('status', 'open')->whereNotNull('owner_id')->select('owner_id'));
            if ($this->access->can($actor, 'funding.view')) {
                $query->orWhereIn('id', ReferralFundingCase::query()->whereIn('referral_id', $this->access->query($actor)->select('care_referrals.id'))
                    ->whereNotNull('navigator_id')->select('navigator_id'));
            }
        })->orderBy('name')->limit(200)->get(['id', 'name']);

        return [
            'owners' => $owners->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'priorities' => (clone $scope)->distinct()->orderBy('priority')->pluck('priority')->all(),
            'sources' => self::SOURCES,
            // Every configured reason, including turned-off ones still on older records.
            'hold_reasons' => array_map(fn (array $item) => ['id' => $item['id'], 'label' => $item['label']],
                ReferralSettings::choices(app(ReferralSettings::class)->effective()?->configuration, 'hold')),
            'funding_statuses' => self::OPEN_FUNDING,
            'saved_views' => array_keys(self::SAVED_VIEWS),
        ];
    }

    public function applyQueue(Builder $query, User $actor, string $queue): Builder
    {
        $open = fn (Builder $builder) => $builder->whereNotIn('care_referrals.stage', ['closed', 'converted']);

        return match ($queue) {
            'all' => $query,
            'mine' => $open($query)->where('care_referrals.owner_id', $actor->id),
            'unassigned' => $open($query)->whereNull('care_referrals.owner_id'),
            'overdue' => $this->overdueConstraint($query, $actor),
            'due_today' => $this->dueTodayConstraint($query, $actor),
            'awaiting_contact' => $open($query)->whereNull('care_referrals.first_human_contact_at'),
            'waiting' => $open($query)->whereNotNull('care_referrals.hold_reason'),
            'capacity_waitlist' => $open($query)->where('care_referrals.hold_reason', 'capacity_waitlist'),
            'ready_for_handover' => $query->where('care_referrals.stage', 'ready_for_handover'),
            'funding_help' => $query->where('care_referrals.stage', 'funding_assistance'),
            'central' => $open($query)->whereNull('care_referrals.site_id'),
            default => $open($query),
        };
    }

    /** Same rule as the Reports "overdue" measure: an overdue next action or accessible open follow-up. */
    private function overdueConstraint(Builder $query, User $actor): Builder
    {
        $now = CarbonImmutable::now();

        return $query->where('care_referrals.stage', '!=', 'closed')->where(fn (Builder $late) => $late->where('care_referrals.next_due_at', '<', $now)
            ->orWhereIn('care_referrals.id', $this->queue->work($actor)->where('status', 'open')->where('due_at', '<', $now)->select('referral_id')));
    }

    private function dueTodayConstraint(Builder $query, User $actor): Builder
    {
        [$start, $end] = $this->today();

        return $query->where('care_referrals.stage', '!=', 'closed')->where(fn (Builder $due) => $due->where(fn (Builder $next) => $next->where('care_referrals.next_due_at', '>=', $start)->where('care_referrals.next_due_at', '<', $end))
            ->orWhereIn('care_referrals.id', $this->queue->work($actor)->where('status', 'open')->where('due_at', '>=', $start)->where('due_at', '<', $end)->select('referral_id')));
    }

    /** Scope + site + name/reference search, shared by every view. */
    private function scope(User $actor, array $filters): Builder
    {
        $query = $this->access->query($actor);
        if (isset($filters['site_id'])) {
            $query->where('care_referrals.site_id', (int) $filters['site_id']);
        }
        if (! empty($filters['q'])) {
            $this->search->constrain($query, (string) $filters['q']);
        }

        return $query;
    }

    private function applyReferralFilters(Builder $query, array $filters): void
    {
        if (isset($filters['owner'])) {
            $filters['owner'] === 'unassigned' ? $query->whereNull('care_referrals.owner_id') : $query->where('care_referrals.owner_id', (int) $filters['owner']);
        }
        foreach (['source', 'hold_reason', 'priority'] as $key) {
            if (isset($filters[$key])) {
                $query->where('care_referrals.'.$key, $filters[$key]);
            }
        }
        // Received dates are New Zealand calendar days, inclusive.
        if (isset($filters['received_from'])) {
            $query->where('care_referrals.created_at', '>=', CarbonImmutable::parse($filters['received_from'], 'Pacific/Auckland')->startOfDay()->utc());
        }
        if (isset($filters['received_to'])) {
            $query->where('care_referrals.created_at', '<', CarbonImmutable::parse($filters['received_to'], 'Pacific/Auckland')->addDay()->startOfDay()->utc());
        }
    }

    private function fundingQuery(User $actor, array $filters): Builder
    {
        $referrals = $this->scope($actor, $filters);
        if (isset($filters['stage'])) {
            $referrals->where('care_referrals.stage', $filters['stage']);
        }
        $query = ReferralFundingCase::query()->select('care_referral_funding_cases.*')
            ->whereIn('care_referral_funding_cases.referral_id', $referrals->select('care_referrals.id'))
            ->whereIn('care_referral_funding_cases.status', self::OPEN_FUNDING);
        match ($filters['queue'] ?? 'open') {
            'mine' => $query->where('care_referral_funding_cases.navigator_id', $actor->id),
            'unassigned' => $query->whereNull('care_referral_funding_cases.navigator_id'),
            'overdue' => $query->whereIn('care_referral_funding_cases.id', ReferralWorkItem::query()->where('status', 'open')->where('due_at', '<', now())
                ->whereNotNull('funding_case_id')->select('funding_case_id')),
            default => null,
        };
        if (isset($filters['owner'])) {
            $filters['owner'] === 'unassigned' ? $query->whereNull('care_referral_funding_cases.navigator_id') : $query->where('care_referral_funding_cases.navigator_id', (int) $filters['owner']);
        }
        if (isset($filters['pathway'])) {
            $query->where('care_referral_funding_cases.pathway', $filters['pathway']);
        }
        if (isset($filters['funding_status'])) {
            $query->where('care_referral_funding_cases.status', $filters['funding_status']);
        }

        return $query;
    }

    /** Open work items (by permitted audience) plus untracked next actions, projected once each — as the calendar does. */
    private function followUpUnion(User $actor, array $filters): QueryBuilder
    {
        $referrals = $this->scope($actor, $filters)->where('care_referrals.stage', '!=', 'closed');
        if (isset($filters['stage'])) {
            $referrals->where('care_referrals.stage', $filters['stage']);
        }
        $audience = $filters['audience'] ?? null;
        $work = $this->queue->work($actor)->where('care_referral_work_items.status', 'open')
            ->whereIn('care_referral_work_items.referral_id', (clone $referrals)->select('care_referrals.id'))
            ->when($audience, fn (Builder $query) => $query->where('care_referral_work_items.audience', $audience))
            ->toBase()->select([DB::raw("'work' as row_type"), 'care_referral_work_items.id as row_id', 'care_referral_work_items.referral_id',
                'care_referral_work_items.owner_id', 'care_referral_work_items.due_at', 'care_referral_work_items.audience', 'care_referral_work_items.kind']);
        if ($audience !== null && $audience !== 'intake') {
            return $work;
        }
        $next = $this->queue->nextActions($actor)->whereIn('care_referrals.id', (clone $referrals)->select('care_referrals.id'))
            ->toBase()->select([DB::raw("'next' as row_type"), 'care_referrals.id as row_id', 'care_referrals.id as referral_id',
                'care_referrals.owner_id', 'care_referrals.next_due_at as due_at', DB::raw("'intake' as audience"), DB::raw("'next_action' as kind")]);

        return $work->unionAll($next);
    }

    /** @return list<array<string, mixed>> */
    private function rows(User $actor, $records): array
    {
        if ($records->isEmpty()) {
            return [];
        }
        $now = CarbonImmutable::now();
        [$start, $end] = $this->today();
        $ids = $records->pluck('id')->all();
        $lateWork = $this->queue->work($actor)->where('status', 'open')->whereIn('referral_id', $ids)->where('due_at', '<', $now)->distinct()->pluck('referral_id')->flip();
        $todayWork = $this->queue->work($actor)->where('status', 'open')->whereIn('referral_id', $ids)->where('due_at', '>=', $start)->where('due_at', '<', $end)->distinct()->pluck('referral_id')->flip();

        return $records->map(function (CareReferral $referral) use ($lateWork, $todayWork, $now, $start, $end): array {
            $active = $referral->stage !== 'closed';
            $next = $referral->next_due_at;

            return [...$this->presenter->summary($referral),
                'is_overdue' => $active && (($next && $next->lt($now)) || isset($lateWork[$referral->id])),
                'is_due_today' => $active && (($next && $next->gte($start) && $next->lt($end)) || isset($todayWork[$referral->id])),
            ];
        })->values()->all();
    }

    private function referralLink(CareReferral $referral): array
    {
        $summary = $this->presenter->summary($referral);

        return ['reference' => $referral->reference, 'name' => $summary['name'], 'preferred_name' => $summary['preferred_name'],
            'site_name' => $summary['site_name'], 'stage' => $referral->stage, 'restricted' => $referral->restricted];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} today's New Zealand day as UTC instants */
    private function today(): array
    {
        $start = CarbonImmutable::now('Pacific/Auckland')->startOfDay();

        return [$start->utc(), $start->addDay()->startOfDay()->utc()];
    }
}
