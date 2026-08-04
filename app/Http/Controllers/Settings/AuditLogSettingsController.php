<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogViewService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogSettingsController extends Controller
{
    public function __construct(private readonly AuditLogViewService $auditLogs) {}

    public function index(Request $request)
    {
        $this->authorizeAuditAccess($request);

        $filters = $this->normalizeFilters($request);
        $events = $this->auditLogs->query($filters)
            ->paginate(50)
            ->withQueryString()
            ->through(fn ($log): array => $this->auditLogs->present($log));
        $options = $this->auditLogs->filterOptions();

        return inertia('settings/audit-logs', [
            'events' => $events,
            'users' => $options['users'],
            'filters' => $this->responseFilters($filters),
            'stats' => $this->auditLogs->stats($filters),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAuditAccess($request);

        $filters = $this->normalizeFilters($request);
        $logs = $this->auditLogs->query($filters)->limit(5000)->get();

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'wb');

            $this->putCsv($handle, ['Timestamp', 'User', 'Description', 'Action', 'Module', 'Subject']);

            foreach ($logs as $log) {
                $event = $this->auditLogs->present($log);

                $this->putCsv($handle, [
                    $event['created_at'],
                    $event['actor']['name'] ?? 'System',
                    $event['description'],
                    $log->action,
                    $event['module'] ?? '',
                    trim(($event['subject_type'] ?? '').($event['subject_id'] ? " #{$event['subject_id']}" : '')),
                ]);
            }

            fclose($handle);
        }, 'audit-logs-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeAuditAccess(Request $request): void
    {
        abort_unless($request->user()?->canDo('audit.viewAny'), 403);
    }

    /**
     * @return array{
     *     search:?string,
     *     user_id:?int,
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
            'user' => ['nullable', 'string', 'max:40', 'regex:/^(all|[1-9][0-9]*)$/'],
            'module' => ['nullable', 'string', 'max:40'],
            'action' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $user = $this->nullableFilter($validated['user'] ?? null);
        $module = $this->nullableFilter($validated['module'] ?? null);
        if ($module !== null) {
            abort_unless(in_array($module, $this->auditLogs->moduleKeys(), true), 422);
        }

        return [
            'search' => $this->nullableFilter($validated['search'] ?? null),
            'user_id' => $user !== null ? (int) $user : null,
            'module' => $module,
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

    /** @param array<string, mixed> $filters */
    private function responseFilters(array $filters): array
    {
        $response = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        if (isset($response['user_id'])) {
            $response['user'] = (string) $response['user_id'];
            unset($response['user_id']);
        }

        return $response;
    }
}
