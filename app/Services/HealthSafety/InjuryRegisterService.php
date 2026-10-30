<?php

namespace App\Services\HealthSafety;

use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Current authorised register facts. No model, query or authority escapes the callback. */
final class InjuryRegisterService
{
    private const STATUSES = ['reported', 'under_treatment', 'return_to_work', 'recovered', 'closed'];

    private const TYPES = ['strain' => 'Muscle strain', 'laceration' => 'Laceration', 'fracture' => 'Fracture',
        'burn' => 'Burn', 'contusion' => 'Contusion / bruise', 'concussion' => 'Concussion',
        'repetitive_strain' => 'Repetitive strain', 'chemical_exposure' => 'Chemical exposure',
        'biological_exposure' => 'Biological exposure', 'needle_stick' => 'Needle-stick',
        'slip_trip_fall' => 'Slip / trip / fall', 'manual_handling' => 'Manual handling',
        'psychological' => 'Psychological', 'illness' => 'Work illness', 'other' => 'Other'];

    private const TREATMENTS = ['none' => 'None required', 'first_aid' => 'First aid', 'gp_visit' => 'GP visit',
        'hospital' => 'Hospital', 'emergency_department' => 'Emergency department',
        'hospitalisation' => 'Hospitalisation', 'specialist' => 'Specialist', 'ongoing' => 'Ongoing treatment'];

    private const FACTUAL_COLUMNS = ['id', 'reference_number', 'source_revision', 'source_schema_version',
        'user_id', 'site_id', 'injury_date', 'injury_type', 'body_part_affected', 'severity', 'description',
        'worksafe_notifiable', 'acc_claim_lodged', 'lost_time_days', 'expected_return_date',
        'actual_return_date', 'next_review_date', 'status'];

    public function __construct(private readonly InjuryAccessService $access) {}

