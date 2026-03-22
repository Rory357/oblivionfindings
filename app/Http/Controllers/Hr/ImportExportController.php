<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Services\EmployeeImportExportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportExportController extends Controller
{
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

        $tenantId = null;
        $csv = $this->service->exportToCsv($tenantId);
        $filename = 'employees_' . date('Y-m-d_His') . '.csv';

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
        $tenantId = null;

        $result = $this->service->importFromCsv($csvContent, $tenantId, $user->id);

        return back()->with('importResult', $result);
    }
}
