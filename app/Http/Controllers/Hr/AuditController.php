<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogViewService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    public function __construct(private readonly AuditLogViewService $auditLogs) {}

    /**
     * Audit log viewer with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('audit.viewAny'), 403);

        $filters = [
            'module' => 'hr',
            'user_id' => $request->integer('user_id') ?: null,
            'action' => $request->string('action')->trim()->value() ?: null,
            'date_from' => $request->string('date_from')->trim()->value() ?: null,
            'date_to' => $request->string('date_to')->trim()->value() ?: null,
        ];
        $modelType = $request->string('model_type')->trim()->value() ?: null;
        $logs = $this->auditLogs->query($filters)
            ->when($modelType, fn ($query) => $query->where('auditable_type', $modelType))
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => $this->legacyPageShape(
                $this->auditLogs->present($log),
            ));

        $hrLogs = $this->auditLogs->query(['module' => 'hr'])->reorder();
        $modelTypes = (clone $hrLogs)
            ->select('auditable_type')
            ->whereNotNull('auditable_type')
            ->distinct()
            ->pluck('auditable_type')
            ->values();

        $auditUsers = User::whereIn('id',
            (clone $hrLogs)
                ->select('user_id')
                ->distinct()
                ->whereNotNull('user_id')
        )
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('hr/settings/audit-log', [
            'logs' => $logs,
            'actions' => (clone $hrLogs)
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action')
                ->values(),
            'modelTypes' => $modelTypes,
            'users' => $auditUsers,
            'filters' => [
                'user_id' => $request->query('user_id'),
                'action' => $request->query('action'),
                'model_type' => $request->query('model_type'),
                'date_from' => $request->query('date_from'),
                'date_to' => $request->query('date_to'),
            ],
        ]);
    }

    /**
     * Show audit trail for a specific record.
     */
    public function show(Request $request, string $type, int $id)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('audit.viewAny'), 403);

        $logs = $this->auditLogs->query(['module' => 'hr'])
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->get()
            ->map(fn (AuditLog $log): array => $this->auditLogs->present($log));

        return response()->json([
            'auditable_type' => $type,
            'auditable_id' => $id,
            'entries' => $logs,
        ]);
    }

    /** @param array<string, mixed> $event */
    private function legacyPageShape(array $event): array
    {
        return [
            'id' => $event['id'],
            'user_id' => $event['actor']['id'] ?? null,
            'action' => $event['action'],
            'auditable_type' => $event['subject_type'],
            'auditable_id' => $event['subject_id'],
            'old_values' => $event['properties']['before'],
            'new_values' => $event['properties']['after'],
            'created_at' => $event['created_at'],
            'user' => $event['actor'],
        ];
    }
}
