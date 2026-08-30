<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLogViewService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly AuditLogViewService $auditLogs) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('audit.viewAny'), 403);

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'module' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $queryFilters = [
            'search' => $filters['q'] ?? null,
            'action' => $filters['action'] ?? null,
            'user_id' => isset($filters['user_id']) ? (int) $filters['user_id'] : null,
            'client_id' => isset($filters['client_id']) ? (int) $filters['client_id'] : null,
            'module' => $this->moduleFilter($filters['module'] ?? null),
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];

        $logs = $this->auditLogs->query($queryFilters, $user)
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($log): array => $this->auditLogs->present($log));

        return inertia('audit/index', [
            'logs' => $logs,
            'filters' => $filters,
            'filter_options' => $this->auditLogs->filterOptions($user),
        ]);
    }

    private function moduleFilter(?string $module): ?string
    {
        if ($module === null || $module === '' || $module === 'all') {
            return null;
        }

        abort_unless(in_array($module, $this->auditLogs->moduleKeys(), true), 422);

        return $module;
    }
}
