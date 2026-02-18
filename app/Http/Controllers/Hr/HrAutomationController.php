<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAutomationRule;
use App\Domain\Hr\Models\HrAutomationRun;
use App\Domain\Hr\Services\HrAutomationService;
use App\Domain\Hr\Services\HrReportingService;
use App\Domain\Hr\Services\HrWebhookService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HrAutomationController extends Controller
{
    private const ROLE_GROUPS = [
        'managers',
        'managers_core',
        'coordinators',
        'auditors',
        'approvers',
    ];

    public function __construct(
        private readonly HrAutomationService $automationService,
        private readonly HrWebhookService $webhookService,
        private readonly HrReportingService $reportingService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $tenantId = $user->tenant_id ?? null;

        $rules = HrAutomationRule::query()
            ->forTenant($tenantId)
            ->withCount([
                'runs',
                'runs as failed_runs_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (HrAutomationRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'event_type' => $rule->event_type,
                'conditions' => $rule->conditions ?? [],
                'actions' => $rule->actions ?? [],
                'is_active' => (bool) $rule->is_active,
                'stop_on_match' => (bool) $rule->stop_on_match,
                'last_ran_at' => optional($rule->last_ran_at)->toDateTimeString(),
                'last_status' => $rule->last_status,
                'last_error' => $rule->last_error,
                'runs_count' => (int) $rule->runs_count,
                'failed_runs_count' => (int) $rule->failed_runs_count,
            ])
            ->values();

        $recentRuns = HrAutomationRun::query()
            ->forTenant($tenantId)
            ->with('rule:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (HrAutomationRun $run) => [
                'id' => $run->id,
                'rule_id' => $run->rule_id,
                'rule_name' => $run->rule?->name,
                'event_type' => $run->event_type,
                'status' => $run->status,
                'message' => $run->message,
                'executed_at' => optional($run->executed_at)->toDateTimeString(),
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

        return Inertia::render('hr/reports/automations', [
            'rules' => $rules,
            'recentRuns' => $recentRuns,
            'eventOptions' => $this->webhookService->eventOptions(),
            'actionOptions' => $this->automationService->actionOptions(),
            'roleGroupOptions' => collect(self::ROLE_GROUPS)->map(fn (string $group) => [
                'value' => $group,
                'label' => str_replace('_', ' ', $group),
            ])->values(),
            'reportTypeOptions' => collect($this->reportingService->reportTypes())
                ->map(fn (array $meta, string $key) => [
                    'value' => $key,
                    'label' => $meta['title'],
                ])->values(),
            'recipientOptions' => $recipientOptions,
            'can' => [
                'manage' => $user->canDo('hr.reports.export'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);

        $validated = $this->validatePayload($request, $user->tenant_id ?? null);

        HrAutomationRule::query()->create([
            'tenant_id' => $user->tenant_id ?? null,
            'name' => $validated['name'],
            'event_type' => $validated['event_type'],
            'conditions' => $this->buildConditions($validated),
            'actions' => [$this->buildAction($validated)],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'stop_on_match' => (bool) ($validated['stop_on_match'] ?? false),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Automation rule created.');
    }

    public function update(Request $request, HrAutomationRule $rule)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $rule->tenant_id);

        $validated = $this->validatePayload($request, $user->tenant_id ?? null, $rule->id);

        $rule->update([
            'name' => $validated['name'],
            'event_type' => $validated['event_type'],
            'conditions' => $this->buildConditions($validated),
            'actions' => [$this->buildAction($validated)],
            'is_active' => (bool) ($validated['is_active'] ?? $rule->is_active),
            'stop_on_match' => (bool) ($validated['stop_on_match'] ?? $rule->stop_on_match),
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Automation rule updated.');
    }

    public function toggle(Request $request, HrAutomationRule $rule)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $this->assertTenantAccess($user->tenant_id ?? null, $rule->tenant_id);

        $wasActive = (bool) $rule->is_active;
        $rule->update([
            'is_active' => ! $wasActive,
            'updated_by' => $user->id,
        ]);

        return redirect()->back()->with('success', $wasActive ? 'Automation rule paused.' : 'Automation rule resumed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?int $tenantId, ?int $ignoreRuleId = null): array
    {
        $recipientRule = Rule::exists('users', 'id');
        if ($tenantId !== null && Schema::hasColumn('users', 'tenant_id')) {
            $recipientRule = $recipientRule->where(fn ($query) => $query->where('tenant_id', $tenantId));
        }

        $nameRule = Rule::unique('hr_automation_rules', 'name')
            ->where(function ($query) use ($tenantId) {
                if ($tenantId === null) {
                    $query->whereNull('tenant_id');
                    return;
                }

                $query->where('tenant_id', $tenantId);
            });

        if ($ignoreRuleId !== null) {
            $nameRule = $nameRule->ignore($ignoreRuleId);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120', $nameRule],
            'event_type' => ['required', 'string', Rule::in(HrWebhookService::SUPPORTED_EVENTS)],
            'condition_field' => ['nullable', 'string', 'max:120'],
            'condition_value' => ['nullable', 'string', 'max:255'],
            'action_type' => ['required', 'string', Rule::in($this->automationService->supportedActionTypes())],
            'action_title' => ['nullable', 'string', 'max:255'],
            'action_body' => ['nullable', 'string', 'max:1000'],
            'action_url' => ['nullable', 'string', 'max:500'],
            'role_group' => ['nullable', 'string', Rule::in(self::ROLE_GROUPS)],
            'recipient_user_ids' => ['nullable', 'array'],
            'recipient_user_ids.*' => ['integer', $recipientRule],
            'report_type' => ['nullable', 'string', Rule::in(array_keys($this->reportingService->reportTypes()))],
            'report_date_from' => ['nullable', 'date'],
            'report_date_to' => ['nullable', 'date', 'after_or_equal:report_date_from'],
            'is_active' => ['nullable', 'boolean'],
            'stop_on_match' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function buildConditions(array $validated): array
    {
        $field = trim((string) ($validated['condition_field'] ?? ''));
        $value = trim((string) ($validated['condition_value'] ?? ''));

        if ($field === '' || $value === '') {
            return [];
        }

        return [
            'equals' => [
                $field => $value,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function buildAction(array $validated): array
    {
        $type = (string) $validated['action_type'];
        $base = [
            'type' => $type,
            'title' => $validated['action_title'] ?? null,
            'body' => $validated['action_body'] ?? null,
            'url' => $validated['action_url'] ?? null,
        ];

        if ($type === HrAutomationService::ACTION_NOTIFY_ROLE_GROUP) {
            $base['role_group'] = $validated['role_group'] ?? 'managers';
            return $base;
        }

        if ($type === HrAutomationService::ACTION_NOTIFY_USERS) {
            $base['user_ids'] = collect($validated['recipient_user_ids'] ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            return $base;
        }

        if ($type === HrAutomationService::ACTION_QUEUE_REPORT_EXPORT) {
            $filters = [];
            if (! empty($validated['report_date_from'])) {
                $filters['date_from'] = (string) $validated['report_date_from'];
            }
            if (! empty($validated['report_date_to'])) {
                $filters['date_to'] = (string) $validated['report_date_to'];
            }

            $base['report_type'] = (string) ($validated['report_type'] ?? 'headcount');
            $base['filters'] = $filters;
            $base['recipient_user_ids'] = collect($validated['recipient_user_ids'] ?? [])
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            return $base;
        }

        return $base;
    }

    private function assertTenantAccess(?int $tenantId, ?int $resourceTenantId): void
    {
        if ($tenantId !== null && $tenantId !== $resourceTenantId) {
            abort(404);
        }
    }
}
