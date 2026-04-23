<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogSettingsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($this->canAccess($request), 403);

        $filters = $this->normalizeFilters($request);
        $events = $this->buildQuery($filters)
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $log) => $this->mapEvent($log));

        return inertia('settings/audit-logs', [
            'events' => $events,
            'users' => User::query()
                ->select('id', 'name')
                ->orderBy('name')
                ->get(),
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
            'stats' => [
                'today' => AuditLog::query()->whereDate('created_at', today())->count(),
                'this_week' => AuditLog::query()->where('created_at', '>=', now()->startOfWeek())->count(),
                'this_month' => AuditLog::query()->where('created_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($this->canAccess($request), 403);

        $filters = $this->normalizeFilters($request);
        $logs = $this->buildQuery($filters)->limit(5000)->get();

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Timestamp', 'User', 'Description', 'Action', 'Module', 'Subject', 'IP Address']);

            foreach ($logs as $log) {
                $event = $this->mapEvent($log);

                fputcsv($handle, [
                    $event['created_at'],
                    $event['causer']['name'] ?? 'System',
                    $event['description'],
                    $log->action,
                    $event['module'] ?? '',
                    trim(($event['subject_type'] ?? '') . ($event['subject_id'] ? " #{$event['subject_id']}" : '')),
                    $log->ip_address,
                ]);
            }

            fclose($handle);
        }, 'audit-logs-' . now()->format('Y-m-d-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function canAccess(Request $request): bool
    {
        return (bool) ($request->user()?->canDo('audit.viewAny') || $request->user()?->canDo('settings.access.manage'));
    }

    /**
     * @return array{
     *     search:?string,
     *     user:?string,
     *     module:?string,
     *     action:?string,
     *     date_from:?string,
     *     date_to:?string
     * }
     */
    private function normalizeFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'user' => ['nullable', 'string', 'max:40'],
            'module' => ['nullable', 'string', 'max:40'],
            'action' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return [
            'search' => $this->nullableFilter($validated['search'] ?? null),
            'user' => $this->nullableFilter($validated['user'] ?? null),
            'module' => $this->nullableFilter($validated['module'] ?? null),
            'action' => $this->nullableFilter($validated['action'] ?? null),
            'date_from' => $this->nullableFilter($validated['date_from'] ?? null),
            'date_to' => $this->nullableFilter($validated['date_to'] ?? null),
        ];
    }

    private function nullableFilter(?string $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '' || $normalized === 'all') {
            return null;
        }

        return $normalized;
    }

    private function buildQuery(array $filters): Builder
    {
        $query = AuditLog::query()
            ->with('user:id,name,email')
            ->orderByDesc('created_at');

        if ($filters['search']) {
            $needle = $filters['search'];

            $query->where(function (Builder $builder) use ($needle): void {
                $builder->where('action', 'like', "%{$needle}%")
                    ->orWhere('auditable_type', 'like', "%{$needle}%")
                    ->orWhere('ip_address', 'like', "%{$needle}%")
                    ->orWhereHas('user', fn (Builder $userQuery) => $userQuery
                        ->where('name', 'like', "%{$needle}%")
                        ->orWhere('email', 'like', "%{$needle}%"));
            });
        }

        if ($filters['user']) {
            $query->where('user_id', (int) $filters['user']);
        }

        if ($filters['module']) {
            $module = $filters['module'];

            if ($module === 'default') {
                $query->where(function (Builder $builder): void {
                    $builder->whereNull('auditable_type')
                        ->orWhereNull('action')
                        ->orWhere('action', 'not like', '%.%');
                });
            } else {
                $query->where(function (Builder $builder) use ($module): void {
                    $builder->where('action', 'like', "{$module}.%")
                        ->orWhere('action', 'like', "%.{$module}.%")
                        ->orWhere('auditable_type', 'like', '%' . ucfirst($module) . '%');
                });
            }
        }

        if ($filters['action']) {
            $this->applyActionFilter($query, $filters['action']);
        }

        if ($filters['date_from']) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    private function applyActionFilter(Builder $query, string $action): void
    {
        $query->where(function (Builder $builder) use ($action): void {
            match ($action) {
                'created' => $builder
                    ->where('action', 'like', '%.create%')
                    ->orWhere('action', 'like', '%created%'),
                'updated' => $builder
                    ->where('action', 'like', '%.update%')
                    ->orWhere('action', 'like', '%updated%'),
                'deleted' => $builder
                    ->where('action', 'like', '%.delete%')
                    ->orWhere('action', 'like', '%deleted%'),
                'login' => $builder->where('action', 'like', '%login%'),
                'logout' => $builder->where('action', 'like', '%logout%'),
                default => $builder->where('action', 'like', "%{$action}%"),
            };
        });
    }

    /**
     * @return array{
     *     id:int,
     *     description:string,
     *     event:?string,
     *     module:?string,
     *     subject_type:?string,
     *     subject_id:?int,
     *     properties:array<string,mixed>,
     *     causer:?array{id:int,name:string,email:?string},
     *     created_at:?string
     * }
     */
    private function mapEvent(AuditLog $log): array
    {
        $meta = is_array($log->meta) ? $log->meta : [];
        $old = Arr::get($meta, 'old', Arr::get($meta, 'previous', []));
        $attributes = Arr::get($meta, 'attributes', Arr::get($meta, 'new', Arr::get($meta, 'changes', [])));

        if ($attributes === [] && $old === []) {
            $attributes = Arr::except($meta, ['old', 'previous', 'attributes', 'new', 'changes']);
        }

        return [
            'id' => $log->id,
            'description' => Str::of(str_replace(['.', '_'], ' ', $log->action))->headline()->toString(),
            'event' => $this->deriveEvent($log->action),
            'module' => $this->deriveModule($log),
            'subject_type' => $log->auditable_type ? Str::headline(class_basename($log->auditable_type)) : null,
            'subject_id' => $log->auditable_id,
            'properties' => [
                'old' => is_array($old) ? $old : [],
                'attributes' => is_array($attributes) ? $attributes : [],
            ],
            'causer' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
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
        $actionPrefix = Str::of($log->action)->before('.')->lower()->value();

        if (in_array($actionPrefix, ['operations', 'hr', 'settings', 'finance'], true)) {
            return $actionPrefix;
        }

        if (Str::contains($actionPrefix, 'fleet')) {
            return 'fleet';
        }

        $subject = Str::lower(class_basename((string) $log->auditable_type));

        return match (true) {
            Str::contains($subject, ['staff', 'employee', 'leave', 'training', 'feedback']) => 'hr',
            Str::contains($subject, ['vehicle', 'asset', 'geofence', 'inspection', 'mileage']) => 'fleet',
            Str::contains($subject, ['invoice', 'payment', 'bank', 'currency', 'credit']) => 'finance',
            Str::contains($subject, ['setting', 'role', 'permission', 'user']) => 'settings',
            Str::contains($subject, ['shift', 'client', 'handover', 'timesheet']) => 'operations',
            default => 'default',
        };
    }
}
