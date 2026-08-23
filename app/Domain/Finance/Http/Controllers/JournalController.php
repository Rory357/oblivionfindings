<?php

namespace App\Domain\Finance\Http\Controllers;

use App\Domain\Finance\Http\Requests\StoreJournalRequest;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinFixedAssetDepreciation;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinRecurringJournalOccurrence;
use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Finance\Services\FixedAssetService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JournalController extends Controller
{
    public function __construct(
        protected JournalPostingService $postingService,
        protected FixedAssetService $fixedAssetService,
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

        // Reference data for the in-list New Journal wizard — only loaded for
        // users who can actually create a journal (the modal trigger is gated too).
        $canManage = $request->user()->can('create', FinJournal::class);

        $recurringOccurrenceHistory = FinRecurringJournalOccurrence::query()
            ->whereHas(
                'recurringJournal',
                fn ($query) => $query->where('organization_id', $orgId),
            )
            ->with([
                'recurringJournal:id,organization_id,name',
                'journal:id,journal_number',
                'attempts' => fn ($query) => $query
                    ->orderByDesc('started_at')
                    ->orderByDesc('id'),
            ])
            ->where(function ($query): void {
                $query->where('status', 'failed')
                    ->orWhere('attempt_count', '>', 1)
                    ->orWhereNotNull('recovered_at');
            })
            ->orderByDesc('last_attempted_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (FinRecurringJournalOccurrence $occurrence) => [
                'id' => $occurrence->id,
                'schedule_name' => $occurrence->recurringJournal->name,
                'scheduled_for' => $occurrence->scheduled_for->toDateString(),
                'status' => $occurrence->status,
                'attempt_count' => $occurrence->attempt_count,
                'last_attempted_at' => $occurrence->last_attempted_at?->toIso8601String(),
                'posted_at' => $occurrence->posted_at?->toIso8601String(),
                'failed_at' => $occurrence->failed_at?->toIso8601String(),
                'recovered_at' => $occurrence->recovered_at?->toIso8601String(),
                'last_error_code' => $occurrence->last_error_code,
                'journal' => $occurrence->journal ? [
                    'id' => $occurrence->journal->id,
                    'journal_number' => $occurrence->journal->journal_number,
                ] : null,
                'attempts' => $occurrence->attempts
                    ->take(5)
                    ->map(fn ($attempt) => [
                        'outcome' => $attempt->outcome,
                        'error_code' => $attempt->error_code,
                        'started_at' => $attempt->started_at?->toIso8601String(),
                        'finished_at' => $attempt->finished_at?->toIso8601String(),
                    ])
                    ->values(),
            ]);

        return Inertia::render('finance/journals/Index', [
            'journals' => $journals,
            'recurringOccurrenceHistory' => $recurringOccurrenceHistory,
            'filters' => $request->only(['status', 'type', 'date_from', 'date_to', 'search']),
            'canManage' => $canManage,
            'accounts' => $canManage
                ? FinAccount::forOrganization($orgId)->active()->orderBy('code')->get(['id', 'code', 'name', 'type'])
                : [],
            'costCentres' => $canManage
                ? FinCostCentre::forOrganization($orgId)->active()->orderBy('code')->get(['id', 'code', 'name'])
                : [],
            'fundingStreams' => $canManage
                ? FinFundingStream::forOrganization($orgId)->active()->orderBy('code')->get(['id', 'code', 'name'])
                : [],
        ]);
    }

    /**
     * Stream the (filtered) journal list as a sanitised CSV. Honours the same
     * status/type/date/search filters as the index. Debit/Credit totals are
     * summed from each journal's lines (withSum), not the header total_amount.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', FinJournal::class);

        $orgId = $request->user()->organization_id;

        $query = FinJournal::forOrganization($orgId)
            ->withSum('lines as debit_total', 'debit')
            ->withSum('lines as credit_total', 'credit');

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
            $query->where(fn ($q) => $q->where('journal_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        $rows = $query->orderByDesc('journal_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FinJournal $j) => [
                $j->journal_number,
                optional($j->journal_date)->format('Y-m-d'),
                $j->type,
                $j->description,
                number_format((float) $j->debit_total, 2, '.', ''),
                number_format((float) $j->credit_total, 2, '.', ''),
                $j->status,
            ]);

        return $this->streamSanitizedCsv(
            'journals-'.now()->format('Y-m-d').'.csv',
            ['Journal #', 'Date', 'Type', 'Description', 'Debit Total', 'Credit Total', 'Status'],
            $rows,
        );
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
            if ($journal->source_type === FinFixedAssetDepreciation::class && $journal->source_id !== null) {
                $depreciation = FinFixedAssetDepreciation::query()->findOrFail($journal->source_id);
                if ((int) $depreciation->journal_id !== (int) $journal->id) {
                    throw new \InvalidArgumentException('The depreciation journal has conflicting execution lineage.');
                }
                $reversingJournal = $this->fixedAssetService->reverseDepreciation(
                    $depreciation,
                    $request->input('reason'),
                );
            } else {
                $reversingJournal = $this->postingService->reverse($journal, $request->input('reason'));
            }
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['posting' => $e->getMessage()]);
        }

        return redirect()->route('finance.journals.show', $reversingJournal)
            ->with('success', "Reversing journal {$reversingJournal->journal_number} created and posted.");
    }
}
