<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    use ResolvesHrTenant;

    /**
     * Audit log viewer with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $logs = AuditLog::forOrganization($tenantId)
            ->with('user:id,name,email')
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('model_type'), fn ($q, $type) => $q->where('auditable_type', $type))
            ->when($request->query('date_from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('date_to'), fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $logs->through(function (AuditLog $log): array {
            $meta = $log->meta ?? [];
            $oldValues = $meta['old_values'] ?? $meta['old'] ?? null;
            $newValues = $meta['new_values'] ?? $meta['new'] ?? null;

            return [
                'id' => $log->id,
                'organization_id' => $log->organization_id,
                'user_id' => $log->user_id,
                'action' => $log->action,
                'auditable_type' => $log->auditable_type,
                'auditable_id' => $log->auditable_id,
                'old_values' => $oldValues,
                'new_values' => $newValues ?? ($oldValues === null ? $meta : null),
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at,
                'user' => $log->user,
            ];
        });

        // Get unique model types for the filter dropdown
        $modelTypes = AuditLog::forOrganization($tenantId)
            ->select('auditable_type')
            ->whereNotNull('auditable_type')
            ->distinct()
            ->pluck('auditable_type')
            ->values();

        // Get users who have audit entries for the filter dropdown
        $auditUsers = User::whereIn('id',
            AuditLog::forOrganization($tenantId)
                ->select('user_id')
                ->distinct()
                ->whereNotNull('user_id')
        )
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('hr/settings/audit-log', [
            'logs' => $logs,
            'actions' => AuditLog::forOrganization($tenantId)
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
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $logs = AuditLog::forOrganization($tenantId)
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'auditable_type' => $type,
            'auditable_id' => $id,
            'entries' => $logs,
        ]);
    }
}
