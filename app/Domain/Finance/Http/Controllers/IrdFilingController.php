<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinIrdFiling;
use App\Domain\Finance\Services\IrdFilingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IrdFilingController extends Controller
{
    public function __construct(
        protected IrdFilingService $irdFilingService,
    ) {}

    /**
     * List all IRD filings with optional filters.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = FinIrdFiling::forOrganization($orgId);

        if ($request->filled('filing_type')) {
            $query->ofType($request->input('filing_type'));
        }

        if ($request->filled('status')) {
            $query->ofStatus($request->input('status'));
        }

        $filings = $query
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        // Get GST returns that are filed but don't have an IRD filing yet
        $availableGstReturns = FinGstReturn::forOrganization($orgId)
            ->whereIn('status', ['draft', 'filed'])
            ->whereDoesntHave('irdFilings')
            ->orderByDesc('period_end')
            ->get(['id', 'period_start', 'period_end', 'gst_payable', 'status', 'ird_period']);

        return Inertia::render('finance/IrdFilings/Index', [
            'filings' => $filings,
            'availableGstReturns' => $availableGstReturns,
            'filters' => $request->only(['filing_type', 'status']),
        ]);
    }

    /**
     * Create a filing from an existing GST return.
     */
    public function createFromGst(Request $request, FinGstReturn $gstReturn)
    {
        $validated = $request->validate([
            'ird_number' => ['required', 'string', 'min:8', 'max:11'],
        ]);

        $orgId = $request->user()->organization_id;

        $filing = $this->irdFilingService->createGstFiling(
            $orgId,
            $gstReturn,
            $validated['ird_number'],
        );

        return redirect()->route('finance.ird-filings.show', $filing)
            ->with('success', 'IRD filing created from GST return.');
    }

    /**
     * Show a filing's details.
     */
    public function show(Request $request, FinIrdFiling $filing)
    {
        $filing->load([
            'gstReturn',
            'createdBy:id,name',
        ]);

        return Inertia::render('finance/IrdFilings/Show', [
            'filing' => $filing,
        ]);
    }

    /**
     * Validate a filing before submission.
     */
    public function validateFiling(Request $request, FinIrdFiling $filing)
    {
        $errors = $this->irdFilingService->validateFiling($filing);

        if (! empty($errors)) {
            return redirect()->back()
                ->withErrors(['validation' => $errors]);
        }

        return redirect()->route('finance.ird-filings.show', $filing)
            ->with('success', 'Filing validated successfully. Ready for submission.');
    }

    /**
     * Submit a filing to IRD.
     */
    public function submit(Request $request, FinIrdFiling $filing)
    {
        try {
            $this->irdFilingService->submitFiling($filing);

            if ($filing->status === 'error') {
                return redirect()->route('finance.ird-filings.show', $filing)
                    ->withErrors(['submission' => $filing->error_message]);
            }

            $simulated = (bool) ($filing->ird_response['simulated'] ?? false);
            $message = $simulated
                ? "SIMULATED submission recorded ({$filing->ird_reference}) — NOT transmitted to IRD. File via myIR for a real submission."
                : 'Filing submitted to IRD. Reference: '.$filing->ird_reference;

            return redirect()->route('finance.ird-filings.show', $filing)
                ->with($simulated ? 'warning' : 'success', $message);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['status' => $e->getMessage()]);
        }
    }
}
