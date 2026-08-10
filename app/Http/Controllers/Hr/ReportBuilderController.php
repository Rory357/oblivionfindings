<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrSavedReport;
use App\Domain\Hr\Services\ReportBuilderService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ReportBuilderController extends Controller
{
    public function __construct(
        private readonly ReportBuilderService $reportBuilderService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $reports = HrSavedReport::query()
            ->where('created_by', $user->id)
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $reports->through(fn (HrSavedReport $report): array => [
            'id' => $report->id,
            'name' => $report->name,
            'description' => $report->description,
            'report_type' => $report->report_type,
            'fields' => $report->fields,
            'last_run_at' => $report->last_run_at?->toDateTimeString(),
            'created_by' => $report->creator?->name ?? 'Unknown',
            'created_at' => $report->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('hr/reports/saved', [
            'reports' => $reports,
            'sources' => $this->reportBuilderService->getAvailableSources($user),
            'canExport' => $user->canDo('hr.reports.export'),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        return Inertia::render('hr/reports/builder', [
            'sources' => $this->reportBuilderService->getAvailableSources($user),
        ]);
    }

    public function preview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $validated = $request->validate($this->definitionRules());
        $this->reportBuilderService->assertDefinitionAllowed(
            $user,
            $validated['report_type'],
            $validated['fields'],
            $validated['filters'] ?? null,
            $validated['group_by'] ?? null,
            $validated['sort_by'] ?? null,
        );

        $query = $this->reportBuilderService->buildQuery(
            $user,
            $validated['report_type'],
            $validated['fields'],
            $validated['filters'] ?? null,
            $validated['group_by'] ?? null,
            $validated['sort_by'] ?? null,
            $validated['sort_direction'] ?? 'asc',
        );
        $total = (clone $query)->count();
        $data = $query->limit(50)->get()->toArray();

        return response()->json([
            'data' => $data,
            'fields' => $validated['fields'],
            'total' => $total,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('hr_saved_reports', 'name')->where(
                    fn ($query) => $query->where('created_by', $user->id),
                ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            ...$this->definitionRules(),
        ]);
        $this->reportBuilderService->assertDefinitionAllowed(
            $user,
            $validated['report_type'],
            $validated['fields'],
            $validated['filters'] ?? null,
            $validated['group_by'] ?? null,
            $validated['sort_by'] ?? null,
        );

        HrSavedReport::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'report_type' => $validated['report_type'],
            'fields' => $validated['fields'],
            'filters' => $validated['filters'] ?? null,
            'group_by' => null,
            'sort_by' => $validated['sort_by'] ?? null,
            'sort_direction' => $validated['sort_direction'] ?? 'asc',
            'created_by' => $user->id,
        ]);

        return redirect()->route('hr.reports.saved')->with('success', 'Report saved successfully.');
    }

    public function run(Request $request, HrSavedReport $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);
        $report = $this->ownedReport($user, $report);

        $data = $this->reportBuilderService->executeReport($report, $user);
        $report->forceFill(['last_run_at' => now()])->save();

        return response()->json([
            'data' => $data,
            'fields' => $report->fields,
            'report' => [
                'id' => $report->id,
                'name' => $report->name,
                'report_type' => $report->report_type,
                'last_run_at' => $report->last_run_at?->toDateTimeString(),
            ],
        ]);
    }

    public function export(Request $request, HrSavedReport $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);
        $report = $this->ownedReport($user, $report);

        $data = $this->reportBuilderService->executeReport($report, $user);
        $csv = $this->reportBuilderService->exportToCsv($data, $report->fields);
        $filename = str_replace(' ', '_', strtolower($report->name)).'_'.now()->format('Y-m-d').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function destroy(Request $request, HrSavedReport $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        DB::transaction(function () use ($user, $report): void {
            $lockedActor = User::query()
                ->with(['roles.permissions', 'permissionOverrides'])
                ->lockForUpdate()
                ->findOrFail($user->id);
            abort_unless($lockedActor->canDo('hr.reports.view'), 403);

            HrSavedReport::query()
                ->whereKey($report->getKey())
                ->where('created_by', $lockedActor->id)
                ->lockForUpdate()
                ->firstOrFail()
                ->delete();
        });

        return redirect()->route('hr.reports.saved')->with('success', 'Report deleted.');
    }

    /** @return array<string, array<int, mixed>> */
    private function definitionRules(): array
    {
        return [
            'report_type' => ['required', 'string', Rule::in(array_keys(ReportBuilderService::REPORT_SOURCES))],
            'fields' => ['required', 'array', 'min:1', 'max:'.ReportBuilderService::MAX_FIELDS],
            'fields.*' => ['required', 'string', 'max:64', 'distinct'],
            'filters' => ['nullable', 'array', 'max:'.ReportBuilderService::MAX_FILTERS],
            'filters.*' => ['array:field,operator,value'],
            'filters.*.field' => ['required', 'string', 'max:64'],
            'filters.*.operator' => ['required', 'string', Rule::in(ReportBuilderService::FILTER_OPERATORS)],
            'filters.*.value' => ['nullable', 'string', 'max:255'],
            'group_by' => ['prohibited'],
            'sort_by' => ['nullable', 'string', 'max:64'],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    private function ownedReport(User $user, HrSavedReport $report): HrSavedReport
    {
        return HrSavedReport::query()
            ->whereKey($report->getKey())
            ->where('created_by', $user->id)
            ->firstOrFail();
    }
}
