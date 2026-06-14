<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\PayslipService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PayslipController extends Controller
{
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

        $payslips = HrPayslip::query()
            ->with(['user:id,name', 'employeeProfile:id,employee_number,position_title'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($request->query('period_start'), fn ($q, $start) => $q->where('pay_period_start', '>=', $start))
            ->when($request->query('period_end'), fn ($q, $end) => $q->where('pay_period_end', '<=', $end))
            ->orderByDesc('pay_period_end')
            ->paginate(20)
            ->withQueryString();

        $employees = HrEmployeeProfile::query()
            ->active()
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->user?->name ?? 'Unknown']);

        return Inertia::render('hr/payroll/payslips', [
            'payslips' => $payslips,
            'employees' => $employees,
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

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'payroll_run_id' => ['nullable', 'exists:hr_payroll_runs,id'],
            'employee_profile_id' => ['nullable', 'exists:hr_employee_profiles,id'],
        ]);

        try {
            if (! empty($data['payroll_run_id'])) {
                // Bulk generate from a payroll run
                $run = HrPayrollRun::findOrFail($data['payroll_run_id']);
                $payslips = $this->payslipService->generateBulkPayslips($run);
                $count = $payslips->count();
            } elseif (! empty($data['employee_profile_id'])) {
                // Single employee
                $profile = HrEmployeeProfile::findOrFail($data['employee_profile_id']);
                $this->payslipService->generatePayslip(
                    $profile,
                    $data['period_start'],
                    $data['period_end'],
                );
                $count = 1;
            } else {
                // All active employees
                $profiles = HrEmployeeProfile::query()
                    ->where('tenant_id', $user->tenant_id)
                    ->active()
                    ->get();

                $count = 0;
                foreach ($profiles as $profile) {
                    $this->payslipService->generatePayslip(
                        $profile,
                        $data['period_start'],
                        $data['period_end'],
                    );
                    $count++;
                }
            }
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['generate' => $e->getMessage()]);
        }

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

        // Generate PDF if not yet created
        if (! $payslip->pdf_path) {
            $this->payslipService->generatePayslipPdf($payslip);
            $payslip->refresh();
        }

        if (! $payslip->pdf_path || ! Storage::disk('private')->exists($payslip->pdf_path)) {
            abort(404, 'Payslip document not found.');
        }

        return Storage::disk('private')->download(
            $payslip->pdf_path,
            "payslip_{$payslip->pay_period_start->format('Y-m-d')}_{$payslip->pay_period_end->format('Y-m-d')}.html",
            ['Content-Type' => 'text/html'],
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
            'payslips' => $payslips,
        ]);
    }
}
