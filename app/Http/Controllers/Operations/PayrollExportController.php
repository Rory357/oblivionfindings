<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\PayrollExport;
use App\Models\Timesheet;
use Illuminate\Http\Request;

class PayrollExportController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('payroll_exports.viewAny'), 403);

        $exports = PayrollExport::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['createdBy:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/payroll-export/Index', [
            'exports' => $exports,
        ]);
    }

    public function generate(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('payroll_exports.create'), 403);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $timesheets = Timesheet::query()
            ->where('organization_id', $auth->organization_id)
            ->where('status', 'approved')
            ->whereBetween('date', [$data['start_date'], $data['end_date']])
            ->get();

        $totalHours = $timesheets->sum('total_hours');
        $totalAmount = $timesheets->sum('total_amount');

        $export = PayrollExport::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'] ?? 'Payroll Export ' . $data['start_date'] . ' to ' . $data['end_date'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_hours' => $totalHours,
            'total_amount' => $totalAmount,
            'timesheet_count' => $timesheets->count(),
            'status' => 'generated',
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Payroll export generated.');
    }

    public function download(Request $request, $export)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('payroll_exports.view'), 403);

        $export = PayrollExport::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($export);

        if ($export->file_path && file_exists(storage_path('app/' . $export->file_path))) {
            return response()->download(storage_path('app/' . $export->file_path));
        }

        return redirect()->back()->with('error', 'Export file not found.');
    }

    public function confirm(Request $request, $export)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('payroll_exports.confirm'), 403);

        $export = PayrollExport::query()
            ->where('organization_id', $auth->organization_id)
            ->findOrFail($export);

        $export->update([
            'status' => 'confirmed',
            'confirmed_by' => $auth->id,
            'confirmed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Payroll export confirmed.');
    }
}
