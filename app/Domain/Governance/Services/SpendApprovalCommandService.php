<?php

namespace App\Domain\Governance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPaymentRunItem;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\SpendApproval;
use App\Domain\Governance\Models\SpendApprovalDecision;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpendApprovalCommandService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function assertHasAccessibleSite(User $actor): void
    {
        if ($this->accessibleSiteIds($actor) === []) {
            $this->conceal();
        }
    }

    /** @return array<int, array{id: int, name: string}> */
    public function accessibleSiteOptions(User $actor): array
    {
        $siteIds = $this->accessibleSiteIds($actor);
        if ($siteIds === []) {
            return [];
        }

        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $siteIds)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Site $site) => ['id' => (int) $site->id, 'name' => $site->name])
            ->all();
    }

    public function resolveAccessibleApproval(User $actor, int $approvalId): SpendApproval
    {
        return $this->scopedApprovalQuery($actor)
            ->whereKey($approvalId)
            ->firstOrFail();
    }

    public function accessibleApprovalQuery(User $actor): Builder
    {
        return $this->applyCanonicalReadSourceScope($this->scopedApprovalQuery($actor));
    }

    /**
     * Shared fail-closed reader for mixed governance surfaces. Site scope never
     * substitutes for the exact spend-view action, and an empty Site set does
     * not expose even zero-value spend cards or direct links.
     */
    public function readableApprovalQuery(?User $actor): ?Builder
    {
        if (! $actor instanceof User
            || ! $actor->exists
            || Gate::forUser($actor)->denies('viewAny', SpendApproval::class)) {
            return null;
        }

        $siteIds = $this->accessibleSiteIds($actor);
        if ($siteIds === []) {
            return null;
        }

        return $this->applyCanonicalReadSourceScope(
            $this->scopedApprovalQueryForSiteIds($siteIds),
        );
    }

    public function assertCanonicalSourceForRead(User $actor, SpendApproval $approval): void
    {
        $siteIds = $this->accessibleSiteIds($actor);
        $this->canonicalSourceEvidence(
            $approval->only(['source_type', 'source_id']),
            (int) $approval->site_id,
            $siteIds,
            false,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalBillSourceEvidence(FinBill $bill, int $approvalSiteId): array
    {
        return $this->billSourceEvidence($bill, $approvalSiteId);
    }

    public function create(User $actor, array $payload): SpendApproval
    {
        return DB::transaction(function () use ($actor, $payload): SpendApproval {
            $reference = $this->nextReference();
            $lockedActor = $this->lockActors([$actor->id])[$actor->id];
            Gate::forUser($lockedActor)->authorize('create', SpendApproval::class);
            $accessibleSiteIds = $this->accessibleSiteIds($lockedActor);
            if ($accessibleSiteIds === []) {
                $this->conceal();
            }

            [$parents] = $this->lockAndCanonicalizeParents($payload, $accessibleSiteIds);
            $payload = array_merge($payload, $parents, [
                'reference' => $reference,
                'status' => SpendApproval::STATUS_DRAFT,
                'requested_by' => $lockedActor->id,
                'requires_board' => $this->requiresBoard($payload),
                'version' => 1,
            ]);

            $approval = SpendApproval::create($payload);
            $this->audit('spend_approval.created', $approval->id, [
                'amount' => $approval->amount,
                'category' => $approval->category,
                'requires_board' => $approval->requires_board,
            ]);

            return $approval;
        });
    }

    public function update(User $actor, int $approvalId, array $payload, int $expectedVersion): SpendApproval
    {
        Gate::forUser($actor)->authorize('requestAny', SpendApproval::class);
        $preflight = $this->resolveAccessibleApproval($actor, $approvalId);

        return DB::transaction(function () use ($actor, $approvalId, $payload, $expectedVersion, $preflight): SpendApproval {
            $lockedActor = $this->lockActors([$actor->id, $preflight->requested_by])[$actor->id];
            Gate::forUser($lockedActor)->authorize('requestAny', SpendApproval::class);
            $accessibleSiteIds = $this->accessibleSiteIds($lockedActor);
            $approval = $this->lockApproval($approvalId, $accessibleSiteIds);
            Gate::forUser($lockedActor)->authorize('update', $approval);
            $this->assertVersion($approval, $expectedVersion);

            $candidate = array_merge($approval->only($this->parentFields()), $payload);
            [$parents] = $this->lockAndCanonicalizeParents($candidate, $accessibleSiteIds);
            $approval->fill(array_merge($payload, $parents, [
                'requires_board' => $this->requiresBoard($payload),
                'version' => $approval->version + 1,
                'content_digest' => null,
            ]))->save();

            $this->audit('spend_approval.updated', $approval->id, [
                'version' => $approval->version,
            ]);

            return $approval;
        });
    }

    public function submit(User $actor, int $approvalId, int $expectedVersion): SpendApproval
    {
        Gate::forUser($actor)->authorize('requestAny', SpendApproval::class);
        $preflight = $this->resolveAccessibleApproval($actor, $approvalId);

        return DB::transaction(function () use ($actor, $approvalId, $expectedVersion, $preflight): SpendApproval {
            $lockedActor = $this->lockActors([$actor->id, $preflight->requested_by])[$actor->id];
            Gate::forUser($lockedActor)->authorize('requestAny', SpendApproval::class);
            $accessibleSiteIds = $this->accessibleSiteIds($lockedActor);
            $approval = $this->lockApproval($approvalId, $accessibleSiteIds);
            Gate::forUser($lockedActor)->authorize('submit', $approval);
            $this->assertVersion($approval, $expectedVersion);

            [$parents, $parentEvidence] = $this->lockAndCanonicalizeParents(
                $approval->only($this->parentFields()),
                $accessibleSiteIds,
            );
            $approval->fill($parents);
            $nextVersion = $approval->version + 1;
            $approval->fill([
                'status' => SpendApproval::STATUS_SUBMITTED,
                'submitted_by' => $lockedActor->id,
                'submitted_at' => now(),
                'submission_version' => $nextVersion,
                'content_digest' => $approval->decisionContentDigest($parentEvidence['source']),
                'version' => $nextVersion,
            ])->save();

            $this->audit('spend_approval.submitted', $approval->id, [
                'submission_version' => $approval->submission_version,
                'content_digest' => $approval->content_digest,
            ]);

            return $approval;
        });
    }

    public function decide(User $actor, int $approvalId, string $outcome, array $command): SpendApproval
    {
        Gate::forUser($actor)->authorize('decideAny', SpendApproval::class);
        $preflight = $this->resolveAccessibleApproval($actor, $approvalId);
        if (! in_array($outcome, [SpendApproval::STATUS_APPROVED, SpendApproval::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages(['decision' => 'The decision outcome is invalid.']);
        }
        $this->validateDecisionCommand($outcome, $command);

        return DB::transaction(function () use ($actor, $approvalId, $outcome, $command, $preflight): SpendApproval {
            $actors = $this->lockActors([
                $actor->id,
                $preflight->requested_by,
                $preflight->submitted_by,
            ]);
            $lockedActor = $actors[$actor->id];
            Gate::forUser($lockedActor)->authorize('decideAny', SpendApproval::class);
            $accessibleSiteIds = $this->accessibleSiteIds($lockedActor);
            $approval = $this->lockApproval($approvalId, $accessibleSiteIds);
            Gate::forUser($lockedActor)->authorize('decide', $approval);

            $fingerprint = $this->decisionFingerprint($lockedActor->id, $outcome, $command);
            $prior = SpendApprovalDecision::query()
                ->where('spend_approval_id', $approval->id)
                ->where('stable_key', $command['decision_key'])
                ->first();

            if ($prior) {
                if (! hash_equals($prior->request_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages([
                        'decision_key' => 'This decision key was already used with different decision content.',
                    ]);
                }

                return $approval;
            }

            if ($approval->status !== SpendApproval::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'decision' => 'This spend approval has already been decided or is not submitted.',
                ]);
            }

            $this->assertVersion($approval, (int) $command['expected_version']);
            [, $parentEvidence] = $this->lockAndCanonicalizeParents(
                $approval->only($this->parentFields()),
                $accessibleSiteIds,
            );
            if (! is_string($approval->content_digest)
                || ! hash_equals($approval->content_digest, (string) $command['expected_content_digest'])
                || ! hash_equals($approval->content_digest, $approval->decisionContentDigest($parentEvidence['source']))) {
                throw ValidationException::withMessages([
                    'expected_content_digest' => 'The submitted spend evidence has changed. Reload before deciding.',
                ]);
            }

            $resolution = $this->lockResolution($command['resolution_id'] ?? $approval->resolution_id);
            if ($resolution) {
                $parentEvidence['resolution'] = $this->resolutionEvidence($resolution);
            }

            $decidedAt = now();
            $evidenceVersion = (int) SpendApprovalDecision::query()
                ->where('spend_approval_id', $approval->id)
                ->max('evidence_version') + 1;

            SpendApprovalDecision::create([
                'spend_approval_id' => $approval->id,
                'evidence_version' => $evidenceVersion,
                'stable_key' => $command['decision_key'],
                'request_fingerprint' => $fingerprint,
                'submission_version' => $approval->submission_version,
                'content_digest' => $approval->content_digest,
                'outcome' => $outcome,
                'reason' => $command['decision_notes'] ?? null,
                'decided_by' => $lockedActor->id,
                'decided_at' => $decidedAt,
                'resolution_id' => $resolution?->id,
                'parent_evidence' => $parentEvidence,
            ]);

            $approval->fill([
                'status' => $outcome,
                'decided_by' => $lockedActor->id,
                'decided_at' => $decidedAt,
                'decision_notes' => $command['decision_notes'] ?? null,
                'resolution_id' => $resolution?->id ?? $approval->resolution_id,
                'version' => $approval->version + 1,
            ])->save();

            $this->audit("spend_approval.{$outcome}", $approval->id, [
                'amount' => $approval->amount,
                'resolution_id' => $approval->resolution_id,
                'evidence_version' => $evidenceVersion,
            ]);

            return $approval;
        });
    }

    public function appendAttachments(User $actor, int $approvalId, array $attachments): SpendApproval
    {
        Gate::forUser($actor)->authorize('requestAny', SpendApproval::class);
        $preflight = $this->resolveAccessibleApproval($actor, $approvalId);

        return DB::transaction(function () use ($actor, $approvalId, $attachments, $preflight): SpendApproval {
            $lockedActor = $this->lockActors([$actor->id, $preflight->requested_by])[$actor->id];
            Gate::forUser($lockedActor)->authorize('requestAny', SpendApproval::class);
            $accessibleSiteIds = $this->accessibleSiteIds($lockedActor);
            $approval = $this->lockApproval($approvalId, $accessibleSiteIds);
            Gate::forUser($lockedActor)->authorize('manageAttachments', $approval);
            $this->lockAndCanonicalizeParents($approval->only($this->parentFields()), $accessibleSiteIds);

            $existing = is_array($approval->attachments) ? $approval->attachments : [];
            $approval->update([
                'attachments' => [...$existing, ...$attachments],
                'version' => $approval->version + 1,
                'content_digest' => null,
            ]);

            $this->audit('spend_approval.attachment_added', $approval->id, [
                'count' => count($attachments),
                'version' => $approval->version,
            ]);

            return $approval;
        });
    }

    public function removeAttachment(User $actor, int $approvalId, string $attachmentId): array
    {
        Gate::forUser($actor)->authorize('requestAny', SpendApproval::class);
        $preflight = $this->resolveAccessibleApproval($actor, $approvalId);

        return DB::transaction(function () use ($actor, $approvalId, $attachmentId, $preflight): array {
            $lockedActor = $this->lockActors([$actor->id, $preflight->requested_by])[$actor->id];
            Gate::forUser($lockedActor)->authorize('requestAny', SpendApproval::class);
            $accessibleSiteIds = $this->accessibleSiteIds($lockedActor);
            $approval = $this->lockApproval($approvalId, $accessibleSiteIds);
            Gate::forUser($lockedActor)->authorize('manageAttachments', $approval);
            $this->lockAndCanonicalizeParents($approval->only($this->parentFields()), $accessibleSiteIds);

            $existing = is_array($approval->attachments) ? $approval->attachments : [];
            $target = collect($existing)->firstWhere('id', $attachmentId);
            abort_unless($target, 404, 'Attachment not found.');

            $approval->update([
                'attachments' => array_values(array_filter(
                    $existing,
                    fn (array $row) => ($row['id'] ?? null) !== $attachmentId,
                )),
                'version' => $approval->version + 1,
                'content_digest' => null,
            ]);

            $this->audit('spend_approval.attachment_removed', $approval->id, [
                'attachment_id' => $attachmentId,
                'original_name' => $target['original_name'] ?? null,
                'version' => $approval->version,
            ]);

            return [$approval, $target];
        });
    }

    private function lockApproval(int $approvalId, array $accessibleSiteIds): SpendApproval
    {
        return $this->scopedApprovalQueryForSiteIds($accessibleSiteIds)
            ->whereKey($approvalId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<int, User> */
    private function lockActors(array $ids): array
    {
        return User::query()
            ->whereKey(array_values(array_unique(array_filter($ids))))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function assertVersion(SpendApproval $approval, int $expectedVersion): void
    {
        if ((int) $approval->version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'expected_version' => 'This spend approval changed. Reload before continuing.',
            ]);
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function lockAndCanonicalizeParents(array $payload, array $accessibleSiteIds): array
    {
        // Dependants are locked before their canonical parents in the same
        // order for every command: cost centre -> Site, donor fund -> funding
        // stream, then budget line -> budget. Resolution is always last.
        $costCentre = $this->lockParent(FinCostCentre::class, $payload['cost_centre_id'] ?? null);
        $siteId = $payload['site_id'] ?? null;
        if ($costCentre?->site_id && $siteId && (int) $siteId !== (int) $costCentre->site_id) {
            $this->conceal();
        }
        $siteId = $siteId ?: $costCentre?->site_id;
        if (! $siteId || ! in_array((int) $siteId, $accessibleSiteIds, true)) {
            $this->conceal();
        }
        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey((int) $siteId)
            ->whereIn('id', $accessibleSiteIds)
            ->lockForUpdate()
            ->first();
        if (! $site) {
            $this->conceal();
        }

        $donorFund = $this->lockParent(FinDonorFund::class, $payload['donor_fund_id'] ?? null);
        $fundingStreamId = $payload['funding_stream_id'] ?? null;
        if ($donorFund?->funding_stream_id && $fundingStreamId && (int) $fundingStreamId !== (int) $donorFund->funding_stream_id) {
            $this->conceal();
        }
        $fundingStream = $this->lockParent(
            FinFundingStream::class,
            $fundingStreamId ?: $donorFund?->funding_stream_id,
        );

        $budgetLine = $this->lockParent(BudgetLineItem::class, $payload['budget_line_item_id'] ?? null);
        $budgetId = $payload['budget_id'] ?? null;
        if ($budgetLine && $budgetId && (int) $budgetId !== (int) $budgetLine->budget_id) {
            $this->conceal();
        }
        $budget = $this->lockParent(Budget::class, $budgetId ?: $budgetLine?->budget_id);

        [$sourceFields, $sourceEvidence] = $this->canonicalSourceEvidence(
            $payload,
            (int) $site->id,
            $accessibleSiteIds,
            true,
        );

        return [[
            ...$sourceFields,
            'site_id' => $site?->id,
            'cost_centre_id' => $costCentre?->id,
            'funding_stream_id' => $fundingStream?->id,
            'donor_fund_id' => $donorFund?->id,
            'budget_id' => $budget?->id,
            'budget_line_item_id' => $budgetLine?->id,
        ], [
            'source' => $sourceEvidence,
            'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
            'cost_centre' => $costCentre ? ['id' => $costCentre->id, 'code' => $costCentre->code, 'name' => $costCentre->name] : null,
            'funding_stream' => $fundingStream ? ['id' => $fundingStream->id, 'code' => $fundingStream->code, 'name' => $fundingStream->name] : null,
            'donor_fund' => $donorFund ? ['id' => $donorFund->id, 'fund_code' => $donorFund->fund_code, 'fund_name' => $donorFund->fund_name] : null,
            'budget' => $budget ? ['id' => $budget->id, 'fiscal_year' => $budget->fiscal_year, 'title' => $budget->title] : null,
            'budget_line' => $budgetLine ? ['id' => $budgetLine->id, 'description' => $budgetLine->description, 'account_code' => $budgetLine->account_code] : null,
        ]];
    }

    private function lockParent(string $model, mixed $id): ?object
    {
        if ($id === null || $id === '') {
            return null;
        }

        $parent = $model::query()->whereKey((int) $id)->lockForUpdate()->first();
        if (! $parent) {
            $this->conceal();
        }

        return $parent;
    }

    private function lockResolution(mixed $id): ?Resolution
    {
        return $this->lockParent(Resolution::class, $id);
    }

    /**
     * @param  array<int, int>  $accessibleSiteIds
     * @return array{0: array{source_type: ?string, source_id: ?int}, 1: ?array<string, mixed>}
     */
    private function canonicalSourceEvidence(
        array $payload,
        int $approvalSiteId,
        array $accessibleSiteIds,
        bool $lock,
    ): array {
        $sourceType = filled($payload['source_type'] ?? null) ? (string) $payload['source_type'] : null;
        $sourceId = filled($payload['source_id'] ?? null) ? (int) $payload['source_id'] : null;

        if ($sourceType === null && $sourceId === null) {
            return [['source_type' => null, 'source_id' => null], null];
        }
        if ($sourceType === null || ! $sourceId || ! in_array($sourceType, SpendApproval::SOURCE_TYPES, true)) {
            $this->conceal();
        }
        if (! in_array($approvalSiteId, $accessibleSiteIds, true)) {
            $this->conceal();
        }

        $query = $sourceType::query()->whereKey($sourceId);
        if (in_array($sourceType, [FinBill::class, FinPurchaseOrder::class], true)) {
            $query->whereNull('deleted_at');
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $source = $query->first();
        if (! $source) {
            $this->conceal();
        }

        $evidence = match ($sourceType) {
            FinBill::class => $this->billSourceEvidence($source, $approvalSiteId),
            FinPurchaseOrder::class => $this->purchaseOrderSourceEvidence($source, $approvalSiteId, $lock),
            FinPaymentRun::class => $this->paymentRunSourceEvidence($source, $approvalSiteId, $lock),
        };

        return [[
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ], $evidence];
    }

    private function billSourceEvidence(FinBill $bill, int $approvalSiteId): array
    {
        if (! $bill->site_id || (int) $bill->site_id !== $approvalSiteId) {
            $this->conceal();
        }

        return [
            'type' => FinBill::class,
            'id' => (int) $bill->id,
            'site_id' => (int) $bill->site_id,
            'reference' => $bill->bill_number,
            'status' => $bill->status,
            'total_amount' => number_format((float) $bill->total_amount, 2, '.', ''),
            'vendor_id' => (int) $bill->vendor_id,
            'purchase_order_id' => $bill->purchase_order_id ? (int) $bill->purchase_order_id : null,
        ];
    }

    private function purchaseOrderSourceEvidence(
        FinPurchaseOrder $purchaseOrder,
        int $approvalSiteId,
        bool $lock,
    ): array {
        $costCentreQuery = FinCostCentre::query()->whereKey($purchaseOrder->cost_centre_id);
        if ($lock) {
            $costCentreQuery->lockForUpdate();
        }
        $costCentre = $costCentreQuery->first();
        if (! $costCentre?->site_id || (int) $costCentre->site_id !== $approvalSiteId) {
            $this->conceal();
        }

        return [
            'type' => FinPurchaseOrder::class,
            'id' => (int) $purchaseOrder->id,
            'site_id' => (int) $costCentre->site_id,
            'reference' => $purchaseOrder->po_number,
            'status' => $purchaseOrder->status,
            'total_amount' => number_format((float) $purchaseOrder->total_amount, 2, '.', ''),
            'vendor_id' => (int) $purchaseOrder->vendor_id,
            'cost_centre_id' => (int) $costCentre->id,
        ];
    }

    private function paymentRunSourceEvidence(
        FinPaymentRun $paymentRun,
        int $approvalSiteId,
        bool $lock,
    ): array {
        $itemsQuery = FinPaymentRunItem::query()
            ->where('payment_run_id', $paymentRun->id)
            ->orderBy('id');
        if ($lock) {
            $itemsQuery->lockForUpdate();
        }
        $items = $itemsQuery->get(['id', 'site_id', 'bill_id', 'amount', 'status']);
        if ($items->isEmpty()
            || $items->count() !== (int) $paymentRun->item_count
            || $items->contains(fn (FinPaymentRunItem $item) => ! $item->site_id || (int) $item->site_id !== $approvalSiteId)) {
            $this->conceal();
        }

        return [
            'type' => FinPaymentRun::class,
            'id' => (int) $paymentRun->id,
            'site_id' => $approvalSiteId,
            'reference' => $paymentRun->run_number,
            'status' => $paymentRun->status,
            'total_amount' => number_format((float) $paymentRun->total_amount, 2, '.', ''),
            'item_count' => (int) $paymentRun->item_count,
            'items' => $items->map(fn (FinPaymentRunItem $item) => [
                'id' => (int) $item->id,
                'site_id' => (int) $item->site_id,
                'bill_id' => $item->bill_id ? (int) $item->bill_id : null,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
                'status' => $item->status,
            ])->all(),
        ];
    }

    private function accessibleSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds(
            $actor,
            UserSiteAccessService::GOVERNANCE_SPEND_SITE_BYPASS_PERMISSIONS,
        );
    }

    private function scopedApprovalQuery(User $actor): Builder
    {
        return $this->scopedApprovalQueryForSiteIds($this->accessibleSiteIds($actor));
    }

    private function scopedApprovalQueryForSiteIds(array $siteIds): Builder
    {
        $query = SpendApproval::query();
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $siteScope) use ($siteIds): void {
            $siteScope->whereIn('site_id', $siteIds)
                ->orWhere(function (Builder $legacyCostCentre) use ($siteIds): void {
                    $legacyCostCentre->whereNull('site_id')
                        ->whereIn('cost_centre_id', FinCostCentre::query()
                            ->whereIn('site_id', $siteIds)
                            ->select('id'));
                });
        });
    }

    private function applyCanonicalReadSourceScope(Builder $query): Builder
    {
        return $query->where(function (Builder $sourceScope): void {
            $sourceScope->where(function (Builder $standalone): void {
                $standalone->whereNull('source_type')->whereNull('source_id');
            })->orWhere(function (Builder $billSource): void {
                $billSource->where('source_type', FinBill::class)
                    ->whereNotNull('source_id')
                    ->whereExists(function ($bill): void {
                        $bill->selectRaw('1')
                            ->from('fin_bills')
                            ->whereColumn('fin_bills.id', 'spend_approvals.source_id')
                            ->whereColumn('fin_bills.site_id', 'spend_approvals.site_id')
                            ->whereNull('fin_bills.deleted_at');
                    });
            })->orWhere(function (Builder $purchaseOrderSource): void {
                $purchaseOrderSource->where('source_type', FinPurchaseOrder::class)
                    ->whereNotNull('source_id')
                    ->whereExists(function ($purchaseOrder): void {
                        $purchaseOrder->selectRaw('1')
                            ->from('fin_purchase_orders')
                            ->join('fin_cost_centres', 'fin_cost_centres.id', '=', 'fin_purchase_orders.cost_centre_id')
                            ->whereColumn('fin_purchase_orders.id', 'spend_approvals.source_id')
                            ->whereColumn('fin_cost_centres.site_id', 'spend_approvals.site_id')
                            ->whereNull('fin_purchase_orders.deleted_at');
                    });
            })->orWhere(function (Builder $paymentRunSource): void {
                $paymentRunSource->where('source_type', FinPaymentRun::class)
                    ->whereNotNull('source_id')
                    ->whereExists(function ($paymentRun): void {
                        $paymentRun->selectRaw('1')
                            ->from('fin_payment_runs')
                            ->whereColumn('fin_payment_runs.id', 'spend_approvals.source_id')
                            ->whereRaw('(SELECT COUNT(*) FROM fin_payment_run_items WHERE fin_payment_run_items.payment_run_id = fin_payment_runs.id) = fin_payment_runs.item_count')
                            ->whereExists(function ($item): void {
                                $item->selectRaw('1')
                                    ->from('fin_payment_run_items')
                                    ->whereColumn('fin_payment_run_items.payment_run_id', 'fin_payment_runs.id');
                            })
                            ->whereNotExists(function ($mismatchedItem): void {
                                $mismatchedItem->selectRaw('1')
                                    ->from('fin_payment_run_items')
                                    ->whereColumn('fin_payment_run_items.payment_run_id', 'fin_payment_runs.id')
                                    ->where(function ($siteMismatch): void {
                                        $siteMismatch->whereNull('fin_payment_run_items.site_id')
                                            ->orWhereColumn('fin_payment_run_items.site_id', '!=', 'spend_approvals.site_id');
                                    });
                            });
                    });
            });
        });
    }

    private function conceal(): never
    {
        throw (new ModelNotFoundException)->setModel(SpendApproval::class);
    }

    private function decisionFingerprint(int $actorId, string $outcome, array $command): string
    {
        return hash('sha256', json_encode([
            'actor_id' => $actorId,
            'outcome' => $outcome,
            'decision_notes' => $command['decision_notes'] ?? null,
            'resolution_id' => isset($command['resolution_id']) ? (int) $command['resolution_id'] : null,
            'expected_version' => (int) $command['expected_version'],
            'expected_content_digest' => (string) $command['expected_content_digest'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function validateDecisionCommand(string $outcome, array $command): void
    {
        if (! isset($command['decision_key']) || ! Str::isUuid((string) $command['decision_key'])) {
            throw ValidationException::withMessages(['decision_key' => 'A valid decision key is required.']);
        }
        if (! isset($command['expected_version']) || (int) $command['expected_version'] < 1) {
            throw ValidationException::withMessages(['expected_version' => 'A valid expected version is required.']);
        }
        if (! isset($command['expected_content_digest'])
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $command['expected_content_digest']) !== 1) {
            throw ValidationException::withMessages([
                'expected_content_digest' => 'A valid submitted-content digest is required.',
            ]);
        }
        if (trim((string) ($command['decision_notes'] ?? '')) === '') {
            throw ValidationException::withMessages(['decision_notes' => 'A decision reason is required.']);
        }
    }

    private function resolutionEvidence(Resolution $resolution): array
    {
        return [
            'id' => $resolution->id,
            'reference' => $resolution->resolution_reference,
            'title' => $resolution->title,
            'status' => $resolution->status,
            'outcome' => $resolution->outcome,
        ];
    }

    private function parentFields(): array
    {
        return ['source_type', 'source_id', 'site_id', 'cost_centre_id', 'funding_stream_id', 'donor_fund_id', 'budget_id', 'budget_line_item_id'];
    }

    private function requiresBoard(array $payload): bool
    {
        return (float) $payload['amount'] >= SpendApproval::thresholdFor($payload['category']);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        DB::table('spend_approval_reference_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
        ]);
        $sequence = DB::table('spend_approval_reference_sequences')
            ->where('year', $year)
            ->lockForUpdate()
            ->first();
        $next = (int) $sequence->last_number + 1;
        DB::table('spend_approval_reference_sequences')
            ->where('year', $year)
            ->update(['last_number' => $next]);

        return sprintf('SA-%s-%04d', $year, $next);
    }

    protected function audit(string $action, int $approvalId, array $metadata = []): void
    {
        GovernanceAuditService::log($action, 'SpendApproval', $approvalId, $metadata);
    }
}
