<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Domain\Hr\Services\HrReportingService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HrReportController extends Controller
{
    public function __construct(
        private readonly HrReportingService $reportingService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $tenantId = $user->tenant_id ?? null;
        $reportTypes = $this->reportingService->reportTypes();

        $subscriptions = HrReportSubscription::query()
            ->forTenant($tenantId)
            ->orderByDesc('is_active')
            ->orderBy('next_run_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $recipientIds = $subscriptions
            ->pluck('recipient_user_ids')
            ->filter()
            ->flatten()
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $recipientNames = User::query()
            ->whereIn('id', $recipientIds->all())
            ->pluck('name', 'id');

        $recentExports = HrReportExport::query()
            ->forTenant($tenantId)
            ->with('generator:id,name')
            ->orderByDesc('generated_at')
            ->limit(25)
            ->get()
            ->map(fn (HrReportExport $export) => [
                'id' => $export->id,
                'report_type' => $export->report_type,
                'period_start' => optional($export->period_start)->toDateString(),
                'period_end' => optional($export->period_end)->toDateString(),
                'row_count' => (int) $export->row_count,
                'generated_at' => optional($export->generated_at)->toDateTimeString(),
                'generated_by' => $export->generator?->name,
                'subscription_id' => $export->subscription_id,
            ])
            ->values();

        $recipientOptions = User::query()
            ->staff()
            ->when(
                $tenantId !== null && Schema::hasColumn('users', 'tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId)
            )
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $recipient) => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'email' => $recipient->email,
            ])
            ->values();

        return Inertia::render('hr/reports/index', [
            'availableReports' => collect($reportTypes)->map(fn ($meta, $key) => [
                'key' => $key,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'category' => $meta['category'],
            ])->values(),
            'recentExports' => $recentExports,
            'subscriptions' => $subscriptions->map(fn (HrReportSubscription $subscription) => [
                'id' => $subscription->id,
                'report_type' => $subscription->report_type,
                'cadence' => $subscription->cadence,
                'day_of_week' => $subscription->day_of_week,
                'day_of_month' => $subscription->day_of_month,
                'run_at' => $subscription->run_at,
                'timezone' => $subscription->timezone,
                'is_active' => (bool) $subscription->is_active,
                'next_run_at' => optional($subscription->next_run_at)->toDateTimeString(),
                'last_run_at' => optional($subscription->last_run_at)->toDateTimeString(),
                'last_status' => $subscription->last_status,
                'last_error' => $subscription->last_error,
                'recipient_user_ids' => collect($subscription->recipient_user_ids ?? [])
                    ->filter(fn ($id) => is_numeric($id))
                    ->map(fn ($id) => (int) $id)
                    ->values(),
                'recipient_names' => collect($subscription->recipient_user_ids ?? [])
                    ->map(fn ($id) => $recipientNames[(int) $id] ?? null)
                    ->filter()
                    ->values(),
                'filters' => [
                    'date_from' => $subscription->filters['date_from'] ?? null,
                    'date_to' => $subscription->filters['date_to'] ?? null,
                ],
            ])->values(),
            'recipientOptions' => $recipientOptions,
            'defaultFilters' => [
                'date_from' => now()->subMonth()->toDateString(),
                'date_to' => now()->toDateString(),
            ],
            'can' => [
                'export_data' => $user->canDo('hr.reports.export'),
            ],
        ]);
    }

    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $validated = $request->validate([
            'report_type' => ['required', 'string', Rule::in(array_keys($this->reportingService->reportTypes()))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $tenantId = $user->tenant_id ?? null;
        $filters = $this->parseFilters($validated);
        $report = $this->reportingService->generate(
            reportType: $validated['report_type'],
            tenantId: $tenantId,
            dateFrom: $filters['date_from'] ?? null,
            dateTo: $filters['date_to'] ?? null,
        );

        $export = null;
        if ($user->canDo('hr.reports.export')) {
            $export = $this->reportingService->createExport(
                reportType: $validated['report_type'],
                tenantId: $tenantId,
                filters: $filters,
                generatedBy: $user->id,
            );
        }

        return Inertia::render('hr/reports/show', [
            'reportType' => $report['report_type'],
            'reportTitle' => $report['report_title'],
            'reportData' => $report['data'],
            'generatedAt' => now()->toDateTimeString(),
            'exportId' => $export?->id,
            'filters' => [
                'date_from' => $report['date_from'],
                'date_to' => $report['date_to'],
            ],
            'can' => [
                'export' => $user->canDo('hr.reports.export'),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $tenantId = $user->tenant_id ?? null;

        $recipientRule = Rule::exists('users', 'id');
        if ($tenantId !== null && Schema::hasColumn('users', 'tenant_id')) {
            $recipientRule = $recipientRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }

        $validated = $request->validate([
            'report_type' => ['required', 'string', Rule::in(array_keys($this->reportingService->reportTypes()))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $tenantId = $user->tenant_id ?? null;
        $filters = $this->parseFilters($validated);
        $export = $this->reportingService->createExport(
            reportType: $validated['report_type'],
            tenantId: $tenantId,
            filters: $filters,
            generatedBy: $user->id,
        );

        abort_unless(Storage::disk('private')->exists($export->storage_path), 404);
        $filename = basename($export->storage_path);

        return Storage::disk('private')->download($export->storage_path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function showExport(Request $request, HrReportExport $export)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $export->tenant_id);

        $filters = array_merge((array) ($export->filters ?? []), [
            'date_from' => optional($export->period_start)->toDateString(),
            'date_to' => optional($export->period_end)->toDateString(),
        ]);

        $report = $this->reportingService->generate(
            reportType: $export->report_type,
            tenantId: $export->tenant_id,
            dateFrom: $filters['date_from'] ?? null,
            dateTo: $filters['date_to'] ?? null,
        );

        return Inertia::render('hr/reports/show', [
            'reportType' => $report['report_type'],
            'reportTitle' => $report['report_title'],
            'reportData' => $report['data'],
            'generatedAt' => optional($export->generated_at)->toDateTimeString(),
            'exportId' => $export->id,
            'filters' => [
                'date_from' => $report['date_from'],
                'date_to' => $report['date_to'],
            ],
            'can' => [
                'export' => $user->canDo('hr.reports.export'),
            ],
        ]);
    }

    public function downloadExport(Request $request, HrReportExport $export)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $export->tenant_id);
        abort_unless(Storage::disk('private')->exists($export->storage_path), 404);

        $filename = basename($export->storage_path);

        return Storage::disk('private')->download($export->storage_path, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function storeSubscription(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $tenantId = $user->tenant_id ?? null;
        $recipientRule = Rule::exists('users', 'id');
        if ($tenantId !== null && Schema::hasColumn('users', 'tenant_id')) {
            $recipientRule = $recipientRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }

        $validated = $request->validate([
            'report_type' => ['required', 'string', Rule::in(array_keys($this->reportingService->reportTypes()))],
            'cadence' => ['required', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'run_at' => ['nullable', 'date_format:H:i'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'recipient_user_ids' => ['nullable', 'array'],
            'recipient_user_ids.*' => ['integer', $recipientRule],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $timezone = (string) ($validated['timezone'] ?? 'Pacific/Auckland');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);
        }

        $recipientUserIds = collect($validated['recipient_user_ids'] ?? [])
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($recipientUserIds->isEmpty()) {
            $recipientUserIds = collect([$user->id]);
        }

        $subscription = new HrReportSubscription([
            'tenant_id' => $tenantId,
            'report_type' => $validated['report_type'],
            'cadence' => $validated['cadence'],
            'day_of_week' => $validated['cadence'] === 'weekly' ? (int) ($validated['day_of_week'] ?? 1) : null,
            'day_of_month' => $validated['cadence'] === 'monthly' ? (int) ($validated['day_of_month'] ?? 1) : null,
            'run_at' => $this->normalizeRunAt((string) ($validated['run_at'] ?? '08:00')),
            'timezone' => $timezone,
            'filters' => $this->parseFilters($validated),
            'recipient_user_ids' => $recipientUserIds->all(),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $subscription->next_run_at = $subscription->is_active
            ? $this->reportingService->calculateNextRunAt($subscription, now())
            : null;
        $subscription->save();

        return redirect()->back()->with('success', 'Report subscription created.');
    }

    public function updateSubscription(Request $request, HrReportSubscription $subscription)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $subscription->tenant_id);

        $tenantId = $user->tenant_id ?? null;
        $recipientRule = Rule::exists('users', 'id');
        if ($tenantId !== null && Schema::hasColumn('users', 'tenant_id')) {
            $recipientRule = $recipientRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }

        $validated = $request->validate([
            'report_type' => ['sometimes', 'string', Rule::in(array_keys($this->reportingService->reportTypes()))],
            'cadence' => ['sometimes', 'string', Rule::in(['daily', 'weekly', 'monthly'])],
            'day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:28'],
            'run_at' => ['sometimes', 'date_format:H:i'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'recipient_user_ids' => ['nullable', 'array'],
            'recipient_user_ids.*' => ['integer', $recipientRule],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('timezone', $validated)) {
            if (! in_array((string) $validated['timezone'], timezone_identifiers_list(), true)) {
                return redirect()->back()->withErrors(['timezone' => 'Timezone is not supported.']);
            }
            $subscription->timezone = (string) $validated['timezone'];
        }

        if (array_key_exists('report_type', $validated)) {
            $subscription->report_type = $validated['report_type'];
        }

        if (array_key_exists('cadence', $validated)) {
            $subscription->cadence = $validated['cadence'];
        }

        if (array_key_exists('day_of_week', $validated) || $subscription->cadence === 'weekly') {
            $subscription->day_of_week = $subscription->cadence === 'weekly'
                ? (int) ($validated['day_of_week'] ?? $subscription->day_of_week ?? 1)
                : null;
        }

        if (array_key_exists('day_of_month', $validated) || $subscription->cadence === 'monthly') {
            $subscription->day_of_month = $subscription->cadence === 'monthly'
                ? (int) ($validated['day_of_month'] ?? $subscription->day_of_month ?? 1)
                : null;
        }

        if (array_key_exists('run_at', $validated)) {
            $subscription->run_at = $this->normalizeRunAt((string) $validated['run_at']);
        }

        if (array_key_exists('is_active', $validated)) {
            $subscription->is_active = (bool) $validated['is_active'];
        }

        if (array_key_exists('recipient_user_ids', $validated)) {
            $recipientUserIds = collect($validated['recipient_user_ids'] ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $subscription->recipient_user_ids = $recipientUserIds->isEmpty()
                ? [$user->id]
                : $recipientUserIds->all();
        }

        $subscription->filters = array_merge((array) ($subscription->filters ?? []), $this->parseFilters($validated));
        $subscription->updated_by = $user->id;
        $subscription->next_run_at = $subscription->is_active
            ? $this->reportingService->calculateNextRunAt($subscription, now())
            : null;

        $subscription->save();

        return redirect()->back()->with('success', 'Report subscription updated.');
    }

    public function toggleSubscription(Request $request, HrReportSubscription $subscription)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $subscription->tenant_id);

        $subscription->is_active = ! $subscription->is_active;
        $subscription->next_run_at = $subscription->is_active
            ? $this->reportingService->calculateNextRunAt($subscription, now())
            : null;
        $subscription->updated_by = $user->id;
        $subscription->save();

        return redirect()->back()->with('success', $subscription->is_active ? 'Subscription resumed.' : 'Subscription paused.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function parseFilters(array $payload): array
    {
        $filters = [];

        if (! empty($payload['date_from'])) {
            $filters['date_from'] = (string) $payload['date_from'];
        }

        if (! empty($payload['date_to'])) {
            $filters['date_to'] = (string) $payload['date_to'];
        }

        return $filters;
    }

    private function normalizeRunAt(string $runAt): string
    {
        return strlen($runAt) === 5 ? "{$runAt}:00" : $runAt;
    }

    private function assertTenantAccess(?int $tenantId, ?int $resourceTenantId): void
    {
        if ($tenantId !== null && $tenantId !== $resourceTenantId) {
            abort(404);
        }
    }
}
