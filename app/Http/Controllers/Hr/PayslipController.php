<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Domain\Hr\Services\PayslipService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\BuildsMyHrShell;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PayslipController extends Controller
{
    use BuildsMyHrShell;

    public function __construct(
        protected PayslipService $payslipService,
        private readonly HrPerformanceAccessService $performanceAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /**
     * List payslips (HR admin view) with filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.payslips.view'), 403);

        $payslips = $this->performanceAccess
            ->applyPayslipScope(HrPayslip::query(), $user)
            ->with(['user:id,name', 'employeeProfile:id,employee_number,position_title'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->when($request->query('period_start'), fn ($q, $start) => $q->where('pay_period_start', '>=', $start))
            ->when($request->query('period_end'), fn ($q, $end) => $q->where('pay_period_end', '<=', $end))
            ->orderByDesc('pay_period_end')
            ->paginate(20)
            ->withQueryString();

        $employees = $this->performanceAccess
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
            ->with('user:id,name')
            ->get()
            ->map(fn ($p) => ['id' => $p->user_id, 'name' => $p->user?->name ?? 'Unknown']);

        // Server-side status counts across the complete visible worklist so the hero tiles
        // are true (a page-scoped client tally only sees the current 20 rows).
        // Payslips flow draft → paid; 'approved' from the original schema was
        // never wired, so it isn't surfaced.
        $statusCounts = $this->performanceAccess
            ->applyPayslipScope(HrPayslip::query(), $user)
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

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'payroll_run_id' => ['nullable', 'integer'],
            'employee_profile_id' => ['nullable', 'integer'],
        ]);

        $generated = collect();
        $run = null;
        $profile = null;
        $profiles = collect();

        if (! empty($data['payroll_run_id'])) {
            $run = HrPayrollRun::query()->findOrFail($data['payroll_run_id']);
            $runUserIds = $run->items()
                ->pluck('user_id')
                ->filter()
                ->map(fn ($userId): int => (int) $userId)
                ->unique()
                ->values();
            $visibleRunUserIds = $this->performanceAccess
                ->currentUserIds($user)
                ->whereIn('users.id', $runUserIds)
                ->pluck('users.id')
                ->map(fn ($userId): int => (int) $userId);
            abort_unless($runUserIds->diff($visibleRunUserIds)->isEmpty(), 404);
        } elseif (! empty($data['employee_profile_id'])) {
            $profile = $this->performanceAccess
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                ->findOrFail($data['employee_profile_id']);
        } else {
            $profiles = $this->performanceAccess
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
                ->get();
        }

        try {
            if ($run instanceof HrPayrollRun) {
                // Bulk generate from a payroll run
                $generated = $this->payslipService->generateBulkPayslips($run);
                $count = $generated->count();
            } elseif ($profile instanceof HrEmployeeProfile) {
                // Single employee
                $generated->push($this->payslipService->generatePayslip(
                    $profile,
                    $data['period_start'],
                    $data['period_end'],
                ));
                $count = 1;
            } else {
                // All current employees visible to the generator.
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
        abort_unless($user, 403);
        $payslip = $this->payslipForViewer($user, $payslip);

        $payslip->load([
            'user:id,name,email',
            'employeeProfile:id,employee_number,position_title,tax_code,kiwisaver_rate,employment_type,pay_frequency',
            'payrollRun:id,period_start,period_end,status',
        ]);

        // Calculate YTD totals
        $ytdStart = Carbon::parse($payslip->pay_period_end)->startOfYear()->format('Y-m-d');
        $ytd = HrPayslip::where('user_id', $payslip->user_id)
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
        abort_unless($user, 403);
        $payslip = $this->payslipForViewer($user, $payslip);

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
        abort_unless($user && $this->currentStaff->isCurrent($user), 403);

        $payslips = HrPayslip::query()
            ->where('user_id', $user->id)
            ->orderByDesc('pay_period_end')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('hr/my/payslips', [
            'myHr' => $this->myHrShellProps($user),
            'payslips' => $payslips,
        ]);
    }

    private function payslipForViewer(User $viewer, HrPayslip $payslip): HrPayslip
    {
        if ((int) $payslip->user_id === (int) $viewer->id) {
            abort_unless($this->currentStaff->isCurrent($viewer), 404);

            return HrPayslip::query()
                ->where('user_id', $viewer->id)
                ->findOrFail($payslip->getKey());
        }

        abort_unless($viewer->canDo('hr.payslips.view'), 403);

        return $this->performanceAccess->payslip($viewer, $payslip);
    }
}
