<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\FleetResidentTransport;
use App\Models\PayrollExport;
use App\Models\Timesheet;
use App\Services\Operations\PayrollExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PayrollExportController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessPayrollExports($auth), 403);

        $filters = $request->validate([
            'status' => ['nullable', 'string'],
        ]);

        $exports = PayrollExport::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['exporter:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $statsBase = PayrollExport::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->whereBetween('period_end', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $timesheetColumns = Schema::hasTable('timesheets') ? Schema::getColumnListing('timesheets') : [];
        $billingColumns = Schema::hasTable('billing_entries') ? Schema::getColumnListing('billing_entries') : [];
        $transportColumns = Schema::hasTable('fleet_resident_transports') ? Schema::getColumnListing('fleet_resident_transports') : [];

        return inertia('operations/payroll-export/Index', [
            'exports' => $exports,
            'filters' => $filters,
            'stats' => [
                'total' => PayrollExport::query()
                    ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
                    ->count(),
                'hours_this_period' => (float) $statsBase->sum('total_hours'),
                'amount_this_period' => (float) $statsBase->sum('total_amount'),
            ],
            'readiness' => [
                'approved_timesheets_missing_snapshots' => in_array('status', $timesheetColumns, true)
                    ? Timesheet::query()
                        ->where('status', 'approved')
                        ->where(function ($query) use ($timesheetColumns) {
                            foreach (['client_name_snapshot', 'staff_name_snapshot', 'shift_type_snapshot'] as $column) {
                                if (in_array($column, $timesheetColumns, true)) {
                                    $query->orWhereNull($column);
                                }
                            }
                        })
                        ->count()
                    : 0,
                'approved_timesheets_with_partial_payroll_segments' => in_array('status', $timesheetColumns, true)
                    && in_array('exported_to_payroll_at', $timesheetColumns, true)
                    && in_array('payroll_segments_exported', $timesheetColumns, true)
                    ? Timesheet::query()
                        ->where('status', 'approved')
                        ->whereNull('exported_to_payroll_at')
                        ->whereNotNull('payroll_segments_exported')
                        ->whereRaw('JSON_LENGTH(payroll_segments_exported) > 0')
                        ->count()
                    : 0,
                'snapshot_backfill_required' => [
                    'timesheets' => Schema::hasTable('timesheets')
                        ? Timesheet::query()
                            ->where(function ($query) use ($timesheetColumns) {
                                foreach (['shift_site_name_snapshot', 'client_name_snapshot', 'staff_name_snapshot'] as $column) {
                                    if (in_array($column, $timesheetColumns, true)) {
                                        $query->orWhereNull($column);
                                    }
                                }
                            })
                            ->count()
                        : 0,
                    'billing_entries' => Schema::hasTable('billing_entries')
                        ? BillingEntry::query()
                            ->where(function ($query) use ($billingColumns) {
                                foreach (['site_name_snapshot', 'client_name_snapshot', 'staff_name_snapshot'] as $column) {
                                    if (in_array($column, $billingColumns, true)) {
                                        $query->orWhereNull($column);
                                    }
                                }
                            })
                            ->count()
                        : 0,
                    'fleet_transports' => Schema::hasTable('fleet_resident_transports')
                        ? FleetResidentTransport::query()
                            ->where(function ($query) use ($transportColumns) {
                                foreach (['site_name_snapshot', 'driver_name_snapshot'] as $column) {
                                    if (in_array($column, $transportColumns, true)) {
                                        $query->orWhereNull($column);
                                    }
                                }
                            })
                            ->count()
                        : 0,
                ],
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessPayrollExports($auth), 403);

        return inertia('operations/payroll-export/Create');
    }

    public function generate(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessPayrollExports($auth), 403);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'name' => ['nullable', 'string', 'max:255'],
            'format' => ['nullable', 'in:csv,json'],
        ]);

        app(PayrollExportService::class)->generate(
            (int) $auth->organization_id,
            $data['start_date'],
            $data['end_date'],
            $data['format'] ?? 'csv',
            $auth->id,
        );

        return redirect()->route('operations.payroll_export.index')->with('success', 'Payroll export generated.');
    }

    public function download(Request $request, $export)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessPayrollExports($auth), 403);

        $export = PayrollExport::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($export);

        if ($export->file_path && file_exists(storage_path('app/' . $export->file_path))) {
            return response()->download(storage_path('app/' . $export->file_path));
        }

        return redirect()->back()->with('error', 'Export file not found.');
    }

    public function confirm(Request $request, $export)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessPayrollExports($auth), 403);

        $export = PayrollExport::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($export);

        app(PayrollExportService::class)->confirmExport($export);

        return redirect()->back()->with('success', 'Payroll export confirmed.');
    }

    private function canAccessPayrollExports($auth): bool
    {
        return $auth->canDo('payroll_exports.viewAny')
            || $auth->canDo('payroll_exports.create')
            || $auth->canDo('payroll_exports.view')
            || $auth->canDo('payroll_exports.confirm')
            || $auth->canDo('payroll.export');
    }
}
