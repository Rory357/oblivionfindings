<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ReferralReports
{
    public const METRICS = [
        'received' => ['Referrals', 'All referrals matching this report scope.'],
        'attempted' => ['Human attempt recorded', 'A staff contact attempt has been recorded; automated acknowledgements are excluded.'],
        'contacted' => ['Human contact recorded', 'A successful conversation has been recorded; sent messages alone do not count.'],
        'unassigned' => ['Unassigned over 24 hours', 'Unconverted, open referrals with no named owner, received more than 24 elapsed hours ago.'],
        'overdue' => ['Referrals with overdue work', 'An open next action or accessible open follow-up is overdue. Each referral is counted once.'],
        'capacity_waitlist' => ['Capacity waitlist', 'Currently on hold specifically for capacity; other waiting reasons are excluded.'],
        'converted' => ['Handed over', 'A committed conversion has created or linked a supported person. This is not service commencement.'],
        'commenced' => ['Support commenced', 'At least one service has an actual commencement time. Scheduled starts are excluded.'],
        'reopened' => ['Reopened referrals', 'Referrals with a recorded reopening by generation time. Each referral counts once, even after several reopenings.'],
    ];

    public function __construct(private readonly ReferralAccess $access, private readonly ReferralWorkQueue $work) {}

    public function filters(array $input): array
    {
        $now = CarbonImmutable::now('Pacific/Auckland');
        $data = Validator::make($input, [
            'scope' => ['nullable', Rule::in(['received', 'current'])], 'view' => ['nullable', Rule::in(['overview', 'details'])],
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'site' => ['nullable', 'regex:/^(all|central|[1-9][0-9]*)$/'],
            'owner' => ['nullable', 'regex:/^(all|unassigned|[1-9][0-9]*)$/'],
            'source' => 'nullable|string|max:60', 'stage' => ['nullable', Rule::in(ReferralSettings::STAGES)],
            'metric' => ['nullable', Rule::in(array_keys(self::METRICS))], 'page' => 'nullable|integer|min:1|max:100000',
            'stage_age' => ['nullable', Rule::in(array_keys(ReferralReportAges::LABELS))],
            'funding_age' => ['nullable', Rule::in(array_keys(ReferralReportAges::LABELS))],
            'funding_kind' => ['nullable', Rule::in(['provider', 'income'])],
            'region' => ['nullable', 'regex:/^(not_recorded|[a-f0-9]{64})$/'],
            'classification' => ['nullable', Rule::in(array_keys(ReferralReportClassification::LABELS))],
        ])->validate();
        $filters = ['scope' => $data['scope'] ?? 'received', 'view' => $data['view'] ?? 'overview',
            'from' => $data['from'] ?? $now->startOfMonth()->toDateString(), 'to' => $data['to'] ?? $now->toDateString(),
            'site' => $data['site'] ?? 'all', 'owner' => $data['owner'] ?? 'all', 'source' => $data['source'] ?? '', 'stage' => $data['stage'] ?? '',
            'metric' => $data['metric'] ?? 'received', 'page' => (int) ($data['page'] ?? 1),
            'stage_age' => $data['stage_age'] ?? '', 'funding_age' => $data['funding_age'] ?? '', 'funding_kind' => $data['funding_kind'] ?? '', 'region' => $data['region'] ?? '', 'classification' => $data['classification'] ?? ''];
        $start = CarbonImmutable::parse($filters['from'], 'Pacific/Auckland');
        $end = CarbonImmutable::parse($filters['to'], 'Pacific/Auckland');
        if ($end->lt($start) || $start->diffInDays($end) > 365 || $end->gt($now->endOfDay())) {
            throw ValidationException::withMessages(['to' => 'Choose a received-date range of up to 366 days ending today or earlier.']);
        }

        return $filters;
    }

    public function authorize(User $actor): void
    {
        abort_unless($this->access->can($actor, 'reports.view') && $this->work->canView($actor), 403);
    }

    public function query(User $actor, array $filters, ?CarbonImmutable $cutoff = null): Builder
    {
        $cutoff ??= CarbonImmutable::now('UTC');
        $query = $this->access->query($actor);
        if (! empty($filters['classification'])) {
            ReferralReportClassification::filter($query, $filters['classification']);
        }
        if ($filters['scope'] === 'received') {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Pacific/Auckland')->utc())
                ->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'Pacific/Auckland')->addDay()->utc());
        } else {
            $query->where('stage', '!=', 'closed')->where(fn ($q) => $q->where('stage', '!=', 'converted')
                ->orWhereIn('id', $this->work->work($actor)->where('status', 'open')->select('referral_id')));
        }
        if ($filters['site'] === 'central') {
            $query->whereNull('site_id');
        } elseif ($filters['site'] !== 'all') {
            $query->where('site_id', (int) $filters['site']);
        }
        foreach (['source', 'stage'] as $column) {
            if ($filters[$column] !== '') {
                $query->where($column, $filters[$column]);
            }
        }
        if ($filters['owner'] === 'unassigned') {
            $query->whereNull('owner_id');
        } elseif ($filters['owner'] !== 'all') {
            $query->where('owner_id', (int) $filters['owner']);
        }

        if (! empty($filters['stage_age'])) {
            ReferralReportAges::stage($query, $filters['stage_age'], $cutoff);
        }
        if (! empty($filters['region'])) {
            $ids = [];
            foreach ((clone $query)->select(['id', 'intake'])->cursor() as $record) {
                if (ReferralReportRegion::group($record->intake['region'] ?? null)['key'] === $filters['region']) {
                    $ids[] = $record->id;
                }
            }
            $query->whereIn('id', $ids);
        }
        if (! empty($filters['funding_age']) || ! empty($filters['funding_kind'])) {
            abort_unless($this->access->can($actor, 'funding.view'), 403);
            $cases = ReferralFundingCase::query()->whereIn('referral_id', (clone $query)->select('id'))->whereIn('status', ReferralReportAges::PENDING);
            if (! empty($filters['funding_kind'])) {
                $cases->where('personal_income', $filters['funding_kind'] === 'income');
            }
            $ids = [];
            foreach ($cases->select(['referral_id', 'details'])->cursor() as $case) {
                if (empty($filters['funding_age']) || ReferralReportAges::bucket($case->details['last_submission']['submitted_at'] ?? null, $cutoff) === $filters['funding_age']) {
                    $ids[] = $case->referral_id;
                }
            }
            $query->whereIn('id', array_values(array_unique($ids)));
        }

        return $query;
    }

    public function metric(Builder $query, User $actor, string $metric, CarbonImmutable $cutoff): Builder
    {
        return match ($metric) {
            'attempted' => $query->whereNotNull('first_contact_attempt_at')->where('first_contact_attempt_at', '<=', $cutoff),
            'contacted' => $query->whereNotNull('first_human_contact_at')->where('first_human_contact_at', '<=', $cutoff),
            'unassigned' => $query->whereNotIn('stage', ['closed', 'converted'])->whereNull('owner_id')->where('created_at', '<', $cutoff->subHours(24)),
            'overdue' => $query->where('stage', '!=', 'closed')->where(fn ($q) => $q->where('next_due_at', '<', $cutoff)
                ->orWhereIn('id', $this->work->work($actor)->where('status', 'open')->where('due_at', '<', $cutoff)->select('referral_id'))),
            'capacity_waitlist' => $query->whereNotIn('stage', ['closed', 'converted'])->where('hold_reason', 'capacity_waitlist'),
            'converted' => $query->whereNotNull('converted_at')->where('converted_at', '<=', $cutoff),
            'commenced' => $query->whereIn('id', ReferralServiceStart::query()->whereNotNull('commenced_at')->where('commenced_at', '<=', $cutoff)->select('referral_id')),
            'reopened' => $query->whereIn('id', ReferralEvent::query()->where('audience', 'intake')->where('kind', 'referral.reopened')->where('occurred_at', '<=', $cutoff)->whereColumn('occurred_at', '>=', 'care_referrals.created_at')->select('referral_id')),
            default => $query,
        };
    }

    public function build(User $actor, array $filters, CarbonImmutable $cutoff): array
    {
        $this->authorize($actor);
        $query = $this->query($actor, $filters, $cutoff);
        $metrics = [];
        foreach (self::METRICS as $key => [$label, $definition]) {
            $metrics[] = ['key' => $key, 'label' => $label, 'definition' => $definition,
                'value' => $this->metric(clone $query, $actor, $key, $cutoff)->count()];
        }
        $series = [];
        if ($filters['scope'] === 'received') {
            for ($day = CarbonImmutable::parse($filters['from'], 'Pacific/Auckland'); $day->toDateString() <= $filters['to']; $day = $day->addDay()) {
                $series[$day->toDateString()] = ['date' => $day->toDateString(), 'received' => 0, 'contacted' => 0];
            }
        }
        $delays = ['attempt' => ['hours' => 0, 'count' => 0], 'contact' => ['hours' => 0, 'count' => 0]];
        foreach ((clone $query)->select(['id', 'created_at', 'first_contact_attempt_at', 'first_human_contact_at'])->cursor() as $record) {
            $key = $record->created_at->timezone('Pacific/Auckland')->toDateString();
            if (isset($series[$key])) {
                $series[$key]['received']++;
                if ($record->first_human_contact_at && $record->first_human_contact_at->lte($cutoff)) {
                    $series[$key]['contacted']++;
                }
            }
            foreach (['attempt' => 'first_contact_attempt_at', 'contact' => 'first_human_contact_at'] as $type => $column) {
                if ($record->$column && $record->$column->gte($record->created_at) && $record->$column->lte($cutoff)) {
                    $delays[$type]['hours'] += $record->created_at->diffInSeconds($record->$column) / 3600;
                    $delays[$type]['count']++;
                }
            }
        }
        foreach ($delays as &$delay) {
            $delay['mean_hours'] = $delay['count'] ? round($delay['hours'] / $delay['count'], 1) : null;
        }
        unset($delay);
        $groups = [];
        foreach (['stage', 'source', 'requested_service', 'hold_reason', 'closure_reason'] as $column) {
            $groups[$column] = (clone $query)->whereNotNull($column)
                ->when($column === 'hold_reason', fn ($q) => $q->whereNotIn('stage', ['closed', 'converted']))
                ->when($column === 'closure_reason', fn ($q) => $q->where('stage', 'closed'))
                ->select($column)->selectRaw('COUNT(*) AS total')->groupBy($column)->orderByDesc('total')->get()
                ->map(fn ($row) => ['key' => $row->$column, 'count' => (int) $row->total])->all();
        }
        $groups['owner'] = (clone $query)->with('owner:id,name')->select('owner_id')->selectRaw('COUNT(*) AS total')->groupBy('owner_id')->orderByDesc('total')->get()
            ->map(fn ($row) => ['key' => $row->owner_id ? (string) $row->owner_id : 'unassigned', 'label' => $row->owner?->name ?? 'Unassigned', 'count' => (int) $row->total])->all();
        $stageAges = array_column(ReferralReportAges::empty(), null, 'key');
        foreach ((clone $query)->whereNotIn('stage', ['closed', 'converted'])->select(['created_at', 'stage_changed_at'])->cursor() as $record) {
            $stageAges[ReferralReportAges::bucket($record->stage_changed_at, $cutoff, $record->created_at)]['count']++;
        }
        $groups['stage_age'] = array_values($stageAges);
        $regions = [];
        foreach ((clone $query)->select(['id', 'intake'])->orderBy('id')->cursor() as $record) {
            $region = ReferralReportRegion::group($record->intake['region'] ?? null);
            $regions[$region['key']] ??= [...$region, 'count' => 0];
            $regions[$region['key']]['count']++;
        }
        $groups['region'] = collect($regions)->sortByDesc('count')->values()->all();
        $groups['classification'] = ReferralReportClassification::groups(clone $query);
        $funding = null;
        if ($this->access->can($actor, 'funding.view')) {
            $cases = ReferralFundingCase::query()->whereIn('referral_id', (clone $query)->select('id'));
            if (! empty($filters['funding_age']) || ! empty($filters['funding_kind'])) {
                $cases->whereIn('status', ReferralReportAges::PENDING);
                if (! empty($filters['funding_kind'])) {
                    $cases->where('personal_income', $filters['funding_kind'] === 'income');
                }
                if (! empty($filters['funding_age'])) {
                    $ids = [];
                    foreach ((clone $cases)->select(['id', 'details'])->cursor() as $case) {
                        if (ReferralReportAges::bucket($case->details['last_submission']['submitted_at'] ?? null, $cutoff) === $filters['funding_age']) {
                            $ids[] = $case->id;
                        }
                    }
                    $cases->whereIn('id', $ids);
                }
            }
            $funding = [
                'cases' => (clone $cases)->count(),
                'provider_cases' => (clone $cases)->where('personal_income', false)->count(),
                'income_cases' => (clone $cases)->where('personal_income', true)->count(),
                'by_pathway' => $this->pathwayGroups(clone $cases),
                'by_status' => (clone $cases)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->get()->map(fn ($row) => ['key' => $row->status, 'count' => (int) $row->total])->all(),
                'outcomes' => ReferralFundingDecision::query()->whereIn('id', ReferralFundingDecision::query()->whereIn('funding_case_id', (clone $cases)->select('id'))->selectRaw('MAX(id)')->groupBy('funding_case_id'))
                    ->select('outcome')->selectRaw('COUNT(*) AS total')->groupBy('outcome')->get()->map(fn ($row) => ['key' => $row->outcome, 'count' => (int) $row->total])->all(),
            ];
            $ages = ['provider' => array_column(ReferralReportAges::empty(), null, 'key'), 'income' => array_column(ReferralReportAges::empty(), null, 'key')];
            foreach ((clone $cases)->whereIn('status', ReferralReportAges::PENDING)->select(['personal_income', 'details'])->cursor() as $case) {
                $ages[$case->personal_income ? 'income' : 'provider'][ReferralReportAges::bucket($case->details['last_submission']['submitted_at'] ?? null, $cutoff)]['count']++;
            }
            $funding['pending_age'] = array_map('array_values', $ages);
        }

        $calendars = app(ReferralWorkingTime::class)->calendars(clone $query);

        // Every number below is exact; the small-group rule then hides what could identify someone,
        // for every viewer and in the summary export (config/referral-reports.php).
        return app(ReferralReportPrivacy::class)->protect(['generated_at' => $cutoff->toISOString(), 'filters' => $filters, 'metrics' => $metrics, 'trend' => array_values($series), 'groups' => $groups, 'delays' => $delays, 'funding' => $funding,
            'working_time' => app(ReferralWorkingTime::class)->report($actor, clone $query, $cutoff, $calendars),
            'history' => app(ReferralHistoryReport::class)->build(clone $query, $cutoff, $calendars),
            'definitions' => [
                'scope' => $filters['scope'] === 'received' ? 'Received dates are inclusive New Zealand calendar dates. Every record matching the selected filters remains in the denominator; unresolved referrals are not excluded automatically.' : 'Currently open referrals, plus handed-over referrals with accessible open follow-ups, matching the selected filters. Received dates do not limit this workload.',
                'cutoff' => 'Outcomes are current at generation time, not a historical end-of-period snapshot. Counts are referrals, not unique people.',
                'trend' => 'Received by day, with the subset from each day that has successful human contact by the generation time. This is not daily contact throughput.',
                'delays' => 'Mean elapsed hours among records with a valid recorded timestamp. Not company-working hours. Uncontacted records are excluded from the mean and remain in the cohort.',
                'funding' => 'Counts are assistance cases and latest recorded external decisions, not verified funding or revenue. Personal income is separate. Native units are not combined into a monetary total.',
                'stage_age' => 'Elapsed time in the current open intake stage, including on-hold referrals; closed and converted records are excluded. This is not historical time across repeated visits to a stage or company-working time.',
                'funding_age' => 'Cases submitted or awaiting assessment/decision, measured from their latest recorded external submission to generation time. Missing/invalid dates are shown separately. Counts are cases; selecting a bucket opens referrals with a matching case. This is not company-working time or an agency decision deadline.',
                'working_time' => 'Mean hours per referral, using the original intake publication’s office window, working days, holidays and time zone. Later publications or explicit settings migrations do not rewrite this calendar. Legacy records without a complete original calendar are excluded from working-hour means, with their counts shown. Each mean shows its own denominator; uncontacted referrals are excluded from contact means. Stage time is the current visit only. Hold time follows the current uninterrupted hold independently of stage; changing its reason does not reset it. Legacy on-hold records without hold events use the stage date; other missing history is unavailable. Overdue time starts at the oldest accessible open action or next-action deadline. These are organisation working hours, not agency processing hours.',
                'region' => 'Region reported at intake, grouped without differences in letter case or spacing. This is not a verified address, current placement or approved owning site. Blank entries remain visible as Not recorded.',
                'classification' => 'Only currently closed records are classified as duplicate, test or spam. All other enquiries and referrals, including unresolved and reopened work, remain in Other. All classifications are included unless explicitly filtered. Duplicate closure is a reviewed enquiry link, not a merged person or proof of unique-person counts.',
                'history' => ReferralHistoryReport::DEFINITION,
            ]]);
    }

    /** Resolve labels from the case's pinned publication, never today's renamed configuration. */
    private function pathwayGroups(Builder $cases): array
    {
        $counts = $cases->select('pathway', 'personal_income', 'settings_version_id')->selectRaw('COUNT(*) AS total')
            ->groupBy('pathway', 'personal_income', 'settings_version_id')->get();
        $versions = ReferralSettingsVersion::query()->whereIn('id', $counts->pluck('settings_version_id'))->get()->keyBy('id');
        $groups = [];
        foreach ($counts as $row) {
            $pathways = $versions->get($row->settings_version_id)?->configuration['pathways'] ?? [];
            $pathway = collect($pathways)->firstWhere('id', $row->pathway);
            $label = $pathway['label'] ?? ucfirst(str_replace('_', ' ', $row->pathway));
            $key = $row->pathway.':'.substr(hash('sha256', $label.':'.(int) $row->personal_income), 0, 16);
            $groups[$key] ??= ['key' => $key, 'pathway' => $row->pathway, 'label' => $label, 'count' => 0, 'personal_income' => $row->personal_income];
            $groups[$key]['count'] += (int) $row->total;
        }

        return array_values($groups);
    }
}
