<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Services\GstReturnService;
use App\Domain\Finance\Services\NzComplianceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GstReturnController extends Controller
{
    public function __construct(
        protected GstReturnService $gstReturnService,
        protected NzComplianceService $complianceService,
    ) {}

    /**
     * List GST returns with filters and pagination.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinGstReturn::class);

        $orgId = $request->user()->organization_id;

        $query = FinGstReturn::forOrganization($orgId);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('year')) {
            $year = (int) $request->input('year');
            $query->whereYear('period_end', $year);
        }

        $gstReturns = $query->orderByDesc('period_end')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('finance/gst-returns/Index', [
            'gstReturns' => $gstReturns,
            'filters' => $request->only(['status', 'year']),
        ]);
    }

    /**
     * Show the form to prepare a new GST return.
     */
    public function prepare(Request $request)
    {
        $this->authorize('create', FinGstReturn::class);

        $currentYear = (int) now()->format('Y');

        $filingDates = [
            'monthly' => $this->complianceService->getGstFilingDates('monthly', $currentYear),
            'two_monthly' => $this->complianceService->getGstFilingDates('two_monthly', $currentYear),
            'six_monthly' => $this->complianceService->getGstFilingDates('six_monthly', $currentYear),
        ];

        return Inertia::render('finance/gst-returns/Prepare', [
            'filingDates' => $filingDates,
            'currentYear' => $currentYear,
        ]);
    }

    /**
     * Validate and prepare a GST return via the service.
     */
    public function store(Request $request)
    {
        $this->authorize('create', FinGstReturn::class);

        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'filing_frequency' => ['required', 'in:monthly,two_monthly,six_monthly'],
            'basis' => ['required', 'in:invoice,payments,hybrid'],
        ]);

        $orgId = $request->user()->organization_id;

        $gstReturn = $this->gstReturnService->prepareReturn($orgId, $validated);

        return redirect()->route('finance.gst-returns.show', $gstReturn)
            ->with('success', 'GST return prepared for period ending ' . $gstReturn->period_end->format('d M Y') . '.');
    }

    /**
     * Show a GST return with lines, summary and IRD form data.
     */
    public function show(Request $request, FinGstReturn $gstReturn)
    {
        $this->authorize('view', $gstReturn);

        $gstReturn->load([
            'lines.account:id,code,name,type',
            'lines.taxRate:id,code,name,rate',
            'lines.journalLine.journal:id,journal_number,journal_date',
            'filedBy:id,name',
            'createdBy:id,name',
        ]);

        $summary = $this->gstReturnService->getReturnSummary($gstReturn);
        $irdFormData = $this->complianceService->generateGst101AData($gstReturn);

        return Inertia::render('finance/gst-returns/Show', [
            'gstReturn' => $gstReturn,
            'summary' => $summary,
            'irdFormData' => $irdFormData,
        ]);
    }

    /**
     * Mark a GST return as filed.
     */
    public function file(Request $request, FinGstReturn $gstReturn)
    {
        $this->authorize('file', $gstReturn);

        if ($gstReturn->status !== 'draft') {
            return redirect()->back()
                ->withErrors(['status' => 'Only draft returns can be filed.']);
        }

        $this->gstReturnService->fileReturn($gstReturn, $request->user()->id);

        return redirect()->route('finance.gst-returns.show', $gstReturn)
            ->with('success', 'GST return has been marked as filed.');
    }
}
