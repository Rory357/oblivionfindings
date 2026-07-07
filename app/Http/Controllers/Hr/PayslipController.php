<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\PayslipService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsMyHrShell;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PayslipController extends Controller
{
    use BuildsMyHrShell, ResolvesHrTenant;

    public function __construct(
        protected PayslipService $payslipService,
    ) {}

    /**
     * List payslips (HR admin view) with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payslips.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $payslips = HrPayslip::query()
            ->forTenant($tenantId)
            ->with(['user:id,name', 'employeeProfile:id,employee_number,position_title'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($request->query('period_start'), fn ($q, $start) => $q->where('pay_period_start', '>=', $start))
            ->when($request->query('period_end'), fn ($q, $end) => $q->where('pay_period_end', '<=', $end))
            ->orderByDesc('pay_period_end')
            ->paginate(20)
            ->withQueryString();

        $employees = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->user?->name ?? 'Unknown']);

        // Server-side status counts across the whole tenant so the hero tiles
        // are true (a page-scoped client tally only sees the current 20 rows).
        // Payslips flow draft → paid; 'approved' from the original schema was
        // never wired, so it isn't surfaced.
        $statusCounts = HrPayslip::query()
            ->forTenant($tenantId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('hr/payroll/payslips', [
            'payslips' => $payslips,
            'employees' => $employees,
            'statusCounts' => [
                'total' => (int) $statusCounts->sum(),
                'draft' => (int) ($statusCounts['draft'] ?? 0),
                'paid' => (int) ($statusCounts['paid'] ?? 0),
            ],
            'filters' => [
                'status' => $request->query('status'),
                'user_id' => $request->query('user_id'),
                'period_start' => $request->query('period_start'),
                'period_end' => $request->query('period_end'),
            ],
            'can' => [
                'generate' => $user->canDo('hr.payslips.generate'),
            ],
        ]);
    }

    /**
     * Generate payslips for a pay period or payroll run.
     */
    public function generate(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payslips.generate'), 403);

        // users has no tenant_id column, so resolve it — the old
        // $user->tenant_id made the "all employees" branch filter on NULL.
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'payroll_run_id' => ['nullable', 'exists:hr_payroll_runs,id'],
            'employee_profile_id' => ['nullable', 'exists:hr_employee_profiles,id'],
        ]);

        $generated = collect();

        try {
            if (! empty($data['payroll_run_id'])) {
                // Bulk generate from a payroll run
                $run = HrPayrollRun::where('tenant_id', $tenantId)->findOrFail($data['payroll_run_id']);
                $generated = $this->payslipService->generateBulkPayslips($run);
                $count = $generated->count();
            } elseif (! empty($data['employee_profile_id'])) {
                // Single employee
                $profile = HrEmployeeProfile::where('tenant_id', $tenantId)->findOrFail($data['employee_profile_id']);
                $generated->push($this->payslipService->generatePayslip(
                    $profile,
                    $data['period_start'],
                    $data['period_end'],
                ));
                $count = 1;
            } else {
                // All active employees
                $profiles = HrEmployeeProfile::query()
                    ->where('tenant_id', $tenantId)
                    ->active()
                    ->get();

                foreach ($profiles as $profile) {
                    $generated->push($this->payslipService->generatePayslip(
                        $profile,
                        $data['period_start'],
                        $data['period_end'],
                    ));
                }
                $count = $generated->count();
            }
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['generate' => $e->getMessage()]);
        }

        // Post-commit: let each employee know their payslip is ready to view.
        $this->payslipService->notifyEmployeesPayslipAvailable($generated);

        return redirect()->back()->with('success', "{$count} payslip(s) generated successfully.");
    }

    /**
     * View a single payslip.
     */
    public function show(Request $request, HrPayslip $payslip)
    {
        $user = $request->user();

        // HR admins, or the employee viewing their own payslip (self-service).
        $canView = ($user && $user->canDo('hr.payslips.view'))
            || ($user && $user->id === $payslip->user_id);
        abort_unless($canView, 403);

        $payslip->load([
            'user:id,name,email',
            'employeeProfile:id,employee_number,position_title,tax_code,kiwisaver_rate,employment_type,pay_frequency',
            'payrollRun:id,period_start,period_end,status',
        ]);

        // Calculate YTD totals
        $ytdStart = Carbon::parse($payslip->pay_period_end)->startOfYear()->format('Y-m-d');
        $ytd = HrPayslip::where('user_id', $payslip->user_id)
            ->where('tenant_id', $payslip->tenant_id)
            ->where('pay_period_start', '>=', $ytdStart)
            ->where('pay_period_end', '<=', $payslip->pay_period_end)
            ->selectRaw('
                SUM(gross_pay) as gross_pay,
                SUM(paye) as paye,
                SUM(acc_levy) as acc_levy,
                SUM(kiwisaver_employee) as kiwisaver_employee,
                SUM(kiwisaver_employer) as kiwisaver_employer,
                SUM(student_loan) as student_loan,
                SUM(holiday_pay) as holiday_pay,
                SUM(total_deductions) as total_deductions,
                SUM(net_pay) as net_pay
            ')
            ->first();

        return Inertia::render('hr/payroll/payslip-detail', [
            'payslip' => $payslip,
            'ytd' => $ytd,
        ]);
    }

    /**
     * Download a payslip as a file.
     */
    public function download(Request $request, HrPayslip $payslip)
    {
        $user = $request->user();

        // Allow HR admins or the employee themselves
        $canView = ($user && $user->canDo('hr.payslips.view'))
            || ($user && $user->id === $payslip->user_id);
        abort_unless($canView, 403);

        // Generate the PDF if not yet created, or upgrade a stale pre-PDF (.html) artefact.
        if (! $payslip->pdf_path || ! str_ends_with($payslip->pdf_path, '.pdf')) {
            $this->payslipService->generatePayslipPdf($payslip);
            $payslip->refresh();
        }

        if (! $payslip->pdf_path || ! Storage::disk('private')->exists($payslip->pdf_path)) {
            abort(404, 'Payslip document not found.');
        }

        return Storage::disk('private')->download(
            $payslip->pdf_path,
            "payslip_{$payslip->pay_period_start->format('Y-m-d')}_{$payslip->pay_period_end->format('Y-m-d')}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Employee self-service payslip list.
     */
    public function myPayslips(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $payslips = HrPayslip::query()
            ->where('user_id', $user->id)
            ->orderByDesc('pay_period_end')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/my/payslips', [
            'myHr' => $this->myHrShellProps($user, $this->resolveHrTenantIdForUser($user)),
            'payslips' => $payslips,
        ]);
    }
}
