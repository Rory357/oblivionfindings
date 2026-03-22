<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrSavedReport;
use App\Domain\Hr\Services\ReportBuilderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ReportBuilderController extends Controller
{
    public function __construct(
        private readonly ReportBuilderService $reportBuilderService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — list saved reports                                         */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $tenantId = null;

        $reports = HrSavedReport::forTenant($tenantId)
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $reports->through(fn ($report) => [
            'id' => $report->id,
            'name' => $report->name,
            'description' => $report->description,
            'report_type' => $report->report_type,
            'fields' => $report->fields,
            'is_scheduled' => $report->is_scheduled,
            'last_run_at' => $report->last_run_at?->toDateTimeString(),
            'created_by' => $report->creator?->name ?? 'Unknown',
            'created_at' => $report->created_at?->toDateTimeString(),
        ]);

        return Inertia::render('hr/reports/saved', [
            'reports' => $reports,
            'sources' => $this->reportBuilderService->getAvailableSources(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — show report builder UI                                    */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        return Inertia::render('hr/reports/builder', [
            'sources' => $this->reportBuilderService->getAvailableSources(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Preview — execute report and return first 50 rows                  */
    /* ------------------------------------------------------------------ */

    public function preview(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $validated = $request->validate([
            'report_type' => ['required', 'string', Rule::in(array_keys(ReportBuilderService::REPORT_SOURCES))],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
            'filters.*.field' => ['required_with:filters', 'string'],
            'filters.*.operator' => ['required_with:filters', 'string'],
            'filters.*.value' => ['nullable', 'string'],
            'group_by' => ['nullable', 'string'],
            'sort_by' => ['nullable', 'string'],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $query = $this->reportBuilderService->buildQuery(
            $validated['report_type'],
            $validated['fields'],
            $validated['filters'] ?? null,
            $validated['group_by'] ?? null,
            $validated['sort_by'] ?? null,
            $validated['sort_direction'] ?? 'asc',
        );

        $data = $query->limit(50)->get()->toArray();

        return response()->json([
            'data' => $data,
            'fields' => $validated['fields'],
            'total' => $query->count(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — save report configuration                                  */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'report_type' => ['required', 'string', Rule::in(array_keys(ReportBuilderService::REPORT_SOURCES))],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
            'group_by' => ['nullable', 'string'],
            'sort_by' => ['nullable', 'string'],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        HrSavedReport::create([
            'tenant_id' => null,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'report_type' => $validated['report_type'],
            'fields' => $validated['fields'],
            'filters' => $validated['filters'] ?? null,
            'group_by' => $validated['group_by'] ?? null,
            'sort_by' => $validated['sort_by'] ?? null,
            'sort_direction' => $validated['sort_direction'] ?? 'asc',
            'created_by' => $user->id,
        ]);

        return redirect()->route('hr.reports.saved')->with('success', 'Report saved successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Run — execute a saved report and return full data                  */
    /* ------------------------------------------------------------------ */

    public function run(Request $request, HrSavedReport $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $data = $this->reportBuilderService->executeReport($report);

        return response()->json([
            'data' => $data,
            'fields' => $report->fields,
            'report' => [
                'id' => $report->id,
                'name' => $report->name,
                'report_type' => $report->report_type,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Export — download report as CSV or Excel                           */
    /* ------------------------------------------------------------------ */

    public function export(Request $request, HrSavedReport $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.export'), 403);

        $format = $request->input('format', 'csv');
        $data = $this->reportBuilderService->executeReport($report);

        if ($format === 'excel') {
            $path = $this->reportBuilderService->exportToExcel($data, $report->fields, $report->name);

            return response()->download($path, basename($path), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend();
        }

        // Default: CSV
        $csv = $this->reportBuilderService->exportToCsv($data, $report->fields);
        $filename = str_replace(' ', '_', strtolower($report->name)) . '_' . now()->format('Y-m-d') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy — delete saved report                                      */
    /* ------------------------------------------------------------------ */

    public function destroy(Request $request, HrSavedReport $report)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.reports.view'), 403);

        $report->delete();

        return redirect()->route('hr.reports.saved')->with('success', 'Report deleted.');
    }
}
