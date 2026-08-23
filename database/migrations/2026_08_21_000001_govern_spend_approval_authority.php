<?php

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPurchaseOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertLegacyRowsCanBeGoverned();

        Schema::table('spend_approvals', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->foreignId('submitted_by')->nullable()->after('requested_by')->constrained('users');
            $table->unsignedInteger('submission_version')->nullable()->after('submitted_at');
            $table->char('content_digest', 64)->nullable()->after('submission_version');
        });

        Schema::create('spend_approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_approval_id')->constrained('spend_approvals')->restrictOnDelete();
            $table->unsignedInteger('evidence_version');
            $table->uuid('stable_key');
            $table->char('request_fingerprint', 64);
            $table->unsignedInteger('submission_version');
            $table->char('content_digest', 64);
            $table->string('outcome', 16);
            $table->text('reason')->nullable();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->foreignId('resolution_id')->nullable()->constrained('resolutions')->restrictOnDelete();
            $table->json('parent_evidence');

            $table->unique(['spend_approval_id', 'stable_key'], 'spend_decision_stable_key_unique');
            $table->unique(['spend_approval_id', 'evidence_version'], 'spend_decision_evidence_version_unique');
        });

        Schema::create('spend_approval_reference_sequences', function (Blueprint $table) {
            $table->string('year', 4)->primary();
            $table->unsignedInteger('last_number');
        });

        $referenceSequences = [];
        foreach (DB::table('spend_approvals')->orderBy('id')->pluck('reference') as $reference) {
            if (preg_match('/\ASA-(\d{4})-(\d+)\z/', (string) $reference, $matches) !== 1) {
                continue;
            }
            $referenceSequences[$matches[1]] = max(
                $referenceSequences[$matches[1]] ?? 0,
                (int) $matches[2],
            );
        }
        foreach ($referenceSequences as $year => $lastNumber) {
            DB::table('spend_approval_reference_sequences')->insert([
                'year' => $year,
                'last_number' => $lastNumber,
            ]);
        }

        DB::table('spend_approvals')
            ->whereIn('status', ['submitted', 'approved', 'rejected'])
            ->orderBy('id')
            ->chunkById(100, function ($approvals): void {
                foreach ($approvals as $approval) {
                    $parents = $this->legacyCanonicalParents($approval);
                    $attachments = $this->legacyAttachmentEvidence($approval);
                    $sourceEvidence = $this->legacySourceEvidence($approval);
                    $digest = hash('sha256', json_encode([
                        'reference' => $approval->reference,
                        'title' => $approval->title,
                        'description' => $approval->description,
                        'category' => $approval->category,
                        'amount' => number_format((float) $approval->amount, 2, '.', ''),
                        'currency' => $approval->currency,
                        'source_type' => $approval->source_type,
                        'source_id' => $approval->source_id,
                        'source' => $sourceEvidence,
                        'site_id' => $approval->site_id,
                        'cost_centre_id' => $parents['cost_centre_id'],
                        'funding_stream_id' => $parents['funding_stream_id'],
                        'donor_fund_id' => $parents['donor_fund_id'],
                        'budget_id' => $parents['budget_id'],
                        'budget_line_item_id' => $parents['budget_line_item_id'],
                        'requires_board' => (bool) $approval->requires_board,
                        'valid_until' => $approval->valid_until,
                        'attachments' => $attachments,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                    DB::table('spend_approvals')->where('id', $approval->id)->update([
                        'submitted_by' => $approval->requested_by,
                        'submission_version' => 1,
                        'content_digest' => $digest,
                        'version' => in_array($approval->status, ['approved', 'rejected'], true) ? 2 : 1,
                        'funding_stream_id' => $parents['funding_stream_id'],
                        'budget_id' => $parents['budget_id'],
                    ]);

                    if (in_array($approval->status, ['approved', 'rejected'], true)) {
                        DB::table('spend_approval_decisions')->insert([
                            'spend_approval_id' => $approval->id,
                            'evidence_version' => 1,
                            'stable_key' => (string) Str::uuid(),
                            'request_fingerprint' => hash('sha256', "legacy-spend-decision:{$approval->id}"),
                            'submission_version' => 1,
                            'content_digest' => $digest,
                            'outcome' => $approval->status,
                            'reason' => $approval->decision_notes,
                            'decided_by' => $approval->decided_by,
                            'decided_at' => $approval->decided_at,
                            'resolution_id' => $parents['resolution_id'],
                            'parent_evidence' => json_encode([
                                'migrated_legacy' => true,
                                'source' => $sourceEvidence,
                                'site_id' => $approval->site_id,
                                'cost_centre_id' => $parents['cost_centre_id'],
                                'funding_stream_id' => $parents['funding_stream_id'],
                                'donor_fund_id' => $parents['donor_fund_id'],
                                'budget_id' => $parents['budget_id'],
                                'budget_line_item_id' => $parents['budget_line_item_id'],
                                'resolution_id' => $parents['resolution_id'],
                            ], JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }

    private function assertLegacyRowsCanBeGoverned(): void
    {
        $malformedSubmissionId = DB::table('spend_approvals')
            ->whereIn('status', ['submitted', 'approved', 'rejected'])
            ->where(function ($query): void {
                $query->whereNull('submitted_at')->orWhereNull('requested_by');
            })
            ->orderBy('id')
            ->value('id');
        if ($malformedSubmissionId) {
            throw new RuntimeException("Spend approval {$malformedSubmissionId} has submitted status without submission provenance.");
        }

        $malformedTerminalId = DB::table('spend_approvals')
            ->whereIn('status', ['approved', 'rejected'])
            ->where(function ($query): void {
                $query->whereNull('decided_by')->orWhereNull('decided_at');
            })
            ->orderBy('id')
            ->value('id');
        if ($malformedTerminalId) {
            throw new RuntimeException("Spend approval {$malformedTerminalId} has a terminal status without decision provenance.");
        }

        $missingDecisionReasonId = DB::table('spend_approvals')
            ->whereIn('status', ['approved', 'rejected'])
            ->where(function ($query): void {
                $query->whereNull('decision_notes')->orWhereRaw("TRIM(decision_notes) = ''");
            })
            ->orderBy('id')
            ->value('id');
        if ($missingDecisionReasonId) {
            throw new RuntimeException("Spend approval {$missingDecisionReasonId} has a terminal status without a meaningful decision reason.");
        }

        $selfDecisionId = DB::table('spend_approvals')
            ->whereIn('status', ['approved', 'rejected'])
            ->whereColumn('requested_by', 'decided_by')
            ->orderBy('id')
            ->value('id');
        if ($selfDecisionId) {
            throw new RuntimeException("Spend approval {$selfDecisionId} was decided by its requester.");
        }

        $invalidSiteId = DB::table('spend_approvals as approvals')
            ->leftJoin('sites', 'sites.id', '=', 'approvals.site_id')
            ->where(function ($query): void {
                $query->whereNull('approvals.site_id')
                    ->orWhereNull('sites.id')
                    ->orWhere('sites.is_active', false)
                    ->orWhere('sites.archived', true)
                    ->orWhereNotNull('sites.archived_at')
                    ->orWhereNotNull('sites.deleted_at');
            })
            ->orderBy('approvals.id')
            ->value('approvals.id');
        if ($invalidSiteId) {
            throw new RuntimeException("Spend approval {$invalidSiteId} has no current canonical Site.");
        }

        foreach (DB::table('spend_approvals')->orderBy('id')->get() as $approval) {
            $this->legacyCanonicalParents($approval);
            $this->legacySourceEvidence($approval);
            $this->legacyAttachmentEvidence($approval);
        }
    }

    /** @return list<array{id: mixed, original_name: mixed, mime_type: mixed, size_bytes: mixed, sha256: mixed}> */
    private function legacyAttachmentEvidence(object $approval): array
    {
        try {
            $attachments = json_decode($approval->attachments ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Spend approval {$approval->id} has malformed attachment evidence.",
                previous: $exception,
            );
        }

        if (! is_array($attachments)
            || ! array_is_list($attachments)
            || collect($attachments)->contains(fn (mixed $attachment): bool => ! is_array($attachment))) {
            throw new RuntimeException("Spend approval {$approval->id} has malformed attachment evidence.");
        }

        return collect($attachments)
            ->map(fn (array $attachment) => [
                'id' => $attachment['id'] ?? null,
                'original_name' => $attachment['original_name'] ?? null,
                'mime_type' => $attachment['mime_type'] ?? null,
                'size_bytes' => $attachment['size_bytes'] ?? null,
                'sha256' => $attachment['sha256'] ?? null,
            ])
            ->sortBy('id')
            ->values()
            ->all();
    }

    /**
     * Reproduce the command owner's parent-equality rules before any DDL. An
     * omitted parent that is unambiguously implied by its child is normalized
     * during backfill; missing, deleted, or conflicting identities stop the
     * migration before legacy evidence can be treated as canonical.
     *
     * @return array{cost_centre_id: ?int, funding_stream_id: ?int, donor_fund_id: ?int, budget_id: ?int, budget_line_item_id: ?int, resolution_id: ?int}
     */
    private function legacyCanonicalParents(object $approval): array
    {
        $costCentre = $approval->cost_centre_id
            ? DB::table('fin_cost_centres')->where('id', $approval->cost_centre_id)->first(['id', 'site_id'])
            : null;
        if ($approval->cost_centre_id && ! $costCentre) {
            $this->invalidLegacyParent($approval, 'cost centre');
        }
        if ($costCentre?->site_id && (int) $costCentre->site_id !== (int) $approval->site_id) {
            $this->invalidLegacyParent($approval, 'cost centre Site');
        }

        $fundingStreamId = $approval->funding_stream_id ? (int) $approval->funding_stream_id : null;
        $donorFund = $approval->donor_fund_id
            ? DB::table('fin_donor_funds')
                ->where('id', $approval->donor_fund_id)
                ->whereNull('deleted_at')
                ->first(['id', 'funding_stream_id'])
            : null;
        if ($approval->donor_fund_id && ! $donorFund) {
            $this->invalidLegacyParent($approval, 'donor fund');
        }
        if ($donorFund?->funding_stream_id) {
            if ($fundingStreamId !== null && $fundingStreamId !== (int) $donorFund->funding_stream_id) {
                $this->invalidLegacyParent($approval, 'donor funding stream');
            }
            $fundingStreamId = (int) $donorFund->funding_stream_id;
        }
        if ($fundingStreamId !== null
            && ! DB::table('fin_funding_streams')->where('id', $fundingStreamId)->exists()) {
            $this->invalidLegacyParent($approval, 'funding stream');
        }

        $budgetId = $approval->budget_id ? (int) $approval->budget_id : null;
        $budgetLine = $approval->budget_line_item_id
            ? DB::table('budget_line_items')->where('id', $approval->budget_line_item_id)->first(['id', 'budget_id'])
            : null;
        if ($approval->budget_line_item_id && ! $budgetLine) {
            $this->invalidLegacyParent($approval, 'budget line');
        }
        if ($budgetLine) {
            if ($budgetId !== null && $budgetId !== (int) $budgetLine->budget_id) {
                $this->invalidLegacyParent($approval, 'budget line parent');
            }
            $budgetId = (int) $budgetLine->budget_id;
        }
        if ($budgetId !== null
            && ! DB::table('budgets')->where('id', $budgetId)->whereNull('deleted_at')->exists()) {
            $this->invalidLegacyParent($approval, 'budget');
        }

        $resolutionId = $approval->resolution_id ? (int) $approval->resolution_id : null;
        if ($resolutionId !== null
            && ! DB::table('resolutions')->where('id', $resolutionId)->whereNull('deleted_at')->exists()) {
            $this->invalidLegacyParent($approval, 'resolution');
        }

        return [
            'cost_centre_id' => $costCentre ? (int) $costCentre->id : null,
            'funding_stream_id' => $fundingStreamId,
            'donor_fund_id' => $donorFund ? (int) $donorFund->id : null,
            'budget_id' => $budgetId,
            'budget_line_item_id' => $budgetLine ? (int) $budgetLine->id : null,
            'resolution_id' => $resolutionId,
        ];
    }

    private function invalidLegacyParent(object $approval, string $relationship): never
    {
        throw new RuntimeException(
            "Spend approval {$approval->id} has a missing or mismatched canonical {$relationship} relationship."
        );
    }

    private function legacySourceEvidence(object $approval): ?array
    {
        $sourceType = filled($approval->source_type) ? (string) $approval->source_type : null;
        $sourceId = filled($approval->source_id) ? (int) $approval->source_id : null;
        if ($sourceType === null && $sourceId === null) {
            return null;
        }
        if ($sourceType === null || ! $sourceId || ! in_array($sourceType, [
            FinBill::class,
            FinPurchaseOrder::class,
            FinPaymentRun::class,
        ], true)) {
            throw new RuntimeException("Spend approval {$approval->id} has an invalid Finance source identity.");
        }

        if ($sourceType === FinBill::class) {
            $bill = DB::table('fin_bills')->where('id', $sourceId)->whereNull('deleted_at')->first();
            if (! $bill || ! $bill->site_id || (int) $bill->site_id !== (int) $approval->site_id) {
                throw new RuntimeException("Spend approval {$approval->id} has a missing or Site-mismatched Finance source.");
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

        if ($sourceType === FinPurchaseOrder::class) {
            $purchaseOrder = DB::table('fin_purchase_orders')->where('id', $sourceId)->whereNull('deleted_at')->first();
            $costCentre = $purchaseOrder
                ? DB::table('fin_cost_centres')->where('id', $purchaseOrder->cost_centre_id)->first()
                : null;
            if (! $purchaseOrder || ! $costCentre?->site_id || (int) $costCentre->site_id !== (int) $approval->site_id) {
                throw new RuntimeException("Spend approval {$approval->id} has a missing or Site-mismatched Finance source.");
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

        $paymentRun = DB::table('fin_payment_runs')->where('id', $sourceId)->first();
        $items = DB::table('fin_payment_run_items')
            ->where('payment_run_id', $sourceId)
            ->orderBy('id')
            ->get(['id', 'site_id', 'bill_id', 'amount', 'status']);
        if (! $paymentRun
            || $items->isEmpty()
            || $items->count() !== (int) $paymentRun->item_count
            || $items->contains(fn (object $item) => ! $item->site_id || (int) $item->site_id !== (int) $approval->site_id)) {
            throw new RuntimeException("Spend approval {$approval->id} has a missing or Site-mismatched Finance source.");
        }

        return [
            'type' => FinPaymentRun::class,
            'id' => (int) $paymentRun->id,
            'site_id' => (int) $approval->site_id,
            'reference' => $paymentRun->run_number,
            'status' => $paymentRun->status,
            'total_amount' => number_format((float) $paymentRun->total_amount, 2, '.', ''),
            'item_count' => (int) $paymentRun->item_count,
            'items' => $items->map(fn (object $item) => [
                'id' => (int) $item->id,
                'site_id' => (int) $item->site_id,
                'bill_id' => $item->bill_id ? (int) $item->bill_id : null,
                'amount' => number_format((float) $item->amount, 2, '.', ''),
                'status' => $item->status,
            ])->all(),
        ];
    }

    public function down(): void
    {
        Schema::dropIfExists('spend_approval_reference_sequences');
        Schema::dropIfExists('spend_approval_decisions');

        Schema::table('spend_approvals', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropColumn(['version', 'submitted_by', 'submission_version', 'content_digest']);
        });
    }
};
