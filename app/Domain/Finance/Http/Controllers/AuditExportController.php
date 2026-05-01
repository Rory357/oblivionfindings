<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Jobs\GenerateAuditExportJob;
use App\Domain\Finance\Models\FinAuditExport;
use App\Domain\Finance\Services\AuditExportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditExportController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $exports = FinAuditExport::forOrganization($orgId)
            ->with('createdBy:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('finance/audit-exports/Index', [
            'exports' => $exports,
        ]);
    }

    public function create()
    {
        return Inertia::render('finance/audit-exports/Create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'export_name' => 'required|string|max:255',
            'period_from' => 'required|date',
            'period_to' => 'required|date|after_or_equal:period_from',
            'include_journals' => 'boolean',
            'include_bank_reconciliations' => 'boolean',
            'include_ap' => 'boolean',
            'include_ar' => 'boolean',
            'include_gst' => 'boolean',
            'include_fixed_assets' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $export = FinAuditExport::create([
            'organization_id' => $request->user()->organization_id,
            'export_name' => $validated['export_name'],
            'period_from' => $validated['period_from'],
            'period_to' => $validated['period_to'],
            'include_journals' => $validated['include_journals'] ?? true,
            'include_bank_reconciliations' => $validated['include_bank_reconciliations'] ?? true,
            'include_ap' => $validated['include_ap'] ?? true,
            'include_ar' => $validated['include_ar'] ?? true,
            'include_gst' => $validated['include_gst'] ?? true,
            'include_fixed_assets' => $validated['include_fixed_assets'] ?? true,
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        GenerateAuditExportJob::dispatch($export->id);

        return redirect()->route('finance.audit-exports.index')
            ->with('success', 'Audit export is being generated. You will be able to download it shortly.');
    }

    public function download(Request $request, FinAuditExport $export, AuditExportService $exports)
    {
        if ($export->status !== 'completed' || ! $export->file_path) {
            return back()->withErrors(['export' => 'Export is not ready for download.']);
        }

        $contents = $exports->contentsForDownload($export);

        if ($contents === null) {
            return back()->withErrors(['export' => 'Export file not found. Please regenerate.']);
        }

        $export->update(['downloaded_at' => now()]);

        $filename = str_replace(' ', '_', $export->export_name).'.zip';

        return response()->streamDownload(
            fn () => print $contents,
            $filename,
            ['Content-Type' => 'application/zip']
        );
    }

    public function destroy(Request $request, FinAuditExport $export, AuditExportService $exports)
    {
        $exports->deleteFile($export);

        $export->delete();

        return redirect()->route('finance.audit-exports.index')
            ->with('success', 'Audit export deleted.');
    }
}
