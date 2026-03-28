<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Services\JournalPostingService;
use App\Domain\Finance\Http\Requests\StoreJournalRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JournalController extends Controller
{
    public function __construct(
        protected JournalPostingService $postingService,
    ) {}

    /**
     * List journals with pagination and filters.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', FinJournal::class);

        $orgId = $request->user()->organization_id;

        $query = FinJournal::forOrganization($orgId)
            ->withCount('lines');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->ofType($request->input('type'));
        }

        if ($request->filled('date_from')) {
            $query->where('journal_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('journal_date', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('journal_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $journals = $query->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('finance/journals/Index', [
            'journals' => $journals,
            'filters' => $request->only(['status', 'type', 'date_from', 'date_to', 'search']),
        ]);
    }

    /**
     * Show the create journal form.
     */
    public function create(Request $request)
    {
        $this->authorize('create', FinJournal::class);

        $orgId = $request->user()->organization_id;

        return Inertia::render('finance/journals/Create', [
            'accounts' => FinAccount::forOrganization($orgId)
                ->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'costCentres' => FinCostCentre::forOrganization($orgId)
                ->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'fundingStreams' => FinFundingStream::forOrganization($orgId)
                ->active()
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'taxRates' => FinTaxRate::forOrganization($orgId)
                ->active()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'rate']),
        ]);
    }

    /**
     * Store a new draft journal.
     */
    public function store(StoreJournalRequest $request)
    {
        $validated = $request->validated();

        $orgId = $request->user()->organization_id;

        try {
            if (! empty($validated['post_immediately'])) {
                $journal = $this->postingService->createAndPost($orgId, $validated);
            } else {
                $journal = $this->postingService->createDraftJournal($orgId, $validated);
            }
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('finance.journals.show', $journal)
            ->with('success', $journal->status === 'posted'
                ? "Journal {$journal->journal_number} created and posted."
                : "Journal {$journal->journal_number} saved as draft."
            );
    }

    /**
     * Show a single journal with its lines and relations.
     */
    public function show(Request $request, FinJournal $journal)
    {
        $this->authorize('view', $journal);

        $journal->load([
            'lines.account:id,code,name',
            'lines.costCentre:id,code,name',
            'lines.fundingStream:id,code,name',
            'lines.taxRate:id,code,name,rate',
            'fiscalPeriod:id,name,start_date,end_date',
            'postedBy:id,name',
            'createdBy:id,name',
            'reversedByJournal:id,journal_number',
        ]);

        return Inertia::render('finance/journals/Show', [
            'journal' => $journal,
        ]);
    }

    /**
     * Post a draft journal.
     */
    public function post(Request $request, FinJournal $journal)
    {
        $this->authorize('post', $journal);

        try {
            $this->postingService->post($journal);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('finance.journals.show', $journal)
            ->with('success', "Journal {$journal->journal_number} has been posted.");
    }

    /**
     * Reverse a posted journal.
     */
    public function reverse(Request $request, FinJournal $journal)
    {
        $this->authorize('reverse', $journal);

        $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $reversingJournal = $this->postingService->reverse($journal, $request->input('reason'));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('finance.journals.show', $reversingJournal)
            ->with('success', "Reversing journal {$reversingJournal->journal_number} created and posted.");
    }
}
