<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Services\EmployeeImportExportService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportExportController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly EmployeeImportExportService $service,
    ) {}

    /**
     * Show import/export page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        return Inertia::render('hr/import-export/index');
    }

    /**
     * Export employees to CSV download.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        // Optional "export selected" — the People table's multi-select posts the
        // chosen user ids; absent, we export all active employees as before.
        $ids = $request->input('ids');
        $userIds = is_array($ids) && count($ids) > 0
            ? array_values(array_filter(array_map('intval', $ids)))
            : null;

        $csv = $this->service->exportToCsv($tenantId, $userIds);
        $filename = 'employees_'.date('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Download blank CSV template.
     */
    public function template(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $csv = $this->service->generateTemplate();

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'employee_import_template.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Import employees from uploaded CSV.
     */
    public function import(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $csvContent = file_get_contents($request->file('file')->getRealPath());
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $result = $this->service->importFromCsv($csvContent, $tenantId, $user->id);

        return back()->with('importResult', $result);
    }
}
