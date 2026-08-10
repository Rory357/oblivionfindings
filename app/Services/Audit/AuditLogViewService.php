<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Support\SafeOperationalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AuditLogViewService
{
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
    public function query(array $filters = []): Builder
    {
        $query = AuditLog::query()
            ->with([
                'user:id,name',
                'client:id,first_name,last_name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filters['search'] ?? null) {
            $needle = $filters['search'];
            $query->where(function (Builder $search) use ($needle): void {
                $search->where('action', 'like', "%{$needle}%")
                    ->orWhere('auditable_type', 'like', "%{$needle}%")
                    ->orWhereHas('user', fn (Builder $users) => $users->where('name', 'like', "%{$needle}%"))
                    ->orWhereHas('client', fn (Builder $clients) => $clients
                        ->where('first_name', 'like', "%{$needle}%")
                        ->orWhere('last_name', 'like', "%{$needle}%"));
            });
        }

        if ($filters['user_id'] ?? null) {
            $query->where('user_id', $filters['user_id']);
        }

        if ($filters['client_id'] ?? null) {
            $query->where('client_id', $filters['client_id']);
        }

        if ($filters['module'] ?? null) {
            $this->applyModuleFilter($query, $filters['module']);
        }

        if ($filters['action'] ?? null) {
            $this->applyActionFilter($query, $filters['action']);
        }

        if ($filters['date_from'] ?? null) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] ?? null) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
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
        $fieldMap = collect([...$recordedFields, ...array_keys($before), ...array_keys($after)])
            ->filter(fn (mixed $field): bool => is_string($field) && $field !== '')
            ->take(100)
            ->mapWithKeys(fn (string $field): array => [$field => true])
            ->all();

        return [
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
    }

    /** @return array{users: array<int, array{id:int,name:string}>, clients: array<int, array{id:int,name:string}>} */
    public function filterOptions(): array
    {
        $users = User::query()
            ->whereIn('id', AuditLog::query()->select('user_id')->whereNotNull('user_id'))
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name'])
            ->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name])
            ->all();

        $clients = Client::query()
            ->whereIn('id', AuditLog::query()->select('client_id')->whereNotNull('client_id'))
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
    public function stats(array $filters): array
    {
        $base = $this->query($filters)->reorder();

        return [
            'today' => (clone $base)->whereDate('created_at', today())->count(),
            'this_week' => (clone $base)->where('created_at', '>=', now()->startOfWeek())->count(),
            'this_month' => (clone $base)->where('created_at', '>=', now()->startOfMonth())->count(),
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
