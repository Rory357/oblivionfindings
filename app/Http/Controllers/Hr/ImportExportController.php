<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\EmployeeImportExportService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportExportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportExportService $service,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Show import/export page.
     */
    public function index(Request $request)
    {
        $user = $this->manager($request);

        return Inertia::render('hr/import-export/index', [
            'stats' => [
                'exportable' => $this->siteAccess
                    ->applyCurrentStaffProfileScope(HrEmployeeProfile::query(), $user)
                    ->count(),
                'profiles' => $this->siteAccess
                    ->applyHistoricalStaffProfileScope(HrEmployeeProfile::query(), $user)
                    ->count(),
            ],
            'sites' => $this->siteAccess
                ->applySiteScope(
                    Site::query()->active()->notArchived()->whereNull('archived_at'),
                    $user,
                )
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Export employees to CSV download.
     */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->manager($request);

        // Optional "export selected" — the People table's multi-select posts the
        // chosen user ids; absent, we export all active employees as before.
        $ids = $request->input('ids');
        $userIds = is_array($ids) && count($ids) > 0
            ? array_values(array_filter(array_map('intval', $ids)))
            : null;

        $csv = $this->service->exportToCsv($user, $userIds);
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
        $this->manager($request);

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
        $user = $this->manager($request);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $csvContent = file_get_contents($request->file('file')->getRealPath());
        abort_unless(is_string($csvContent), 422, 'The uploaded CSV could not be read.');

        $result = $this->service->importFromCsv($csvContent, $user);

        return back()->with('importResult', $result);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        return $this->siteAccess
            ->applyStaffScope(User::query(), $user)
            ->findOrFail($user->getKey());
    }
}
