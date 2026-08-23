<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Models\SpendApprovalDecision;
use App\Domain\Governance\Services\SpendApprovalCommandService;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class AccountsPayableService
{
    public function __construct(
        private JournalPostingService $journalPostingService,
        private GstTaxRateResolver $gstTaxRateResolver,
        private SpendApprovalCommandService $spendApprovals,
        private UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Return only decided approvals whose exact FinBill source and immutable
     * evidence remain current within the actor's canonical governance Site scope.
     *
     * @return Collection<int, array{id:int, reference:string, title:string, amount:string, category:string}>
     */
    public function linkableSpendApprovals(User $actor): Collection
    {
        if (Gate::forUser($actor)->denies('viewAny', SpendApproval::class)) {
            return collect();
        }

        $siteIds = $this->accessibleSpendSiteIds($actor);
        if ($siteIds === []) {
            return collect();
        }

        return SpendApproval::query()
            ->where('status', SpendApproval::STATUS_APPROVED)
            ->whereIn('site_id', $siteIds)
            ->where('source_type', FinBill::class)
            ->whereNotNull('source_id')
            ->where(fn ($query) => $query
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>=', now()->toDateString()))
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->filter(function (SpendApproval $approval): bool {
                $bill = FinBill::query()
                    ->whereKey($approval->source_id)
                    ->where('site_id', $approval->site_id)
                    ->first();
                if (! $bill) {
                    return false;
                }

                $decision = $this->currentDecisionQuery($approval)->first();

                return $this->hasCurrentSpendApprovalEvidence($approval, $bill, $decision);
            })
            ->map(fn (SpendApproval $approval): array => [
                'id' => (int) $approval->id,
                'reference' => $approval->reference,
                'title' => $approval->title,
                'amount' => (string) $approval->amount,
                'category' => $approval->category,
            ])
            ->values();
    }

    public function assertSpendApprovalLinkVisible(
        User $actor,
        ?FinBill $bill,
        int $approvalId,
    ): void {
        Gate::forUser($actor)->authorize('viewAny', SpendApproval::class);
        $siteIds = $this->accessibleSpendSiteIds($actor);
        if ($siteIds === []) {
            $this->concealSpendApproval();
        }

        $approval = SpendApproval::query()
            ->whereKey($approvalId)
            ->whereIn('site_id', $siteIds)
            ->where('source_type', FinBill::class)
            ->when($bill, fn ($query) => $query->where('source_id', $bill->id))
            ->firstOrFail();
        $sourceBill = FinBill::query()
            ->whereKey($approval->source_id)
            ->where('site_id', $approval->site_id)
            ->first();
        $decision = $this->currentDecisionQuery($approval)->first();

        if (! $sourceBill || ! $this->hasCurrentSpendApprovalEvidence($approval, $sourceBill, $decision)) {
            $this->concealSpendApproval();
        }
    }

    /**
     * Create a bill with lines. Auto-generate bill_number if not provided.
     */
    public function createBill(?int $orgId, array $data, ?User $actor = null): FinBill
    {
        return DB::transaction(function () use ($orgId, $data, $actor) {
            $approvalId = filled($data['spend_approval_id'] ?? null)
                ? (int) $data['spend_approval_id']
                : null;
            $approval = null;
            if ($approvalId) {
                if (! $actor) {
                    $this->concealSpendApproval();
                }
                $lockedActor = $this->lockUser($actor->id);
                Gate::forUser($lockedActor)->authorize('create', FinBill::class);
                $approval = $this->lockVisibleSpendApproval($lockedActor, $approvalId);
            }

            $billNumber = ! empty($data['bill_number'])
                ? $data['bill_number']
                : $this->generateBillNumber($orgId);

            $bill = FinBill::create([
                'organization_id' => $orgId,
                'vendor_id' => $data['vendor_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'spend_approval_id' => $data['spend_approval_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'allocation_event_type' => $data['allocation_event_type'] ?? null,
                'bill_number' => $billNumber,
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'status' => 'draft',
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'],
                'subtotal' => 0,
                'gst_amount' => 0,
                'total_amount' => 0,
                'amount_paid' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id ?? Auth::id(),
            ]);

            $subtotal = '0';
            $gstAmount = '0';

            foreach ($data['lines'] as $line) {
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $gstRate = (string) ($line['gst_rate'] ?? 15);
                $taxRate = $this->gstTaxRateResolver->matchInputRate(
                    (int) $orgId,
                    isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
                    $gstRate,
                );
                $gstFraction = $taxRate?->rate ?? bcdiv($gstRate, '100', 4);

                $lineSubtotal = bcmul($qty, $price, 2);
                $lineGst = bcmul($lineSubtotal, (string) $gstFraction, 2);
                $lineTotal = bcadd($lineSubtotal, $lineGst, 2);

                $bill->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstFraction,
                    'tax_rate_id' => $taxRate?->id,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'],
                    'cost_centre_id' => $line['cost_centre_id'] ?? null,
                    'funding_stream_id' => $line['funding_stream_id'] ?? null,
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $gstAmount = bcadd($gstAmount, $lineGst, 2);
            }

            $totalAmount = bcadd($subtotal, $gstAmount, 2);

            $bill->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ]);

            if ($approval) {
                $decision = $this->currentDecisionQuery($approval, true)->first();
                if (! $this->hasCurrentSpendApprovalEvidence($approval, $bill->fresh(), $decision)) {
                    $this->concealSpendApproval();
                }
            }

            return $bill->load('lines', 'vendor');
        });
    }

    /**
     * Capture-at-source: record an operational expense (a repair, a delivery, …)
     * as a DRAFT accounts-payable bill. Kept draft so it stays GL-safe — the GL
     * journal posts only when a finance user approves it (approveBill), never
     * automatically. Idempotent on `reference` (stored as vendor_reference), so a
     * source event that fires more than once never creates a duplicate bill. The
     * vendor is resolved-or-created by name; the expense account is resolved by
     * code and throws if the chart is missing it (never invents one).
     *
     * When `site_id`/`asset_id`/`allocation_event_type` are given, approving the
     * bill also creates the FinCostAllocation rows that feed site cost reporting.
     *
     * @param  array{reference:string,vendor_name:string,description:string,amount:float|string,account_code:string,vendor_type?:string,gst_rate?:float|int,bill_date?:string,due_date?:string,notes?:string,cost_centre_id?:int,site_id?:int,asset_id?:int,allocation_event_type?:string}  $data
     */
    public function captureOperationalBill(?int $orgId, array $data): ?FinBill
    {
        $amount = (float) ($data['amount'] ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $reference = $data['reference'];

        $existing = FinBill::where('organization_id', $orgId)
            ->where('vendor_reference', $reference)
            ->first();
        if ($existing) {
            return $existing;
        }

        $vendor = FinVendor::firstOrCreate(
            ['organization_id' => $orgId, 'name' => $data['vendor_name']],
            ['vendor_type' => $data['vendor_type'] ?? 'contractor', 'is_active' => true],
        );

        $account = $this->resolveExpenseAccount($orgId, $data['account_code']);

        return $this->createBill($orgId, [
            'vendor_id' => $vendor->id,
            'vendor_reference' => $reference,
            'bill_date' => $data['bill_date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
            'notes' => $data['notes'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'asset_id' => $data['asset_id'] ?? null,
            'allocation_event_type' => $data['allocation_event_type'] ?? null,
            'lines' => [[
                'description' => $data['description'],
                'quantity' => 1,
                'unit_price' => $amount,
                'gst_rate' => $data['gst_rate'] ?? 15,
                'account_id' => $account->id,
                'cost_centre_id' => $data['cost_centre_id'] ?? null,
            ]],
        ]);
    }

    /**
     * Resolve an active expense GL account by code for an org, throwing if the
     * chart is missing it — capture-at-source must never invent a chart code.
     */
    private function resolveExpenseAccount(?int $orgId, string $code): FinAccount
    {
        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new InvalidArgumentException(
                "GL expense account '{$code}' not found (or inactive) for organisation #{$orgId}."
            );
        }

        return $account;
    }

    /**
     * Update a bill. Only allowed if draft.
     */
    public function updateBill(FinBill $bill, array $data, ?User $actor = null): FinBill
    {
        return DB::transaction(function () use ($bill, $data, $actor) {
            $approvalId = filled($data['spend_approval_id'] ?? null)
                ? (int) $data['spend_approval_id']
                : null;
            $approval = null;
            if ($approvalId) {
                if (! $actor) {
                    $this->concealSpendApproval();
                }
                $lockedActor = $this->lockUser($actor->id);
                $approval = $this->lockVisibleSpendApproval($lockedActor, $approvalId);
            }

            $lockedBill = FinBill::query()
                ->lockForUpdate()
                ->findOrFail($bill->id);
            if ($lockedBill->status !== 'draft') {
                throw new InvalidArgumentException('Only draft bills can be updated.');
            }
            if ($approval) {
                Gate::forUser($lockedActor)->authorize('update', $lockedBill);
            }

            $lockedBill->update([
                'vendor_id' => $data['vendor_id'],
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'spend_approval_id' => $data['spend_approval_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            // Delete existing lines and recreate
            $lockedBill->lines()->delete();

            $subtotal = '0';
            $gstAmount = '0';

            foreach ($data['lines'] as $line) {
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $gstRate = (string) ($line['gst_rate'] ?? 15);
                $taxRate = $this->gstTaxRateResolver->matchInputRate(
                    (int) $bill->organization_id,
                    isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
                    $gstRate,
                );
                $gstFraction = $taxRate?->rate ?? bcdiv($gstRate, '100', 4);

                $lineSubtotal = bcmul($qty, $price, 2);
                $lineGst = bcmul($lineSubtotal, (string) $gstFraction, 2);
                $lineTotal = bcadd($lineSubtotal, $lineGst, 2);

                $lockedBill->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstFraction,
                    'tax_rate_id' => $taxRate?->id,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'],
                    'cost_centre_id' => $line['cost_centre_id'] ?? null,
                    'funding_stream_id' => $line['funding_stream_id'] ?? null,
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $gstAmount = bcadd($gstAmount, $lineGst, 2);
            }

            $totalAmount = bcadd($subtotal, $gstAmount, 2);

            $lockedBill->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ]);

            if ($approval) {
                $decision = $this->currentDecisionQuery($approval, true)->first();
                if (! $this->hasCurrentSpendApprovalEvidence($approval, $lockedBill->fresh(), $decision)) {
                    $this->concealSpendApproval();
                }
            }

            return $lockedBill->load('lines', 'vendor');
        });
    }

    /**
     * Approve a bill and create the GL journal entry.
     * DR expense accounts (per line), CR Accounts Payable (code '2000').
     */
    public function approveBill(FinBill $bill, int $userId): FinBill
    {
        $preflight = FinBill::query()
            ->whereKey($bill->id)
            ->firstOrFail(['id', 'spend_approval_id', 'total_amount']);
        $threshold = (float) config('finance.spend_approval.threshold', 10000);
        $preflightRequiresSpendApproval = config('finance.spend_approval.enforce', false)
            && (float) $preflight->total_amount >= $threshold;

        return DB::transaction(function () use (
            $bill,
            $userId,
            $preflight,
            $preflightRequiresSpendApproval,
            $threshold,
        ) {
            $lockedActor = $this->lockUser($userId);
            $approval = null;
            if ($preflightRequiresSpendApproval && $preflight->spend_approval_id) {
                $approval = $this->lockVisibleSpendApproval(
                    $lockedActor,
                    (int) $preflight->spend_approval_id,
                );
            }

            $lockedBill = FinBill::query()
                ->lockForUpdate()
                ->findOrFail($bill->id);
            if (! in_array($lockedBill->status, ['draft', 'awaiting_approval'])) {
                throw new InvalidArgumentException('Only draft or awaiting approval bills can be approved.');
            }

            $requiresSpendApproval = config('finance.spend_approval.enforce', false)
                && (float) $lockedBill->total_amount >= $threshold;
            if ($requiresSpendApproval) {
                Gate::forUser($lockedActor)->authorize('approve', $lockedBill);
                if (! $approval
                    || (int) $lockedBill->spend_approval_id !== (int) $preflight->spend_approval_id) {
                    $this->throwSpendApprovalGate($lockedBill, $threshold);
                }
                $decision = $this->currentDecisionQuery($approval, true)->first();
                $this->assertSpendApprovalSatisfied($lockedBill, $approval, $decision, $threshold);
            }

            $lockedBill->loadMissing('lines.taxRate', 'vendor');

            $apAccount = $this->findApAccount($lockedBill->organization_id);

            $journalLines = [];

            // DR net expense/asset amounts. The per-source tax metadata remains
            // on these lines; the separate 2210 line carries the recoverable GST
            // control balance without being counted as a second GST component.
            foreach ($lockedBill->lines as $line) {
                $taxRate = $this->gstTaxRateResolver->resolveStoredRate(
                    (int) $lockedBill->organization_id,
                    $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
                    (string) $line->gst_rate,
                    "Bill {$lockedBill->bill_number} line #{$line->id}",
                );
                $netAmount = bcsub((string) $line->line_total, (string) $line->gst_amount, 2);

                $journalLines[] = [
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'debit' => $netAmount,
                    'credit' => 0,
                    'cost_centre_id' => $line->cost_centre_id,
                    'funding_stream_id' => $line->funding_stream_id,
                    'tax_rate_id' => $taxRate?->id,
                    'tax_amount' => $line->gst_amount,
                ];
            }

            if (bccomp((string) $lockedBill->gst_amount, '0.00', 2) > 0) {
                $journalLines[] = [
                    'account_id' => $this->findAccountByCode($lockedBill->organization_id, '2210')->id,
                    'description' => "GST Paid - {$lockedBill->bill_number}",
                    'debit' => $lockedBill->gst_amount,
                    'credit' => 0,
                ];
            }

            // CR Accounts Payable for the total amount
            $journalLines[] = [
                'account_id' => $apAccount->id,
                'description' => 'Bill '.$lockedBill->bill_number.' — '.($lockedBill->vendor?->name ?? 'Unknown vendor'),
                'debit' => 0,
                'credit' => $lockedBill->total_amount,
            ];

            $journal = $this->journalPostingService->createAndPost($lockedBill->organization_id, [
                'journal_date' => $lockedBill->bill_date->toDateString(),
                'type' => 'standard',
                'reference' => $lockedBill->bill_number,
                'description' => "Bill {$lockedBill->bill_number} approved",
                'source_type' => FinBill::class,
                'source_id' => $lockedBill->id,
                'lines' => $journalLines,
            ]);

            $lockedBill->update([
                'status' => 'approved',
                'approved_by' => $lockedActor->id,
                'approved_at' => now(),
                'journal_id' => $journal->id,
            ]);

            $this->allocateCapturedBill($lockedBill, $journal);

            return $lockedBill->refresh();
        });
    }

    /**
     * Cost-allocate an approved capture-at-source bill. FinCostAllocation is the
     * cross-module layer site budgets/forecasts read (SiteCostService groups by
     * event_type), and it is otherwise only written by FinancialEventService — so
     * a bill that carries operational context (site/asset) must allocate its
     * expense lines here or the spend silently vanishes from site reporting.
     */
    private function allocateCapturedBill(FinBill $bill, $journal): void
    {
        if (! $bill->site_id && ! $bill->asset_id) {
            return;
        }

        $journal->loadMissing('lines');
        $expenseLineIds = $bill->lines->pluck('account_id')->all();

        foreach ($journal->lines as $journalLine) {
            if (bccomp((string) $journalLine->debit, '0', 2) <= 0) {
                continue; // allocate expense (debit) lines only, never the AP credit
            }
            if (! in_array($journalLine->account_id, $expenseLineIds, true)) {
                continue;
            }

            FinCostAllocation::create([
                'journal_id' => $journal->id,
                'journal_line_id' => $journalLine->id,
                'financial_event_id' => null,
                'site_id' => $bill->site_id,
                'asset_id' => $bill->asset_id,
                'amount' => $journalLine->debit,
                'event_type' => $bill->allocation_event_type ?: 'bill_expense',
                'event_date' => $bill->bill_date->toDateString(),
            ]);
        }
    }

    /**
     * Governance spend-approval gate. When enforcement is enabled (config
     * finance.spend_approval.enforce), a bill at/above the configured threshold
     * can only be approved once it is linked to a SpendApproval that is APPROVED
     * and whose amount covers the full bill total. Off by default, so existing
     * AP flows are unaffected until an org opts in. Surfaced as an
     * InvalidArgumentException (the approve action catches it → flash error);
     * this NEVER creates a SpendApproval — the link is one-directional.
     */
    private function assertSpendApprovalSatisfied(
        FinBill $bill,
        SpendApproval $approval,
        ?SpendApprovalDecision $decision,
        float $threshold,
    ): void {
        if (! $this->hasCurrentSpendApprovalEvidence($approval, $bill, $decision)) {
            $this->throwSpendApprovalGate($bill, $threshold);
        }
    }

    private function hasCurrentSpendApprovalEvidence(
        SpendApproval $approval,
        FinBill $bill,
        ?SpendApprovalDecision $decision,
    ): bool {
        if ($approval->status !== SpendApproval::STATUS_APPROVED
            || ! $approval->site_id
            || ! $bill->site_id
            || (int) $approval->site_id !== (int) $bill->site_id
            || $approval->source_type !== FinBill::class
            || (int) $approval->source_id !== (int) $bill->id
            || (float) $approval->amount < (float) $bill->total_amount
            || strtoupper((string) $approval->currency) !== 'NZD'
            || ($approval->valid_until && $approval->valid_until->isBefore(today()))
            || (int) $approval->requested_by === (int) $approval->decided_by
            || ! $decision) {
            return false;
        }

        try {
            $sourceEvidence = $this->spendApprovals->canonicalBillSourceEvidence(
                $bill,
                (int) $approval->site_id,
            );
            $currentDigest = $approval->decisionContentDigest($sourceEvidence);
        } catch (Throwable) {
            return false;
        }

        $decisionSource = $decision->parent_evidence['source'] ?? null;

        return is_string($approval->content_digest)
            && hash_equals($approval->content_digest, $currentDigest)
            && $decision->outcome === SpendApproval::STATUS_APPROVED
            && (int) $decision->submission_version === (int) $approval->submission_version
            && is_string($decision->content_digest)
            && hash_equals($decision->content_digest, $approval->content_digest)
            && (int) $decision->decided_by === (int) $approval->decided_by
            && $decision->decided_at?->equalTo($approval->decided_at) === true
            && trim((string) $decision->reason) !== ''
            && trim((string) $approval->decision_notes) === trim((string) $decision->reason)
            && $decisionSource === $sourceEvidence;
    }

    private function currentDecisionQuery(SpendApproval $approval, bool $lock = false)
    {
        $query = SpendApprovalDecision::query()
            ->where('spend_approval_id', $approval->id)
            ->where('submission_version', $approval->submission_version)
            ->where('content_digest', $approval->content_digest)
            ->orderByDesc('evidence_version')
            ->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query;
    }

    private function lockVisibleSpendApproval(User $actor, int $approvalId): SpendApproval
    {
        Gate::forUser($actor)->authorize('viewAny', SpendApproval::class);
        $siteIds = $this->accessibleSpendSiteIds($actor);
        if ($siteIds === []) {
            $this->concealSpendApproval();
        }

        return SpendApproval::query()
            ->whereKey($approvalId)
            ->whereIn('site_id', $siteIds)
            ->where('source_type', FinBill::class)
            ->whereNotNull('source_id')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<int, int> */
    private function accessibleSpendSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $actor,
            UserSiteAccessService::GOVERNANCE_SPEND_SITE_BYPASS_PERMISSIONS,
        );
    }

    private function lockUser(int $userId): User
    {
        return User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    private function concealSpendApproval(): never
    {
        throw (new ModelNotFoundException)->setModel(SpendApproval::class);
    }

    private function throwSpendApprovalGate(FinBill $bill, float $threshold): never
    {
        throw new InvalidArgumentException(sprintf(
            'This bill (NZD %s) is at or above the NZD %s spend-approval threshold. Link an approved spend approval covering the full amount before approving it.',
            number_format((float) $bill->total_amount, 2),
            number_format($threshold, 2),
        ));
    }

    /**
     * Cancel a bill. Only allowed if draft or awaiting_approval.
     */
    public function cancelBill(FinBill $bill): FinBill
    {
        if (! in_array($bill->status, ['draft', 'awaiting_approval'])) {
            throw new InvalidArgumentException('Only draft or awaiting approval bills can be cancelled.');
        }

        $bill->update(['status' => 'cancelled']);

        return $bill->refresh();
    }

    /**
     * Record a payment against a bill.
     */
    public function recordPayment(FinBill $bill, string|int|float $amount): FinBill
    {
        if ((is_float($amount) && ! is_finite($amount))
            || (is_string($amount) && ! preg_match('/\A-?\d+(?:\.\d{1,2})?\z/D', trim($amount)))) {
            throw new InvalidArgumentException('Payment amount must be a positive finite value.');
        }

        $paymentAmount = is_float($amount)
            ? number_format($amount, 2, '.', '')
            : bcadd(trim((string) $amount), '0.00', 2);
        if (bccomp($paymentAmount, '0.00', 2) <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($bill, $paymentAmount): FinBill {
            $lockedBill = FinBill::query()
                ->lockForUpdate()
                ->findOrFail($bill->getKey());

            if (! in_array($lockedBill->status, ['approved', 'partially_paid'], true)) {
                throw new InvalidArgumentException(
                    "Bill {$lockedBill->bill_number} is not in a payable state."
                );
            }

            $amountDue = bcsub(
                (string) $lockedBill->total_amount,
                (string) $lockedBill->amount_paid,
                2,
            );

            if (bccomp($paymentAmount, $amountDue, 2) > 0) {
                throw new InvalidArgumentException(
                    "Payment amount {$paymentAmount} exceeds bill amount due {$amountDue}."
                );
            }

            $newPaid = bcadd((string) $lockedBill->amount_paid, $paymentAmount, 2);
            $lockedBill->forceFill([
                'amount_paid' => $newPaid,
                'status' => bccomp($newPaid, (string) $lockedBill->total_amount, 2) === 0
                    ? 'paid'
                    : 'partially_paid',
            ])->save();

            return $lockedBill->refresh();
        });
    }

    /**
     * Create a credit note (type='payable' for AP).
     */
    public function createCreditNote(?int $orgId, array $data): FinCreditNote
    {
        return DB::transaction(function () use ($orgId, $data) {
            $creditNoteNumber = $this->generateCreditNoteNumber($orgId);

            $creditNote = FinCreditNote::create([
                'organization_id' => $orgId,
                'credit_note_number' => $creditNoteNumber,
                'type' => $data['type'] ?? 'payable',
                'vendor_id' => $data['vendor_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'bill_id' => $data['bill_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'status' => 'draft',
                'credit_date' => $data['credit_date'],
                'subtotal' => 0,
                'gst_amount' => 0,
                'total_amount' => 0,
                'reason' => $data['reason'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $subtotal = '0';
            $gstAmount = '0';

            foreach ($data['lines'] as $line) {
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $gstRate = (string) ($line['gst_rate'] ?? 15);
                $taxRate = $this->gstTaxRateResolver->matchInputRate(
                    (int) $orgId,
                    isset($line['tax_rate_id']) ? (int) $line['tax_rate_id'] : null,
                    $gstRate,
                );
                $gstFraction = $taxRate?->rate ?? bcdiv($gstRate, '100', 4);

                $lineSubtotal = bcmul($qty, $price, 2);
                $lineGst = bcmul($lineSubtotal, (string) $gstFraction, 2);
                $lineTotal = bcadd($lineSubtotal, $lineGst, 2);

                $creditNote->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'gst_rate' => $gstFraction,
                    'tax_rate_id' => $taxRate?->id,
                    'gst_amount' => $lineGst,
                    'line_total' => $lineTotal,
                    'account_id' => $line['account_id'],
                ]);

                $subtotal = bcadd($subtotal, $lineSubtotal, 2);
                $gstAmount = bcadd($gstAmount, $lineGst, 2);
            }

            $totalAmount = bcadd($subtotal, $gstAmount, 2);

            $creditNote->update([
                'subtotal' => $subtotal,
                'gst_amount' => $gstAmount,
                'total_amount' => $totalAmount,
            ]);

            return $creditNote->load('lines');
        });
    }

    /**
     * Approve a credit note and create a reversing GL journal.
     */
    public function approveCreditNote(FinCreditNote $cn, int $userId): FinCreditNote
    {
        if ($cn->status !== 'draft') {
            throw new InvalidArgumentException('Only draft credit notes can be approved.');
        }

        return DB::transaction(function () use ($cn, $userId) {
            $cn->loadMissing('lines.taxRate');

            $journalLines = [];

            // line_total is GST-inclusive; reverse the net (ex-GST) against the
            // expense/revenue accounts and the GST portion against the GST control
            // account, so the reversal mirrors the original invoice/bill journal
            // (which split net vs GST) instead of lumping GST into revenue/expense.
            $gstAmount = (string) ($cn->gst_amount ?? 0);

            if ($cn->type === 'payable') {
                // AP credit note: DR Accounts Payable / CR expense (net) + CR GST Paid (2210)
                $apAccount = $this->findApAccount($cn->organization_id);

                $journalLines[] = [
                    'account_id' => $apAccount->id,
                    'description' => "Credit Note {$cn->credit_note_number}",
                    'debit' => $cn->total_amount,
                    'credit' => 0,
                ];

                foreach ($cn->lines as $line) {
                    $taxRate = $this->gstTaxRateResolver->resolveStoredRate(
                        (int) $cn->organization_id,
                        $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
                        (string) $line->gst_rate,
                        "Credit note {$cn->credit_note_number} line #{$line->id}",
                    );
                    $journalLines[] = [
                        'account_id' => $line->account_id,
                        'description' => $line->description,
                        'debit' => 0,
                        'credit' => bcsub((string) $line->line_total, (string) ($line->gst_amount ?? 0), 2),
                        'tax_rate_id' => $taxRate?->id,
                        'tax_amount' => bcsub('0.00', (string) $line->gst_amount, 2),
                    ];
                }

                if (bccomp($gstAmount, '0', 2) > 0) {
                    $journalLines[] = [
                        'account_id' => $this->findAccountByCode($cn->organization_id, '2210')->id,
                        'description' => "GST on Credit Note {$cn->credit_note_number}",
                        'debit' => 0,
                        'credit' => $gstAmount,
                    ];
                }
            } else {
                // AR credit note: DR revenue (net) + DR GST Collected (2200) / CR Accounts Receivable
                $arAccount = $this->findAccountByCode($cn->organization_id, '1100');

                foreach ($cn->lines as $line) {
                    $taxRate = $this->gstTaxRateResolver->resolveStoredRate(
                        (int) $cn->organization_id,
                        $line->tax_rate_id === null ? null : (int) $line->tax_rate_id,
                        (string) $line->gst_rate,
                        "Credit note {$cn->credit_note_number} line #{$line->id}",
                    );
                    $journalLines[] = [
                        'account_id' => $line->account_id,
                        'description' => $line->description,
                        'debit' => bcsub((string) $line->line_total, (string) ($line->gst_amount ?? 0), 2),
                        'credit' => 0,
                        'tax_rate_id' => $taxRate?->id,
                        'tax_amount' => bcsub('0.00', (string) $line->gst_amount, 2),
                    ];
                }

                if (bccomp($gstAmount, '0', 2) > 0) {
                    $journalLines[] = [
                        'account_id' => $this->findAccountByCode($cn->organization_id, '2200')->id,
                        'description' => "GST on Credit Note {$cn->credit_note_number}",
                        'debit' => $gstAmount,
                        'credit' => 0,
                    ];
                }

                $journalLines[] = [
                    'account_id' => $arAccount->id,
                    'description' => "Credit Note {$cn->credit_note_number}",
                    'debit' => 0,
                    'credit' => $cn->total_amount,
                ];
            }

            $journal = $this->journalPostingService->createAndPost($cn->organization_id, [
                'journal_date' => $cn->credit_date->toDateString(),
                'type' => 'adjustment',
                'reference' => $cn->credit_note_number,
                'description' => "Credit Note {$cn->credit_note_number} approved",
                'source_type' => FinCreditNote::class,
                'source_id' => $cn->id,
                'lines' => $journalLines,
            ]);

            $cn->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'journal_id' => $journal->id,
            ]);

            return $cn->refresh();
        });
    }

    /**
     * Get aged payables report for an organisation.
     */
    public function getAgedPayables(?int $orgId): array
    {
        $today = Carbon::today();

        $vendors = FinVendor::forOrganization($orgId)
            ->active()
            ->with(['bills' => function ($query) {
                $query->whereColumn('amount_paid', '<', 'total_amount')
                    ->whereNotIn('status', ['cancelled', 'draft']);
            }])
            ->get();

        $result = [];

        foreach ($vendors as $vendor) {
            if ($vendor->bills->isEmpty()) {
                continue;
            }

            $buckets = [
                'current' => ['count' => 0, 'total' => 0],
                '1_30' => ['count' => 0, 'total' => 0],
                '31_60' => ['count' => 0, 'total' => 0],
                '61_90' => ['count' => 0, 'total' => 0],
                '90_plus' => ['count' => 0, 'total' => 0],
            ];

            $vendorTotal = 0;

            foreach ($vendor->bills as $bill) {
                $amountDue = (float) $bill->total_amount - (float) $bill->amount_paid;
                $daysOverdue = $bill->due_date->lt($today)
                    ? $bill->due_date->diffInDays($today)
                    : 0;

                if ($daysOverdue === 0) {
                    $buckets['current']['count']++;
                    $buckets['current']['total'] = round($buckets['current']['total'] + $amountDue, 2);
                } elseif ($daysOverdue <= 30) {
                    $buckets['1_30']['count']++;
                    $buckets['1_30']['total'] = round($buckets['1_30']['total'] + $amountDue, 2);
                } elseif ($daysOverdue <= 60) {
                    $buckets['31_60']['count']++;
                    $buckets['31_60']['total'] = round($buckets['31_60']['total'] + $amountDue, 2);
                } elseif ($daysOverdue <= 90) {
                    $buckets['61_90']['count']++;
                    $buckets['61_90']['total'] = round($buckets['61_90']['total'] + $amountDue, 2);
                } else {
                    $buckets['90_plus']['count']++;
                    $buckets['90_plus']['total'] = round($buckets['90_plus']['total'] + $amountDue, 2);
                }

                $vendorTotal = round($vendorTotal + $amountDue, 2);
            }

            $result[] = [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'buckets' => $buckets,
                'total' => $vendorTotal,
            ];
        }

        return $result;
    }

    /**
     * Find the Accounts Payable GL account (code '2000') for the org.
     */
    public function findApAccount(?int $orgId): FinAccount
    {
        return FinAccount::forOrganization($orgId)
            ->where('code', '2000')
            ->firstOrFail();
    }

    private function findAccountByCode(?int $orgId, string $code): FinAccount
    {
        return FinAccount::forOrganization($orgId)
            ->where('code', $code)
            ->firstOrFail();
    }

    /**
     * Generate the next sequential bill number for an organisation.
     * Format: BILL-YYYYMM-001
     */
    private function generateBillNumber(?int $orgId): string
    {
        return FinBill::nextNumber($orgId);
    }

    /**
     * Generate the next sequential credit note number for an organisation.
     * Format: CN-YYYYMM-001
     */
    private function generateCreditNoteNumber(?int $orgId): string
    {
        $prefix = 'CN-'.now()->format('Ym').'-';

        $maxNumber = FinCreditNote::where('organization_id', $orgId)
            ->where('credit_note_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(credit_note_number, '.(strlen($prefix) + 1).') AS UNSIGNED)) as max_num')
            ->value('max_num');

        $next = ($maxNumber ?? 0) + 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