    public function index(User $viewer, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($filters): array {
            [$filters, $medical] = $this->prepare($current, $filters, $sites);
            $base = $this->filtered($current, $reads, $sites, $filters);
            $query = $this->tab(clone $base, $filters['tab']);
            $page = $query->select($this->columns($medical))->orderByDesc('injury_date')->orderByDesc('id')
                ->paginate(25, ['*'], 'page', $filters['page']);
            $rows = $page->getCollection()->all();
            $sourceStates = [];
            foreach ($rows as $row) {
                $this->parentState($row, $current, $reads, $sites, $sourceStates);
            }
            $counts = $medical ? $this->privateCounts($rows, $current, $reads, $sites, $sourceStates) : [];
            $siteNames = $this->siteNames($reads, $sites);
            $page->through(fn (WorkplaceInjury $row): array => $this->row($row, $current, $reads, $sites, $siteNames, $counts, $sourceStates[(int) $row->id]));
            $page->withPath('/health-safety/injuries')->appends(array_filter(
                array_diff_key($filters, ['page' => true]), fn ($value) => $value !== null && $value !== '',
            ));
            [$tabs, $hero] = $this->counts($base, $medical, $current, $reads, $sites, $sourceStates);
            return ['injuries' => $page->toArray(), 'tab' => $filters['tab'], 'tabCounts' => $tabs, 'hero' => $hero,
                'filters' => array_diff_key($filters, ['page' => true]), 'sites' => $this->siteOptions($reads, $sites),
                'options' => $this->optionsInContext($medical), 'medical_access' => $medical ? 'readable' : 'restricted',
                'detail' => null, 'can' => ['view' => true, 'create' => $current->canDo('hazards.create') || $current->canDo('hazards.manage'),
                    'manage' => $current->canDo('hazards.manage'), 'read_medical' => $medical]];
        });
    }

    /** Register filters only. Capture workers/incidents have a separate create-authority entry. */
    public function pickers(User $viewer, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($filters): array {
            [$filters, $medical] = $this->prepare($current, $filters, $sites);
            return ['sites' => $this->siteOptions($reads, $sites), 'options' => $this->optionsInContext($medical),
                'site_id' => $filters['site_id'], 'medical_access' => $medical ? 'readable' : 'restricted'];
        });
    }

    public function options(User $viewer): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current): array {
            $this->access->assertOperation($current, 'view');
            return $this->optionsInContext($current->canDo('hr.wellbeing.view'));
        });
    }

    /** Consumer receives detached cells inside one current callback, immediately after source proof. */
    public function export(User $viewer, array $filters, callable $consumeRow): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($filters, $consumeRow): array {
            [$filters, $medical] = $this->prepare($current, $filters, $sites);
            $query = $this->tab($this->filtered($current, $reads, $sites, $filters), $filters['tab']);
            $siteNames = $this->siteNames($reads, $sites);
            $emitted = 0;
            $query->select($this->columns($medical))->orderByDesc('injury_date')->orderByDesc('id')->chunk(200,
                function ($rows) use ($current, $reads, $sites, $siteNames, $consumeRow, &$emitted): void {
                    foreach ($rows as $row) {
                        // Canonical project re-reads the source and proves view/Site immediately before emission.
                        $fact = $this->access->project($row, $current, $reads, $sites);
                        $proof = $this->access->factualSourceProof($row, $current, $reads, $sites);
                        if ($proof['attribution_state'] === 'repair_required') {
                            $fact['acc_claim_number'] = null;
                        }
                        $reads->assertActive();
                        $consumeRow([$fact['reference'], $fact['worker']['name'] ?? null, $siteNames[$fact['site_id']] ?? null,
                            $fact['injury_date'] ? CarbonImmutable::parse($fact['injury_date'])->setTimezone('Pacific/Auckland')->toDateString() : null,
                            self::TYPES[$fact['injury_type']] ?? $fact['injury_type'], $fact['body_part_affected'],
                            $fact['severity'], $fact['status'], $fact['lost_time_days'], $fact['acc_claim_lodged'] ? 'Yes' : 'No',
                            $fact['acc_claim_number'] ?? null, $fact['worksafe_notifiable'] ? 'Yes' : 'No']);
                        $emitted++;
                    }
                });
            return ['count' => $emitted, 'filters' => array_diff_key($filters, ['page' => true]),
                'medical_access' => $medical ? 'readable' : 'restricted',
                'filename' => 'injuries-'.now('Pacific/Auckland')->toDateString().'.csv'];
        });
    }

    /** CSV quoting and spreadsheet formula escaping remain the controller's existing putCsv boundary. */
    public function exportHeaders(): array
    {
        return ['Reference', 'Worker', 'Site', 'Injury date', 'Type', 'Body part', 'Severity', 'Status',
            'Lost days', 'ACC lodged', 'ACC number', 'WorkSafe notifiable'];
    }

    private function prepare(User $current, array $input, array $sites): array
    {
        $this->access->assertOperation($current, 'view');
        $medical = $current->canDo('hr.wellbeing.view');
        // Permission check precedes any injury/treatment query, including counts and export.
        if (isset($input['treatment']) && $input['treatment'] !== '') {
            abort_unless($medical, 403);
        }
        $filters = ['q' => '', 'site_id' => null, 'severity' => null, 'status' => null, 'treatment' => null,
            'acc_open' => null, 'worksafe' => null, 'period' => 'all', 'from' => null, 'to' => null,
            'type' => null, 'body_part' => null, 'tab' => 'all', 'page' => 1];
        foreach (['q', 'severity', 'status', 'treatment', 'period', 'from', 'to', 'type', 'body_part', 'tab'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                if (! is_string($input[$key]) || mb_strlen($input[$key]) > 255) {
                    throw ValidationException::withMessages([$key => 'Choose a valid register filter.']);
                }
                $filters[$key] = $input[$key];
            }
        }
        foreach (['site_id', 'page'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $id = filter_var($input[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw ValidationException::withMessages([$key => 'Choose a valid register filter.']);
                }
                $filters[$key] = $id;
            }
        }
        if ($filters['site_id'] !== null) {
            abort_unless(in_array($filters['site_id'], $sites, true), 404);
        }
        foreach (['acc_open', 'worksafe'] as $key) {
            $filters[$key] = filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN) ?: null;
        }
        foreach (['tab' => ['all', ...self::STATUSES, 'worksafe', 'acc'], 'status' => self::STATUSES,
            'severity' => ['minor', 'moderate', 'serious', 'critical'], 'type' => array_keys(self::TYPES),
            'treatment' => array_keys(self::TREATMENTS), 'period' => ['all', 'week', '30d', 'quarter', 'year']] as $key => $allowed) {
            if ($filters[$key] !== null && ! in_array($filters[$key], $allowed, true)) {
                throw ValidationException::withMessages([$key => 'Choose a valid register filter.']);
            }
        }
        [$from, $to] = $this->range($filters);
        if ($from && $to && $from->greaterThan($to)) {
            throw ValidationException::withMessages(['to' => 'The end date must follow the start date.']);
        }
        return [$filters, $medical];
    }

    private function range(array $filters): array
    {
        if ($filters['from'] !== null || $filters['to'] !== null) {
            $dates = [];
            foreach (['from', 'to'] as $key) {
                if ($filters[$key] === null) {
                    $dates[] = null;
                    continue;
                }
                try {
                    $date = CarbonImmutable::createFromFormat('!Y-m-d', $filters[$key], 'Pacific/Auckland');
                    if (! $date || $date->format('Y-m-d') !== $filters[$key]) {
                        throw new \InvalidArgumentException;
                    }
                } catch (\Throwable) {
                    throw ValidationException::withMessages([$key => 'Use a valid date.']);
                }
                $dates[] = ($key === 'from' ? $date->startOfDay() : $date->endOfDay())->utc();
            }
            return $dates;
        }
        $days = ['week' => 7, '30d' => 30, 'quarter' => 90, 'year' => 365][$filters['period']] ?? null;
        return [$days === null ? null : CarbonImmutable::now('Pacific/Auckland')->subDays($days)->utc(), null];
    }

    private function filtered(User $current, CurrentAuthorizationReads $reads, array $sites, array $filters): Builder
    {
        $query = $this->access->readableQuery($current, $reads, $sites)->where('user_id', '>', 0);
        [$from, $to] = $this->range($filters);
        $query->when($filters['site_id'], fn ($q, $id) => $q->where('site_id', $id))
            ->when($from, fn ($q, $date) => $q->where('injury_date', '>=', $date))
            ->when($to, fn ($q, $date) => $q->where('injury_date', '<=', $date));
        foreach (['severity' => 'severity', 'status' => 'status', 'treatment' => 'medical_treatment_type', 'type' => 'injury_type'] as $filter => $column) {
            $query->when($filters[$filter], fn ($q, $value) => $q->where($column, $value));
        }
        $query->when($filters['body_part'] !== null, fn ($q) => $q->where('body_part_affected', 'like', '%'.$filters['body_part'].'%'))
            ->when($filters['acc_open'], fn ($q) => $q->where('acc_claim_lodged', true)->where('status', '!=', 'closed'))
            ->when($filters['worksafe'], fn ($q) => $q->where('worksafe_notifiable', true))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($search) => $search
                ->where('reference_number', 'like', '%'.$filters['q'].'%')->orWhere('description', 'like', '%'.$filters['q'].'%')
                ->orWhere('body_part_affected', 'like', '%'.$filters['q'].'%')
                ->orWhereHas('user', fn ($worker) => $worker->where('name', 'like', '%'.$filters['q'].'%'))));
        return $query;
    }

    private function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'reported', 'under_treatment', 'return_to_work', 'recovered', 'closed' => $query->where('status', $tab),
            'worksafe' => $query->where('worksafe_notifiable', true),
            'acc' => $query->where('acc_claim_lodged', true)->where('status', '!=', 'closed'),
            default => $query,
        };
    }

    private function counts(Builder $base, bool $medical, User $current, CurrentAuthorizationReads $reads, array $sites, array &$sourceStates): array
    {
        $byStatus = (clone $base)->select('status')->selectRaw('count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $tabs = ['all' => (int) $byStatus->sum()];
        foreach (self::STATUSES as $status) {
            $tabs[$status] = (int) ($byStatus[$status] ?? 0);
        }
        $tabs['worksafe'] = (clone $base)->where('worksafe_notifiable', true)->count();
        $tabs['acc'] = (clone $base)->where('acc_claim_lodged', true)->where('status', '!=', 'closed')->count();
        $worksafeAwaiting = (clone $base)->where('worksafe_notifiable', true)->where('status', 'reported')->count();
        $accUnlodged = (clone $base)->where('acc_claim_lodged', false)->whereNotIn('status', ['closed', 'recovered'])->count();
        $lostTimeCount = (clone $base)->where('lost_time_days', '>', 0)->count();
        $lostTimeDays = (int) (clone $base)->sum('lost_time_days');
        [$reviewDue, $reviewIntegrity] = $medical
            ? $this->reviewDue($base, $current, $reads, $sites, $sourceStates) : [null, 'restricted'];
        return [$tabs, ['live' => array_intersect_key($tabs, array_flip(['reported', 'under_treatment', 'return_to_work', 'recovered'])),
            'attention' => ['worksafe_awaiting' => $worksafeAwaiting, 'acc_unlodged' => $accUnlodged,
                'rtw_review_due' => $reviewDue, 'lost_time' => $lostTimeCount],
            'badges' => ['worksafe_awaiting' => $worksafeAwaiting, 'acc_open' => $tabs['acc'],
                'ltifr' => null, 'trifr' => null, 'lost_time_days' => $lostTimeDays],
            'measure_availability' => ['rtw_review_due' => $reviewIntegrity === 'repair_required' ? 'unavailable' : ($medical ? 'readable' : 'restricted'),
                'ltifr' => 'unknown', 'trifr' => 'unknown'], 'rtw_review_due_integrity' => $reviewIntegrity]];
    }

    /** Complete source rows under the page only: a corrupt candidate cannot disappear as an empty count. */
    private function privateCounts(array $rows, User $current, CurrentAuthorizationReads $reads, array $sites, array &$sourceStates): array
    {
        $parents = [];
        $counts = [];
        foreach ($rows as $row) {
            $this->access->assertMedicalSource($row, $current, $reads, $sites);
            $id = (int) $row->id;
            if ($this->parentState($row, $current, $reads, $sites, $sourceStates) === 'repair_required') {
                $counts[$id] = ['rtw_count' => null, 'capacity_count' => null, 'attachment_count' => null,
                    'integrity' => array_fill_keys(['rtw_count', 'capacity_count', 'attachment_count'], 'repair_required')];
                continue;
            }
            $parents[$id] = $row;
            $counts[$id] = ['rtw_count' => 0, 'capacity_count' => 0, 'attachment_count' => 0,
                'integrity' => array_fill_keys(['rtw_count', 'capacity_count', 'attachment_count'], 'valid')];
        }
        if ($parents === []) {
            return $counts;
        }
        foreach (['rtw_count' => ReturnToWorkPlan::class, 'capacity_count' => WorkCapacityAssessment::class,
            'attachment_count' => WorkplaceInjuryAttachment::class] as $key => $class) {
            $reads->query($class::query())->whereIn('workplace_injury_id', array_keys($parents))->chunkById(200,
                function ($children) use ($parents, $current, $reads, $sites, $key, &$counts): void {
                    foreach ($children as $child) {
                        $id = (int) $child->workplace_injury_id;
                        $state = $this->privateSourceState($parents[$id], $child, $current, $reads, $sites);
                        if ($state === 'repair_required') {
                            $counts[$id][$key] = null;
                            $counts[$id]['integrity'][$key] = 'repair_required';
                        } elseif ($counts[$id][$key] !== null) {
                            $counts[$id][$key]++;
                            if ($state === 'legacy_unknown') {
                                $counts[$id]['integrity'][$key] = 'legacy_unknown';
                            }
                        }
                    }
                });
        }
        return $counts;
    }

    /** A readable private source is the same exact tuple/version/afterstate definition as permanent detail. */
    private function privateSourceState(WorkplaceInjury $parent, Model $child, User $current, CurrentAuthorizationReads $reads, array $sites): string
    {
        try {
            $this->access->assertChild($parent, $child, $current, $reads, $sites, 'view');
            if ($child instanceof WorkplaceInjuryAttachment) {
                return match ($this->access->evidenceProof($parent, $child, $current, $reads, $sites)['attribution_state']) {
                    'recorded' => 'valid', 'legacy_unknown' => 'legacy_unknown', default => 'repair_required',
                };
            }
            // Retained typed history must prevent a marker downgrade from becoming a legacy count.
            $version = $this->access->currentVersionForSource($parent, $child, $reads);
            if ((int) $child->source_schema_version === 0) {
                return $version === null ? 'legacy_unknown' : 'repair_required';
            }
            if ((int) $child->source_schema_version !== 1 || data_get($child->worker_snapshot, 'id') !== (int) $parent->user_id) {
                return 'repair_required';
            }
            return $version !== null ? 'valid' : 'repair_required';
        } catch (LogicException) {
            return 'repair_required';
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }
            return 'repair_required';
        }
    }

    /** Proof must precede active/due filtering: an unaccepted status/date mutation cannot produce a false zero. */
    private function parentState(WorkplaceInjury $parent, User $current, CurrentAuthorizationReads $reads, array $sites, array &$states): string
    {
        $id = (int) $parent->id;
        if (! array_key_exists($id, $states)) {
            $proof = $this->access->factualSourceProof($parent, $current, $reads, $sites);
            $states[$id] = match ($proof['attribution_state']) {
                'recorded' => 'valid', 'legacy_unknown' => 'unknown', default => 'repair_required',
            };
        }
        return $states[$id];
    }

    private function reviewDue(Builder $base, User $current, CurrentAuthorizationReads $reads, array $sites, array &$sourceStates): array
    {
        $count = 0;
        $integrity = 'valid';
        $today = now('Pacific/Auckland')->toDateString();
        (clone $base)->select(InjuryAccessService::FACTUAL_COLUMNS)->chunkById(200,
            function ($parents) use ($current, $reads, $sites, $today, &$count, &$integrity, &$sourceStates): void {
                $eligible = [];
                foreach ($parents as $parent) {
                    if ($this->parentState($parent, $current, $reads, $sites, $sourceStates) === 'repair_required') {
                        $integrity = 'repair_required';
                        continue;
                    }
                    $eligible[(int) $parent->id] = $parent;
                }
                if ($eligible === []) {
                    return;
                }
                foreach ($reads->query(ReturnToWorkPlan::query())->whereIn('workplace_injury_id', array_keys($eligible))->get() as $plan) {
                    $state = $this->privateSourceState($eligible[(int) $plan->workplace_injury_id], $plan, $current, $reads, $sites);
                    if ($state === 'repair_required') {
                        $integrity = 'repair_required';
                        continue;
                    }
                    if ($state === 'legacy_unknown' && $integrity !== 'repair_required') {
                        $integrity = 'legacy_unknown';
                    }
                    if ($plan->status === 'active' && $plan->next_review_date !== null && $plan->next_review_date->toDateString() <= $today) {
                        $count++;
                    }
                }
            });
        return [$integrity === 'repair_required' ? null : $count, $integrity];
    }

    private function columns(bool $medical): array
    {
        return $medical ? [...self::FACTUAL_COLUMNS, ...InjuryAccessService::PRIVATE_INJURY_FIELDS] : self::FACTUAL_COLUMNS;
    }

    private function row(WorkplaceInjury $row, User $current, CurrentAuthorizationReads $reads, array $sites, array $siteNames, array $counts, string $sourceIntegrity): array
    {
        $fact = $this->access->project($row, $current, $reads, $sites);
        $medical = $fact['medical_access'] === 'readable';
        if ($sourceIntegrity === 'repair_required') {
            foreach (InjuryAccessService::PRIVATE_INJURY_FIELDS as $field) {
                $fact[$field] = null;
            }
        }
        unset($fact['notes'], $fact['immediate_treatment']);
        $entry = $counts[$fact['id']] ?? null;
        $integrity = $medical ? ($entry['integrity'] ?? array_fill_keys(['rtw_count', 'capacity_count', 'attachment_count'], 'repair_required'))
            : array_fill_keys(['rtw_count', 'capacity_count', 'attachment_count'], 'restricted');
        return [...$fact, 'source_integrity' => $sourceIntegrity, 'injury_type_label' => self::TYPES[$fact['injury_type']] ?? $fact['injury_type'],
            'site' => ['id' => $fact['site_id'], 'name' => $siteNames[$fact['site_id']] ?? null],
            'acc_claim_number' => $fact['acc_claim_number'] ?? null,
            'medical_treatment_type' => $fact['medical_treatment_type'] ?? null,
            'medical_treatment_label' => $medical && $sourceIntegrity !== 'repair_required' ? (self::TREATMENTS[$fact['medical_treatment_type'] ?? ''] ?? null) : null,
            'related_incident_id' => null, 'related_incident_ref' => null,
            'rtw_count' => $medical && $entry !== null ? $entry['rtw_count'] : null,
            'capacity_count' => $medical && $entry !== null ? $entry['capacity_count'] : null,
            'attachment_count' => $medical && $entry !== null ? $entry['attachment_count'] : null,
            'private_count_integrity' => $integrity];
    }

    private function siteOptions(CurrentAuthorizationReads $reads, array $sites): array
    {
        return $reads->query(Site::query())->whereIn('id', $sites)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(fn (Site $site): array => ['id' => (int) $site->id, 'name' => $site->name])->all();
    }

    private function siteNames(CurrentAuthorizationReads $reads, array $sites): array
    {
        return array_column($this->siteOptions($reads, $sites), 'name', 'id');
    }

    private function optionsInContext(bool $medical): array
    {
        return ['injury_types' => self::TYPES, 'treatments' => $medical ? self::TREATMENTS : null,
            'severities' => ['minor', 'moderate', 'serious', 'critical'], 'statuses' => self::STATUSES,
            'tabs' => ['all', ...self::STATUSES, 'worksafe', 'acc'], 'periods' => ['all', 'week', '30d', 'quarter', 'year']];
    }
}