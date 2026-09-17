<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Services\BoardPackAccessService;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Support\GovernanceAuditEntryPresenter;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders the governance audit log — a unified stream of (a) action events
 * from `governance_audit_log` and (b) record changes from
 * `governance_change_log` — as plain sentences. Titles, links and "What
 * changed" details are only included for records the viewer can open.
 */
class GovernanceAuditLogController extends Controller
{
    public function __construct(
        protected BoardPackAccessService $boardPackAccess,
        protected GovernanceAuditEntryPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->resolveFilters($request);
        $excludedEntityTypes = $this->excludedEntityTypes($request);
        $entries = GovernanceAuditService::paginate(
            $filters,
            perPage: 50,
            excludedEntityTypes: $excludedEntityTypes,
        )->withQueryString();

        // Header meters: unfiltered stream totals (same entity exclusions).
        $lastSevenDaysFrom = now((string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE))->subDays(7)->startOfDay();
        $summary = [
            'all_time' => GovernanceAuditService::paginate(
                [],
                perPage: 1,
                excludedEntityTypes: $excludedEntityTypes,
            )->total(),
            'last_7_days' => GovernanceAuditService::paginate(
                ['from' => $lastSevenDaysFrom->copy()->utc()->toDateTimeString()],
                perPage: 1,
                excludedEntityTypes: $excludedEntityTypes,
            )->total(),
            'last_7_days_from' => $lastSevenDaysFrom->toDateString(),
        ];

        $entityTypes = $this->entityTypes($excludedEntityTypes);
        $actionTypes = $this->actionTypes($excludedEntityTypes);
        $changeTypes = $this->changeTypes($excludedEntityTypes);

        return Inertia::render('Governance/AuditLog/Index', [
            'entries' => [
                'data' => $this->presenter->present($entries->items(), $request->user()),
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
                // The calendar days as chosen, not the UTC instants queried.
                'from' => isset($filters['from']) ? substr((string) $request->query('from'), 0, 10) : null,
                'to' => isset($filters['to']) ? substr((string) $request->query('to'), 0, 10) : null,
            ],
            'summary' => $summary,
            'entityTypes' => $entityTypes,
            'actionTypes' => $actionTypes,
            'changeTypes' => $changeTypes,
            // Plain names for the "Record type" and "Activity" filters.
            'recordTypeOptions' => collect($entityTypes)
                ->map(fn (string $type) => [
                    'value' => $type,
                    'label' => GovernanceAuditEntryPresenter::recordTypeLabel($type),
                ])
                ->sortBy('label')
                ->values()
                ->all(),
            'activityOptions' => collect($actionTypes)
                ->map(fn (string $type) => [
                    'value' => $type,
                    'kind' => 'action',
                    'label' => ucfirst(GovernanceAuditEntryPresenter::activityPhrase($type)),
                ])
                ->merge(collect($changeTypes)->map(fn (string $type) => [
                    'value' => $type,
                    'kind' => 'change',
                    'label' => ucfirst(GovernanceAuditEntryPresenter::activityPhrase($type)),
                ]))
                ->sortBy('label')
                ->values()
                ->all(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->resolveFilters($request);
        $excludedEntityTypes = $this->excludedEntityTypes($request);

        // A generous slice (up to 10k rows) — far cheaper than paging.
        $entries = GovernanceAuditService::paginate(
            $filters,
            perPage: 10000,
            excludedEntityTypes: $excludedEntityTypes,
        );

        $rows = collect($this->presenter->present($entries->items(), $request->user()))
            ->map(fn (array $entry) => GovernanceAuditEntryPresenter::csvRow($entry));

        return $this->streamSanitizedCsv(
            'governance-audit-log-'.now()->format('Y-m-d-Hi').'.csv',
            GovernanceAuditEntryPresenter::csvHeader(),
            $rows,
        );
    }

    /**
     * "From" and "To" are New Zealand calendar days; the log stores UTC, so the
     * query uses the start of the first day and the end of the last day.
     */
    private function resolveFilters(Request $request): array
    {
        $timezone = (string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE);

        return array_filter([
            'user_id' => $request->integer('user_id') ?: null,
            'entity_type' => $request->string('entity_type')->toString() ?: null,
            'entity_id' => $request->integer('entity_id') ?: null,
            'action' => $request->string('action')->toString() ?: null,
            'change_type' => $request->string('change_type')->toString() ?: null,
            'from' => $request->date('from', null, $timezone)?->startOfDay()->utc()->toDateTimeString(),
            'to' => $request->date('to', null, $timezone)?->endOfDay()->utc()->toDateTimeString(),
        ]);
    }

    /**
     * Distinct entity types in the unified audit stream.
     *
     * @param  array<int, string>  $excludedEntityTypes
     */
    private function entityTypes(array $excludedEntityTypes): array
    {
        $a = DB::table('governance_audit_log')
            ->whereNotIn('resource_type', $excludedEntityTypes)
            ->distinct()
            ->pluck('resource_type')
            ->toArray();
        $b = DB::table('governance_change_log')
            ->whereNotIn('entity_type', $excludedEntityTypes)
            ->distinct()
            ->pluck('entity_type')
            ->toArray();

        return collect(array_merge($a, $b))->filter()->unique()->sort()->values()->toArray();
    }

    /** @param array<int, string> $excludedEntityTypes */
    private function actionTypes(array $excludedEntityTypes): array
    {
        return DB::table('governance_audit_log')
            ->whereNotIn('resource_type', $excludedEntityTypes)
            ->distinct()
            ->pluck('action')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }

    /** @param array<int, string> $excludedEntityTypes */
    private function changeTypes(array $excludedEntityTypes): array
    {
        return DB::table('governance_change_log')
            ->whereNotIn('entity_type', $excludedEntityTypes)
            ->distinct()
            ->pluck('change_type')
            ->filter()
            ->sort()
            ->values()
            ->toArray();
    }

    /** @return array<int, string> */
    private function excludedEntityTypes(Request $request): array
    {
        return $this->boardPackAccess->canManage($request->user())
            ? []
            : ['BoardPack', BoardPack::class];
    }
}
