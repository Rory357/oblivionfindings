<?php

namespace App\Services\Audit;

use App\Domain\Governance\Services\BoardPackAccessService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Services\Medication\MedicationGeneralAuditPrivacy;
use App\Support\SafeOperationalData;
use App\Support\WorkerClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AuditLogViewService
{
    public function __construct(
        private readonly BoardPackAccessService $boardPackAccess,
        private readonly MedicationGeneralAuditPrivacy $medicationAudit,
    ) {}

    /** Clinical and narrative fields of a chemical exposure; never shown in a general audit row. */
    private const EXPOSURE_CLINICAL_FIELDS = ['circumstances', 'symptoms', 'first_aid_given', 'medical_attention_sought',
        'medical_treatment', 'medical_outcome'];

    /** @var array<string, array{actions: array<int, string>, subjects: array<int, string>}> */
    private const MODULES = [
        'it' => [
            'actions' => ['it'],
            'subjects' => ['Domain\\It', 'ItTicket', 'ItChange', 'ItProblem', 'ItKnowledge'],
        ],
        'monitoring' => [
            'actions' => ['monitoring', 'monitor', 'monitoringprofile', 'collector'],
            'subjects' => ['Monitoring', 'Monitor', 'Collector'],
        ],
        'security_devices' => [
            'actions' => [
                'security-devices', 'security_devices', 'device', 'deviceassignment',
                'deviceassetlink', 'devicemaintenancerecord', 'devicegroup', 'devicerelationship',
                'tracking', 'unifi', 'milesight', 'queclink', 'integration',
                'integrationsiteconfig', 'integrationsitesecret', 'integrationsynclog',
            ],
            'subjects' => ['SecurityDevices', 'Device', 'Integration'],
        ],
        'operations' => [
            'actions' => ['operations', 'client', 'clients', 'shift', 'shifts', 'timesheet', 'timesheets', 'handover', 'site', 'sites'],
            'subjects' => ['Client', 'Shift', 'Timesheet', 'Handover'],
        ],
        'hr' => [
            'actions' => [
                'hr', 'staff', 'employee', 'recruitment', 'training', 'leave',
                'attendance', 'onboarding', 'onboardingchecklist', 'user.employee_intake',
            ],
            'subjects' => [
                'Domain\\Hr', 'Staff', 'Employee', 'Recruitment', 'Training',
                'Leave', 'Feedback', 'Attendance', 'Onboarding',
            ],
        ],
        'fleet' => [
            'actions' => ['fleet', 'asset', 'vehicle', 'geofence'],
            'subjects' => ['Vehicle', 'Asset', 'Geofence', 'Inspection', 'Mileage'],
        ],
        'settings' => [
            'actions' => ['settings', 'auth', 'identity', 'role', 'permission'],
            'subjects' => ['Setting', 'Role', 'Permission', 'User'],
        ],
        'finance' => [
            'actions' => ['finance', 'invoice', 'payment', 'bank', 'currency', 'credit'],
            'subjects' => ['Invoice', 'Payment', 'Bank', 'Currency', 'Credit'],
        ],
    ];

    /** @return array<int, string> */
    public function moduleKeys(): array
    {
        return [...array_keys(self::MODULES), 'default'];
    }

    /**
     * @param  array{
     *     search?:?string,
     *     action?:?string,
     *     user_id?:?int,
     *     client_id?:?int,
     *     module?:?string,
     *     date_from?:?string,
     *     date_to?:?string
     * }  $filters
     */
    public function query(array $filters = [], ?User $viewer = null): Builder
    {
        $query = AuditLog::query()
            ->with([
                'user:id,name',
                'client:id,first_name,last_name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $this->boardPackAccess->scopeAuditVisibility($query, $viewer);
        // EA-020: medication rows follow the medication Site scope, person
        // rule and controlled view; without a viewer none are shown.
        $this->medicationAudit->apply($query, $viewer);

        if ($filters['search'] ?? null) {
            $needle = $filters['search'];
            $query->where(function (Builder $search) use ($needle): void {
                $search->where('action', 'like', "%{$needle}%")
                    ->orWhere('auditable_type', 'like', "%{$needle}%")
                    ->orWhereHas('user', fn (Builder $users) => $users->where('name', 'like', "%{$needle}%"))
                    // #13: risk assessment rows never show a client, so a client's name can't find them either.
                    ->orWhere(fn (Builder $byClient) => $byClient
                        ->where(fn (Builder $notRisk) => $notRisk->whereNull('auditable_type')->orWhereNotIn('auditable_type', self::riskAssessmentTypes()))
                        ->whereHas('client', fn (Builder $clients) => $clients
                            ->where('first_name', 'like', "%{$needle}%")
                            ->orWhere('last_name', 'like', "%{$needle}%")));
            });
        }

        if ($filters['user_id'] ?? null) {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['client_id'] ?? null) {
            $query->where('client_id', $filters['client_id'])
                ->where(fn (Builder $notRisk) => $notRisk->whereNull('auditable_type')->orWhereNotIn('auditable_type', self::riskAssessmentTypes()));
        }

        if ($filters['module'] ?? null) {
            $this->applyModuleFilter($query, $filters['module']);
        }

        if ($filters['action'] ?? null) {
            $this->applyActionFilter($query, $filters['action']);
        }

        // EA-077 (Lane C7): the filter's dates are NZ calendar days; rows are stored in UTC.
        if ($filters['date_from'] ?? null) {
            $query->where('created_at', '>=', WorkerClock::dayStartUtc((string) $filters['date_from']));
        }

        if ($filters['date_to'] ?? null) {
            $query->where('created_at', '<=', WorkerClock::dayEndUtc((string) $filters['date_to']));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function present(AuditLog $log): array
    {
        $meta = is_array($log->meta) ? $log->meta : [];
        $before = $this->firstArray($meta, ['before', 'old', 'previous']);
        $after = $this->firstArray($meta, ['after', 'attributes', 'new', 'changes', 'values']);
        $recordedFields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
        if ($this->isExposureRecord($log)) {
            // Stephan, 25 Oct ("Hide the clinical notes"): older exposure rows hold the worker's clinical text. Values
            // only ever show through the safe allow-list below; an exposure row no longer even lists which clinical
            // fields changed. The row and its other changes still show.
            $before = Arr::except($before, self::EXPOSURE_CLINICAL_FIELDS);
            $after = Arr::except($after, self::EXPOSURE_CLINICAL_FIELDS);
            $recordedFields = array_values(array_diff($recordedFields, self::EXPOSURE_CLINICAL_FIELDS));
        }
        $fieldMap = collect([...$recordedFields, ...array_keys($before), ...array_keys($after)])
            ->filter(fn (mixed $field): bool => is_string($field) && $field !== '')
            ->take(100)
            ->mapWithKeys(fn (string $field): array => [$field => true])
            ->all();

        $row = [
            'id' => (int) $log->id,
            'action' => $log->action,
            'description' => Str::of(str_replace(['.', '_'], ' ', $log->action))->headline()->toString(),
            'event' => $this->deriveEvent($log->action),
            'module' => $this->deriveModule($log),
            'subject_type' => $log->auditable_type ? Str::headline(class_basename($log->auditable_type)) : null,
            'subject_id' => $log->auditable_id ? (int) $log->auditable_id : null,
            'properties' => [
                'fields' => SafeOperationalData::auditFields($fieldMap),
                'before' => SafeOperationalData::auditValues($before),
                'after' => SafeOperationalData::auditValues($after),
            ],
            'actor' => $log->user ? [
                'id' => (int) $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'client' => $log->client ? [
                'id' => (int) $log->client->id,
                'name' => trim($log->client->first_name.' '.$log->client->last_name),
            ] : null,
            'created_at' => $log->created_at?->toIso8601String(),
        ];

        return $this->isRiskAssessment($log) ? $this->presentRiskAssessment($log, $row) : $row;
    }

    /** #13: the risk scoring values an ordinary assessment's audit row may show (never its free text). */
    private const RISK_VALUE_FIELDS = ['status', 'likelihood', 'consequence', 'risk_score', 'risk_level', 'residual_likelihood',
        'residual_consequence', 'residual_risk_score', 'residual_risk_level', 'risk_acceptable', 'review_frequency_days', 'review_due_at'];

    private function isRiskAssessment(AuditLog $log): bool
    {
        return in_array($log->auditable_type, self::riskAssessmentTypes(), true);
    }

    /** @return list<string> */
    private static function riskAssessmentTypes(): array
    {
        return array_values(array_unique([\App\Models\HsRiskAssessment::class, (new \App\Models\HsRiskAssessment)->getMorphClass()]));
    }

    /**
     * #13 (board 13-3; Stephan, 2 Nov: "Same rule as job 4"). A risk assessment row names the assessment by its
     * reference and lists which fields changed. An ordinary assessment also shows its title and the scoring values that
     * changed. One linked to a person (a client, or a safety review about a client or a worker) shows only who changed
     * what and when: no title (it can name the person), no values and no client, for every viewer.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentRiskAssessment(AuditLog $log, array $row): array
    {
        $risk = $log->auditable_id ? \App\Models\HsRiskAssessment::query()->whereKey($log->auditable_id)
            ->first(['id', 'reference_number', 'title', 'assessable_type', 'assessable_id', 'hs_event_id']) : null;
        $marker = \App\Models\HsRiskAssessmentAcceptance::query()->where('audit_log_id', $log->id)
            ->first(['id', 'before_business', 'business', 'before_binding', 'after_binding']);
        $personLinked = $this->riskLinkedToPerson($risk, $marker);

        $before = is_array($marker?->before_business['snapshot'] ?? null) ? $marker->before_business['snapshot'] : null;
        $after = is_array($marker?->business['snapshot'] ?? null) ? $marker->business['snapshot'] : [];
        $changed = [];
        if ($before !== null) {
            foreach ($after as $field => $value) {
                if (is_string($field) && ($before[$field] ?? null) !== $value) { $changed[] = $field; }
            }
        }
        $label = $risk === null ? 'Risk assessment' : (string) $risk->reference_number;
        $row['subject_label'] = $personLinked || $risk === null || $risk->title === null ? $label : $label.' · '.$risk->title;

        if ($personLinked) {
            return [...$row, 'client' => null, 'values_hidden' => 'linked_person',
                'properties' => ['fields' => SafeOperationalData::auditFields(array_fill_keys($changed, true)), 'before' => [], 'after' => []]];
        }
        $pick = fn (array $values): array => collect($values)->only(array_intersect($changed, self::RISK_VALUE_FIELDS))
            ->filter(fn ($value) => $value === null || is_scalar($value))->all();

        return [...$row, 'values_hidden' => null, 'properties' => ['fields' => SafeOperationalData::auditFields(array_fill_keys($changed, true)),
            'before' => $pick($before ?? []), 'after' => $pick($after)]];
    }

    private function riskLinkedToPerson(?\App\Models\HsRiskAssessment $risk, ?\App\Models\HsRiskAssessmentAcceptance $marker): bool
    {
        foreach ([$marker?->before_binding, $marker?->after_binding] as $binding) {
            if (! is_array($binding)) { continue; }
            if (($binding['client_id'] ?? null) !== null) { return true; }
            foreach ((array) ($binding['events'] ?? []) as $event) {
                if (($event['tuple']['client_id'] ?? null) !== null || ($event['tuple']['staff_id'] ?? null) !== null || ($event['exposure'] ?? null) !== null) { return true; }
            }
        }
        if ($risk === null) { return true; } // Nothing to check against: say less, not more.
        if (in_array($risk->assessable_type, [\App\Models\Client::class, (new \App\Models\Client)->getMorphClass()], true)) { return true; }
        $eventIds = array_filter([$risk->hs_event_id,
            in_array($risk->assessable_type, [\App\Models\HsEvent::class, (new \App\Models\HsEvent)->getMorphClass()], true) ? $risk->assessable_id : null]);

        return $eventIds !== [] && \App\Models\HsEvent::withTrashed()->whereIn('id', $eventIds)
            ->where(fn ($person) => $person->whereNotNull('client_id')->orWhereNotNull('staff_id'))->exists();
    }

    /** @return array{users: array<int, array{id:int,name:string}>, clients: array<int, array{id:int,name:string}>} */
    public function filterOptions(?User $viewer = null): array
    {
        $visibleAudit = $this->query([], $viewer)->reorder();
        $users = User::query()
            ->whereIn('id', (clone $visibleAudit)->select('user_id')->whereNotNull('user_id'))
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name])
            ->all();

        $clients = Client::query()
            ->whereIn('id', (clone $visibleAudit)->select('client_id')->whereNotNull('client_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(250)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $client): array => [
                'id' => (int) $client->id,
                'name' => trim($client->first_name.' '.$client->last_name),
            ])
            ->all();

        return compact('users', 'clients');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{today:int,this_week:int,this_month:int}
     */
    public function stats(array $filters, ?User $viewer = null): array
    {
        $base = $this->query($filters, $viewer)->reorder();

        // EA-077 (Lane C7): today, this week (from Monday) and this month in NZ time.
        $today = WorkerClock::today();

        return [
            'today' => (clone $base)->where('created_at', '>=', $today->utc())->count(),
            'this_week' => (clone $base)->where('created_at', '>=', $today->startOfWeek()->utc())->count(),
            'this_month' => (clone $base)->where('created_at', '>=', $today->startOfMonth()->utc())->count(),
        ];
    }

    private function applyActionFilter(Builder $query, string $action): void
    {
        $query->where(function (Builder $actions) use ($action): void {
            match ($action) {
                'created' => $actions
                    ->where('action', 'like', '%.create%')
                    ->orWhere('action', 'like', '%created%'),
                'updated' => $actions
                    ->where('action', 'like', '%.update%')
                    ->orWhere('action', 'like', '%updated%'),
                'deleted' => $actions
                    ->where('action', 'like', '%.delete%')
                    ->orWhere('action', 'like', '%deleted%'),
                'login' => $actions->where('action', 'like', '%login%'),
                'logout' => $actions->where('action', 'like', '%logout%'),
                default => $actions->where('action', 'like', "%{$action}%"),
            };
        });
    }

    private function applyModuleFilter(Builder $query, string $module): void
    {
        if ($module === 'default') {
            $query->whereNot(fn (Builder $known) => $this->addKnownModuleConditions($known));

            return;
        }

        $definition = self::MODULES[$module] ?? null;
        if ($definition === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $matching) => $this->addModuleConditions($matching, $definition));
    }

    private function addKnownModuleConditions(Builder $query): void
    {
        $query->whereRaw('1 = 0');
        foreach (self::MODULES as $definition) {
            $query->orWhere(fn (Builder $module) => $this->addModuleConditions($module, $definition));
        }
    }

    /** @param array{actions: array<int, string>, subjects: array<int, string>} $definition */
    private function addModuleConditions(Builder $query, array $definition): void
    {
        $query->where(fn (Builder $actions) => $this->addActionPrefixConditions($actions, $definition['actions']))
            ->orWhere(function (Builder $subjectFallback) use ($definition): void {
                $subjectFallback
                    ->whereNotNull('auditable_type')
                    ->whereNot(fn (Builder $knownActions) => $this->addKnownActionPrefixConditions($knownActions))
                    ->where(fn (Builder $subjects) => $this->addSubjectConditions($subjects, $definition['subjects']));
            });
    }

    /** @param array<int, string> $prefixes */
    private function addActionPrefixConditions(Builder $query, array $prefixes): void
    {
        $query->whereRaw('1 = 0');
        foreach ($prefixes as $prefix) {
            $query->orWhere('action', $prefix)
                ->orWhere('action', 'like', $prefix.'.%');
        }
    }

    private function addKnownActionPrefixConditions(Builder $query): void
    {
        $prefixes = collect(self::MODULES)
            ->flatMap(fn (array $definition): array => $definition['actions'])
            ->unique()
            ->values()
            ->all();

        $this->addActionPrefixConditions($query, $prefixes);
    }

    /** @param array<int, string> $fragments */
    private function addSubjectConditions(Builder $query, array $fragments): void
    {
        $query->whereRaw('1 = 0');
        foreach ($fragments as $fragment) {
            $query->orWhere('auditable_type', 'like', "%{$fragment}%");
        }
    }

    private function deriveEvent(string $action): ?string
    {
        $normalized = Str::lower($action);

        return match (true) {
            Str::contains($normalized, ['create', 'created']) => 'created',
            Str::contains($normalized, ['update', 'updated']) => 'updated',
            Str::contains($normalized, ['delete', 'deleted']) => 'deleted',
            Str::contains($normalized, 'login') => 'login',
            Str::contains($normalized, 'logout') => 'logout',
            default => null,
        };
    }

    private function deriveModule(AuditLog $log): string
    {
        $action = Str::lower($log->action);
        foreach (self::MODULES as $module => $definition) {
            if (collect($definition['actions'])->contains(
                fn (string $prefix): bool => $action === $prefix
                    || str_starts_with($action, $prefix.'.'),
            )) {
                return $module;
            }
        }

        $subject = Str::lower((string) $log->auditable_type);
        foreach (self::MODULES as $module => $definition) {
            if (collect($definition['subjects'])->contains(
                fn (string $fragment): bool => Str::contains($subject, Str::lower($fragment)),
            )) {
                return $module;
            }
        }

        return 'default';
    }

    /** @param array<string, mixed> $meta */
    private function isExposureRecord(AuditLog $log): bool
    {
        return in_array($log->auditable_type, [
            \App\Models\SubstanceExposureRecord::class,
            (new \App\Models\SubstanceExposureRecord)->getMorphClass(),
        ], true);
    }

    private function firstArray(array $meta, array $keys): array
    {
        foreach ($keys as $key) {
            $value = Arr::get($meta, $key);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }
}
