<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\PayrollExportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PayrollExportController extends Controller
{
    public function __construct(
        protected PayrollExportService $payrollService,
        protected HrWebhookService $webhookService,
    ) {}

    /**
     * List payroll runs.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.view'), 403);

        $tenantId = $this->resolveTenantIdForUser($user);

        $runs = HrPayrollRun::query()
            ->with(['lockedBy:id,name', 'exportedBy:id,name'])
            ->withCount('items')
            ->where('tenant_id', $tenantId)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('period_end')
            ->paginate(20)
            ->withQueryString();

        $runs->through(fn ($run) => [
            'id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'status' => $run->status,
            'total_hours' => (float) $run->total_hours,
            'total_gross' => (float) $run->total_gross,
            'items_count' => (int) $run->items_count,
            'created_at' => optional($run->created_at)->toDateString(),
            'locked_at' => optional($run->locked_at)->toDateTimeString(),
            'exported_at' => optional($run->exported_at)->toDateTimeString(),
            'validation_errors' => $run->validation_errors ?? [],
        ]);

        return Inertia::render('hr/payroll/index', [
            'runs' => $runs,
            'filters' => [
                'status' => $request->query('status'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.payroll.export'),
                'export_data' => $user->canDo('hr.payroll.export'),
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
        $tenantId = $this->resolveTenantIdForUser($user);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $run = $this->payrollService->createRun(
                $tenantId,
                Carbon::parse($data['period_start']),
                Carbon::parse($data['period_end']),
                $user->id,
            );
            if (! empty($data['notes'])) {
                $run->update(['notes' => $data['notes']]);
            }
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
        $tenantId = $this->resolveTenantIdForUser($user);
        if ($run->tenant_id !== $tenantId) {
            abort(404);
        }

        try {
            $this->payrollService->lockRun($run, $user->id);
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['lock' => $e->getMessage()]);
        }

        $this->webhookService->publish($run->tenant_id, 'payroll.run.locked', [
            'payroll_run_id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'locked_by' => $user->id,
            'status' => 'locked',
        ]);

        return redirect()->back()->with('success', 'Payroll run locked.');
    }

    /**
     * Export a locked payroll run as CSV.
     */
    public function export(Request $request, HrPayrollRun $run)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payroll.export'), 403);
        $tenantId = $this->resolveTenantIdForUser($user);
        if ($run->tenant_id !== $tenantId) {
            abort(404);
        }

        try {
            $path = $this->payrollService->generateExport($run, $user->id);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['export' => $e->getMessage()]);
        }

        $this->webhookService->publish($run->tenant_id, 'payroll.run.exported', [
            'payroll_run_id' => $run->id,
            'period_start' => optional($run->period_start)->toDateString(),
            'period_end' => optional($run->period_end)->toDateString(),
            'exported_by' => $user->id,
            'storage_path' => $path,
        ]);

        return Storage::disk('private')->download($path, basename($path), [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function resolveTenantIdForUser($user): int
    {
        $candidateTenantId = $user->tenant_id ?? null;
        if (is_numeric($candidateTenantId)) {
            return (int) $candidateTenantId;
        }

        $profileTenantId = HrEmployeeProfile::query()
            ->where('user_id', $user->id)
            ->value('tenant_id');

        if (is_numeric($profileTenantId)) {
            return (int) $profileTenantId;
        }

        $fallbackTenantId = HrPayrollRun::query()->orderByDesc('id')->value('tenant_id')
            ?? HrEmployeeProfile::query()->orderBy('id')->value('tenant_id');

        return (int) ($fallbackTenantId ?? 1);
    }
}
