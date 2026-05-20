<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Services\GovernanceAuditService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders the governance audit log — a unified stream of (a) action events
 * from `governance_audit_log` and (b) entity-write events from the global
 * `audit_logs` table filtered to governance-domain models.
 */
class GovernanceAuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->resolveFilters($request);
        $entries = GovernanceAuditService::paginate($filters, perPage: 50);

        // Hydrate user names for the visible page only.
        $userIds = collect($entries->items())->pluck('user_id')->filter()->unique()->values();
        $userMap = User::whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id')
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ]);

        $items = collect($entries->items())->map(function ($row) use ($userMap) {
            $row = (array) $row;
            $row['user'] = $row['user_id'] ? ($userMap[$row['user_id']] ?? null) : null;
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
            $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
            $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;

            return $row;
        });

        return Inertia::render('Governance/AuditLog/Index', [
            'entries' => [
                'data' => $items,
                'links' => $entries->linkCollection()->toArray(),
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'total' => $entries->total(),
                'per_page' => $entries->perPage(),
            ],
            'filters' => [
                'user_id' => $filters['user_id'] ?? null,
                'entity_type' => $filters['entity_type'] ?? null,
                'action' => $filters['action'] ?? null,
                'change_type' => $filters['change_type'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'entityTypes' => $this->entityTypes(),
            'actionTypes' => $this->actionTypes(),
            'changeTypes' => $this->changeTypes(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->resolveFilters($request);

        $callback = function () use ($filters) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Kind', 'Type', 'EntityType', 'EntityId', 'UserId', 'IP', 'Description', 'CreatedAt']);

            // Stream a generous slice (up to 10k rows) — far cheaper than paging.
            $entries = GovernanceAuditService::paginate($filters, perPage: 10000);
            foreach ($entries->items() as $row) {
                $row = (array) $row;
                fputcsv($handle, [
                    $row['kind'] ?? '',
                    $row['type'] ?? '',
                    $row['entity_type'] ?? '',
                    $row['entity_id'] ?? '',
                    $row['user_id'] ?? '',
                    $row['ip_address'] ?? '',
                    $row['description'] ?? '',
                    $row['created_at'] ?? '',
                ]);
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, 'governance-audit-log-' . now()->format('Y-m-d-Hi') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function resolveFilters(Request $request): array
    {
        return array_filter([
            'user_id' => $request->integer('user_id') ?: null,
            'entity_type' => $request->string('entity_type')->toString() ?: null,
            'entity_id' => $request->integer('entity_id') ?: null,
            'action' => $request->string('action')->toString() ?: null,
            'change_type' => $request->string('change_type')->toString() ?: null,
            'from' => $request->date('from')?->toDateTimeString(),
            'to' => $request->date('to')?->toDateTimeString(),
        ]);
    }

    /** Distinct entity types in the unified audit stream. */
    private function entityTypes(): array
    {
        $a = DB::table('governance_audit_log')->distinct()->pluck('resource_type')->toArray();
        $b = DB::table('governance_change_log')->distinct()->pluck('entity_type')->toArray();

        return collect(array_merge($a, $b))->filter()->unique()->sort()->values()->toArray();
    }

    private function actionTypes(): array
    {
        return DB::table('governance_audit_log')->distinct()->pluck('action')->filter()->sort()->values()->toArray();
    }

    private function changeTypes(): array
    {
        return DB::table('governance_change_log')->distinct()->pluck('change_type')->filter()->sort()->values()->toArray();
    }
}
