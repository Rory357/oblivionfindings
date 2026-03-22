<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Services\PayrollExportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PayrollExportController extends Controller
{
    public function __construct(
        protected PayrollExportService $payrollService,
    ) {}

    /**
     * List payroll runs.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.view'), 403);

        $tenantId = null;

        $runs = HrPayrollRun::query()
            ->with(['lockedBy:id,name', 'exportedBy:id,name'])
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('period_end')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/payroll/index', [
            'runs' => $runs,
            'filters' => [
                'status' => $request->query('status'),
            ],
            'can' => [
                'export' => $user->canDo('hr.payroll.export'),
            ],
        ]);
    }

    /**
     * Create a new payroll run for a pay period.
     */
    public function createRun(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->payrollService->createRun(
                $user->tenant_id,
                Carbon::parse($data['period_start']),
                Carbon::parse($data['period_end']),
                $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['period' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Payroll run created.');
    }

    /**
     * Lock a payroll run to prevent further edits.
     */
    public function lockRun(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);

        try {
            $this->payrollService->lockRun($run, $user->id);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['lock' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Payroll run locked.');
    }

    /**
     * Export a locked payroll run as CSV.
     */
    public function export(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);

        try {
            $path = $this->payrollService->generateExport($run, $user->id);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['export' => $e->getMessage()]);
        }

        return Storage::disk('private')->download($path, basename($path), [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Export payroll run in a specific format (Xero, MYOB, iPayroll, Bank).
     */
    public function exportFormatted(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);

        $format = $request->input('format', 'xero');
        $service = app(\App\Domain\Hr\Services\PayrollExportFormatService::class);

        $content = match ($format) {
            'xero' => $service->exportToXero($run),
            'myob' => $service->exportToMyob($run),
            'ipayroll' => $service->exportToIPayroll($run),
            'bank' => $service->exportToBankFile($run),
            default => $service->exportToXero($run),
        };

        $filename = "payroll-run-{$run->id}-{$format}.csv";

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
