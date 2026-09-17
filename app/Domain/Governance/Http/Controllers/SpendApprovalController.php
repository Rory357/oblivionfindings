<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SpendApprovalController extends Controller
{
    public function __construct(private readonly SpendApprovalCommandService $commands) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SpendApproval::class);

        $scope = $this->commands->accessibleApprovalQuery($request->user());
        $query = (clone $scope)
            ->select([
                'id', 'reference', 'title', 'category', 'amount', 'currency',
                'status', 'requires_board', 'requested_by', 'decided_by',
                'resolution_id', 'submitted_at', 'decided_at', 'created_at',
            ])
            // Resolution titles are not listed: the list is visible to people
            // who may not be able to open the resolution itself.
            ->with(['requestedBy:id,name', 'decidedBy:id,name'])
            ->latest('id');

        if ($status = $request->string('status')->toString()) {
            if ($status === 'pending') {
                // Legacy links: drafts and requests waiting for a decision.
                $query->whereIn('status', [SpendApproval::STATUS_DRAFT, SpendApproval::STATUS_SUBMITTED]);
            } else {
                $query->where('status', $status);
            }
        }
        if ($category = $request->string('category')->toString()) {
            $query->where('category', $category);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $approvals = $query->paginate(25)->withQueryString();

        // Totals follow the NZ financial year (1 July – 30 June, NZ time).
        [$yearStart, $yearEnd, $financialYear] = $this->currentFinancialYear();

        $summary = [
            'pending' => (clone $scope)->whereIn('status', [SpendApproval::STATUS_DRAFT, SpendApproval::STATUS_SUBMITTED])->count(),
            'waiting' => (clone $scope)->where('status', SpendApproval::STATUS_SUBMITTED)->count(),
            'drafts' => (clone $scope)->where('status', SpendApproval::STATUS_DRAFT)->count(),
            'approved_this_year' => (float) (clone $scope)->where('status', SpendApproval::STATUS_APPROVED)
                ->whereBetween('decided_at', [$yearStart, $yearEnd])->sum('amount'),
            'rejected_this_year' => (float) (clone $scope)->where('status', SpendApproval::STATUS_REJECTED)
                ->whereBetween('decided_at', [$yearStart, $yearEnd])->sum('amount'),
            'financial_year' => $financialYear,
        ];

        // The request wizard lives on this page (the old create page redirects
        // here with ?create=1). Its site picker is only built for viewers the
        // create gate and the canonical site scope both allow.
        $siteOptions = Gate::forUser($request->user())->allows('create', SpendApproval::class)
            ? $this->commands->accessibleSiteOptions($request->user())
            : [];
        $canCreate = $siteOptions !== [];

        return Inertia::render('Governance/SpendApprovals/Index', [
            'approvals' => [
                'data' => $approvals->items(),
                'links' => $approvals->linkCollection()->toArray(),
                'current_page' => $approvals->currentPage(),
                'last_page' => $approvals->lastPage(),
                'total' => $approvals->total(),
                'per_page' => $approvals->perPage(),
            ],
            'filters' => [
                'status' => $request->string('status')->toString() ?: null,
                'category' => $request->string('category')->toString() ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ],
            'summary' => $summary,
            'categories' => $this->categoryLabels(),
            'thresholds' => $this->thresholds(),
            'can_create' => $canCreate,
            'form_options' => $canCreate ? ['sites' => $siteOptions] : null,
        ]);
    }

    /**
     * Legacy deep link: the request wizard is a dialog on the index. Authorise
     * exactly as the retired page did, then open the dialog there.
     */
    public function create(Request $request): RedirectResponse
    {
        $this->authorize('create', SpendApproval::class);
        $this->commands->assertHasAccessibleSite($request->user());

        return redirect()->route('governance.spend-approvals.index', ['create' => 1]);
    }

    /** Legacy deep link: the edit wizard is a dialog on the show page. */
    public function edit(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('update', $approval);

        return redirect()->route('governance.spend-approvals.show', ['approval' => $approval->id, 'edit' => 1]);
    }

    /** @return array<string, float|int> */
    private function thresholds(): array
    {
        return [
            'capex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_CAPEX),
            'opex' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_OPEX),
            'supplier_contract' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_SUPPLIER_CONTRACT),
            'donor_restricted' => SpendApproval::thresholdFor(SpendApproval::CATEGORY_DONOR_RESTRICTED),
        ];
    }

    /**
     * Plain category names (shared Governance labels), keyed by stored value.
     *
     * @return array<string, string>
     */
    private function categoryLabels(): array
    {
        return collect(array_keys(SpendApproval::categories()))
            ->mapWithKeys(fn (string $key) => [$key => GovernanceLabels::label('spend_category', $key)])
            ->all();
    }

    /**
     * The current NZ financial year as UTC bounds plus its label ("2025/26").
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function currentFinancialYear(): array
    {
        $timezone = (string) (config('app.worker_timezone') ?: GovernanceLabels::TIMEZONE);
        $today = CarbonImmutable::now($timezone);
        $startYear = $today->month >= 7 ? $today->year : $today->year - 1;
        $start = CarbonImmutable::create($startYear, 7, 1, 0, 0, 0, $timezone);

        return [
            $start->utc(),
            $start->addYear()->subSecond()->utc(),
            GovernanceLabels::financialYear($startYear + 1),
        ];
    }

    public function show(Request $request, SpendApproval $approval): Response
    {
        $this->authorize('view', $approval);
        $user = $request->user();
        $approval = $this->commands->resolveAccessibleApproval($user, $approval->id);
        $this->commands->assertCanonicalSourceForRead($user, $approval);
        $approval->load([
            'requestedBy:id,name,email',
            'submittedBy:id,name,email',
            'decidedBy:id,name,email',
            'resolution:id,resolution_reference,title,status,outcome,governance_meeting_id',
            'budget:id,fiscal_year,title',
            'site:id,name',
        ]);

        // A linked resolution is shown only to viewers who can open it.
        $resolution = $approval->resolution && Gate::forUser($user)->allows('view', $approval->resolution)
            ? [
                'id' => (int) $approval->resolution->id,
                'title' => $approval->resolution->title,
                'reference' => $approval->resolution->resolution_reference,
                'outcome' => $approval->resolution->outcome,
            ]
            : null;
        $siteName = $approval->site?->name;
        $approval->unsetRelation('resolution');
        $approval->unsetRelation('site');

        $authority = [
            'update' => Gate::forUser($user)->allows('update', $approval),
            'submit' => Gate::forUser($user)->allows('submit', $approval),
            'decide' => Gate::forUser($user)->allows('decide', $approval),
            'manage_attachments' => Gate::forUser($user)->allows('manageAttachments', $approval),
        ];
        $isSubmitted = $approval->status === SpendApproval::STATUS_SUBMITTED;
        $threshold = SpendApproval::thresholdFor($approval->category);

        return Inertia::render('Governance/SpendApprovals/Show', [
            'approval' => $approval,
            'linked_resolution' => $resolution,
            'site_name' => $siteName,
            'financial_year_label' => $approval->budget ? GovernanceLabels::financialYear((string) $approval->budget->fiscal_year) : null,
            'categories' => $this->categoryLabels(),
            'threshold' => $threshold,
            'who_approves' => $this->whoApprovesSentence($threshold),
            'attachments' => $this->presentAttachments($approval),
            'authority' => $authority,
            // Why the viewer can't decide a request that is waiting for a decision.
            'decision_blocked_reason' => $isSubmitted && ! $authority['decide']
                ? $this->decisionBlockedReason($user, $approval)
                : null,
            // Passed resolutions the approver can link when board sign-off is needed.
            'board_resolution_options' => $isSubmitted && $authority['decide'] && $approval->requires_board
                ? $this->commands->boardResolutionOptions($user, $approval)
                : [],
            'can_view_resolutions' => Gate::forUser($user)->allows('viewAny', Resolution::class),
            // Edit wizard options — only for viewers who may edit this draft.
            'form_options' => $authority['update'] ? [
                'sites' => $this->commands->accessibleSiteOptions($user),
                'thresholds' => $this->thresholds(),
            ] : null,
        ]);
    }

    /** "Below $5,000: a finance approver decides. $5,000 and over: needs a board resolution." */
    private function whoApprovesSentence(float $threshold): string
    {
        $amount = GovernanceLabels::money($threshold);

        return "Below {$amount}: approved by a finance approver. {$amount} and over: needs a board resolution, which the approver links when recording the approval.";
    }

    private function decisionBlockedReason(User $user, SpendApproval $approval): string
    {
        if ((int) $approval->requested_by === (int) $user->id) {
            return 'You requested this, so another approver must decide it.';
        }

        if ($approval->submitted_by !== null && (int) $approval->submitted_by === (int) $user->id) {
            return 'You sent this request for a decision, so another approver must decide it.';
        }

        return 'Spend requests are decided by finance approvers. Ask the chair or the finance lead if you think you should be one.';
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', SpendApproval::class);
        $this->commands->assertHasAccessibleSite($request->user());
        $data = $this->validatePayload($request);
        $approval = $this->commands->create($request->user(), $data);

        return redirect()->route('governance.spend-approvals.show', $approval)
            ->with('success', 'Spend request saved as a draft. Send it for a decision when it is ready.');
    }

    public function update(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('update', $approval);

        $data = $this->validatePayload($request);
        $expectedVersion = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];

        $this->commands->update($request->user(), $approval->id, $data, (int) $expectedVersion);

        return back()->with('success', 'Spend request updated.');
    }

    public function submit(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('submit', $approval);
        $expectedVersion = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
        ])['expected_version'];
        $this->commands->submit($request->user(), $approval->id, (int) $expectedVersion);

        return back()->with('success', 'Spend request sent for a decision.');
    }

    public function approve(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('decideAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('decide', $approval);
        $validated = $this->validateDecision($request);
        $this->commands->decide($request->user(), $approval->id, SpendApproval::STATUS_APPROVED, $validated);

        return back()->with('success', 'Spend request approved.');
    }

    public function reject(Request $request, SpendApproval $approval): RedirectResponse
    {
        $this->authorize('decideAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('decide', $approval);
        $validated = $this->validateDecision($request);
        $this->commands->decide($request->user(), $approval->id, SpendApproval::STATUS_REJECTED, $validated);

        return back()->with('success', 'Spend request declined. The requester can see your reason.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'in:capex,opex,supplier_contract,donor_restricted'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'source_type' => ['nullable', 'string', 'required_with:source_id', Rule::in(SpendApproval::SOURCE_TYPES)],
            'source_id' => ['nullable', 'integer', 'min:1', 'required_with:source_type'],
            'site_id' => ['nullable', 'integer', 'min:1'],
            'cost_centre_id' => ['nullable', 'integer', 'min:1'],
            'funding_stream_id' => ['nullable', 'integer', 'min:1'],
            'donor_fund_id' => ['nullable', 'integer', 'min:1'],
            'budget_id' => ['nullable', 'integer', 'min:1'],
            'budget_line_item_id' => ['nullable', 'integer', 'min:1'],
            'valid_until' => ['nullable', 'date'],
        ], [
            'title.required' => 'Give the request a title.',
            'category.required' => 'Choose what kind of spend this is.',
            'category.in' => 'Choose one of the listed kinds of spend.',
            'amount.required' => 'Enter the amount in NZD.',
            'amount.numeric' => 'Enter the amount as a number.',
            'amount.min' => "The amount can't be negative.",
            'amount.max' => 'That amount is too large.',
            'valid_until.date' => 'Enter a valid date.',
        ]);
    }

    private function validateDecision(Request $request): array
    {
        $refresh = SpendApprovalCommandService::REFRESH_AND_RETRY;

        return $request->validate([
            'decision_key' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'expected_content_digest' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'decision_notes' => ['required', 'string', 'max:2000'],
            'resolution_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'decision_key.required' => $refresh,
            'decision_key.uuid' => $refresh,
            'expected_version.required' => $refresh,
            'expected_version.integer' => $refresh,
            'expected_version.min' => $refresh,
            'expected_content_digest.required' => $refresh,
            'expected_content_digest.size' => $refresh,
            'expected_content_digest.regex' => $refresh,
            'decision_notes.required' => 'Give a reason for your decision.',
            'decision_notes.max' => 'Keep the reason to 2,000 characters.',
            'resolution_id.integer' => 'Choose the resolution from the list.',
        ]);
    }

    /**
     * Upload supporting documents (quotes, contracts, invoices, due diligence)
     * for a spend approval. Critical for board audit of money decisions.
     */
    public function attachFiles(Request $request, SpendApproval $approval): RedirectResponse|Response|JsonResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('manageAttachments', $approval);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md',
            ],
        ]);

        $storedPaths = [];
        $attachments = [];

        try {
            foreach ($request->file('files') as $file) {
                $directory = "governance/spend-approvals/{$approval->id}";
                $extension = $file->getClientOriginalExtension() ?: $file->extension();
                $storedName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
                $path = $file->storeAs($directory, $storedName, 'local');
                $storedPaths[] = $path;
                $attachments[] = [
                    'id' => Str::uuid()->toString(),
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
                    'uploaded_at' => now()->toIso8601String(),
                    'uploaded_by_id' => $request->user()->id,
                    'uploaded_by_name' => $request->user()->name,
                ];
            }

            $approval = $this->commands->appendAttachments($request->user(), $approval->id, $attachments);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])
            : redirect()->back()->with('success', count($attachments) === 1 ? 'File attached.' : 'Files attached.');
    }

    public function deleteAttachment(Request $request, SpendApproval $approval, string $attachment): RedirectResponse|JsonResponse
    {
        $this->authorize('requestAny', SpendApproval::class);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->authorize('manageAttachments', $approval);
        [$approval, $target] = $this->commands->removeAttachment($request->user(), $approval->id, $attachment);

        if (isset($target['path']) && Storage::disk('local')->exists($target['path'])) {
            Storage::disk('local')->delete($target['path']);
        }

        return $request->wantsJson()
            ? response()->json(['attachments' => $this->presentAttachments($approval->fresh())])
            : redirect()->back()->with('success', 'File removed.');
    }

    public function downloadAttachment(Request $request, SpendApproval $approval, string $attachment)
    {
        $this->authorize('download', $approval);
        $approval = $this->commands->resolveAccessibleApproval($request->user(), $approval->id);
        $this->commands->assertCanonicalSourceForRead($request->user(), $approval);
        $existing = is_array($approval->attachments) ? $approval->attachments : [];
        $target = collect($existing)->firstWhere('id', $attachment);

        if (! $target || empty($target['path']) || ! Storage::disk('local')->exists($target['path'])) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk('local')->download(
            $target['path'],
            $target['original_name'] ?? 'attachment',
            ['Content-Type' => $target['mime_type'] ?? 'application/octet-stream'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function presentAttachments(SpendApproval $approval): array
    {
        $existing = is_array($approval->attachments) ? $approval->attachments : [];

        return collect($existing)->map(fn (array $row) => [
            'id' => $row['id'] ?? null,
            'original_name' => $row['original_name'] ?? 'attachment',
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => $row['size_bytes'] ?? null,
            'uploaded_at' => $row['uploaded_at'] ?? null,
            'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
            'download_url' => isset($row['id'])
                ? "/governance/spend-approvals/{$approval->id}/attachments/{$row['id']}/download"
                : null,
        ])->all();
    }
}
