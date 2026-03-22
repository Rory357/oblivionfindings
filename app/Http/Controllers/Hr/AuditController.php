<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditController extends Controller
{
    /**
     * Audit log viewer with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.settings.manage'), 403);

        $tenantId = $user->tenant_id;

        $logs = HrAuditLog::forTenant($tenantId)
            ->with('user:id,name,email')
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->query('model_type'), fn ($q, $type) => $q->where('auditable_type', $type))
            ->when($request->query('date_from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->query('date_to'), fn ($q, $to) => $q->where('created_at', '<=', $to . ' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Get unique model types for the filter dropdown
        $modelTypes = HrAuditLog::forTenant($tenantId)
            ->select('auditable_type')
            ->distinct()
            ->pluck('auditable_type')
            ->values();

        // Get users who have audit entries for the filter dropdown
        $auditUsers = User::whereIn('id',
            HrAuditLog::forTenant($tenantId)
                ->select('user_id')
                ->distinct()
                ->whereNotNull('user_id')
        )
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('hr/settings/audit-log', [
            'logs' => $logs,
            'actions' => HrAuditLog::ACTIONS,
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

        $logs = HrAuditLog::forTenant($user->tenant_id)
            ->forModel($type, $id)
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
