<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDepreciation;
use App\Domain\Finance\Models\FinFixedAssetDisposal;
use App\Domain\Finance\Models\FinJournal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class FixedAssetService
{
    public function __construct(
        protected JournalPostingService $journalPostingService,
    ) {}

    /**
     * Create a new fixed asset, optionally posting a GL journal for the acquisition.
     */
    public function createAsset(?int $orgId, array $data): FinFixedAsset
    {
        return DB::transaction(function () use ($orgId, $data) {
            if (! empty($data['gl_asset_account_id'])) {
                $this->journalPostingService->lockJournalSequence($orgId);
            }

            $asset = FinFixedAsset::create([
                'organization_id' => $orgId,
                'asset_name' => $data['asset_name'],
                'asset_tag' => $data['asset_tag'] ?? null,
                'category' => $data['category'],
                'purchase_date' => $data['purchase_date'],
                'purchase_cost' => $data['purchase_cost'],
                'residual_value' => $data['residual_value'] ?? 0,
                'useful_life_months' => $data['useful_life_months'],
                'depreciation_method' => $data['depreciation_method'],
                'accumulated_depreciation' => 0,
                'gl_asset_account_id' => $data['gl_asset_account_id'] ?? null,
                'gl_depreciation_account_id' => $data['gl_depreciation_account_id'] ?? null,
                'gl_expense_account_id' => $data['gl_expense_account_id'] ?? null,
                'status' => 'active',
                'linked_asset_id' => $data['linked_asset_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            // Post acquisition journal if GL asset account is specified
            if (! empty($data['gl_asset_account_id'])) {
                $this->postAcquisitionJournal($orgId, $asset);
            }

            return $asset;
        });
    }

    /**
     * Update an existing fixed asset.
     * Cannot change purchase_cost if depreciations exist.
     */
    public function updateAsset(FinFixedAsset $asset, array $data): FinFixedAsset
    {
        $assetId = (int) $asset->getKey();
        $organizationId = $asset->organization_id;

        return DB::transaction(function () use ($assetId, $organizationId, $data): FinFixedAsset {
            $asset = FinFixedAsset::query()
                ->whereKey($assetId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($asset->status === 'disposed') {
                throw new InvalidArgumentException('A disposed fixed asset cannot be changed.');
            }

            $hasDepreciations = $asset->depreciations()->exists();

            if ($hasDepreciations && isset($data['purchase_cost']) && bccomp((string) $data['purchase_cost'], (string) $asset->purchase_cost, 2) !== 0) {
                throw new InvalidArgumentException('Cannot change purchase cost after depreciation has been recorded.');
            }

            $fillable = [
                'asset_name', 'asset_tag', 'category', 'purchase_date', 'purchase_cost',
                'residual_value', 'useful_life_months', 'depreciation_method',
                'gl_asset_account_id', 'gl_depreciation_account_id', 'gl_expense_account_id',
                'linked_asset_id', 'notes',
            ];

            $updateData = array_intersect_key($data, array_flip($fillable));

            // If depreciations exist, prevent changing locked fields
            if ($hasDepreciations) {
                unset($updateData['purchase_cost'], $updateData['purchase_date']);
            }

            $asset->update($updateData);

            return $asset->refresh();
        });
    }

    /**
     * Run monthly depreciation for all active assets in an organisation.
     */
    public function runDepreciation(?int $orgId, string $depreciationDate): array
    {
        if ($orgId === null || $orgId < 1) {
            throw new InvalidArgumentException('An organisation is required to run fixed-asset depreciation.');
        }

        $period = Carbon::parse($depreciationDate)->startOfMonth();
        $processed = [];

        $assetIds = FinFixedAsset::forOrganization($orgId)
            ->where(function ($query) use ($period): void {
                $query->where('status', 'active')
                    ->orWhereHas('depreciations', fn ($depreciations) => $depreciations
                        ->where('depreciation_date', $period->toDateString()));
            })
            ->orderBy('id')
            ->pluck('id');

        foreach ($assetIds as $assetId) {
            $result = DB::transaction(function () use ($orgId, $assetId, $period): ?array {
                $this->journalPostingService->lockJournalSequence($orgId);

                $asset = FinFixedAsset::forOrganization($orgId)
                    ->whereKey($assetId)
                    ->lockForUpdate()
                    ->first();

                if (! $asset) {
                    return null;
                }

                $existing = FinFixedAssetDepreciation::query()
                    ->where('fixed_asset_id', $asset->id)
                    ->where('depreciation_date', $period->toDateString())
                    ->first();

                if ($existing) {
                    return $this->depreciationResult($asset, $existing, true);
                }

                if ($asset->status !== 'active') {
                    return null;
                }

                $depreciableAmount = (float) $asset->purchase_cost - (float) $asset->residual_value;
                $accumulated = (float) $asset->accumulated_depreciation;

                if ($accumulated >= $depreciableAmount) {
                    return null;
                }

                $monthlyAmount = $this->calculateMonthlyDepreciation($asset);
                if ($monthlyAmount <= 0) {
                    return null;
                }

                $remaining = $depreciableAmount - $accumulated;
                $monthlyAmount = round(min($monthlyAmount, $remaining), 2);
                $newAccumulated = round($accumulated + $monthlyAmount, 2);
                $bookValueAfter = round((float) $asset->purchase_cost - $newAccumulated, 2);

                // This row is the durable asset-period claim. The asset lock
                // serializes compliant workers and the database unique key is
                // the final defence against any non-compliant writer.
                $depreciation = FinFixedAssetDepreciation::create([
                    'fixed_asset_id' => $asset->id,
                    'depreciation_date' => $period->toDateString(),
                    'amount' => $monthlyAmount,
                    'accumulated_total' => $newAccumulated,
                    'book_value_after' => $bookValueAfter,
                    'journal_id' => null,
                ]);

                $journalId = null;

                if ($asset->gl_expense_account_id || $asset->gl_depreciation_account_id) {
                    $expenseAccountId = $asset->gl_expense_account_id
                        ?? $this->getDefaultAccountId($orgId, '8000');
                    $accumAccountId = $asset->gl_depreciation_account_id
                        ?? $this->getDefaultAccountId($orgId, '1590');

                    if ($expenseAccountId && $accumAccountId) {
                        $journal = $this->journalPostingService->createAndPost($orgId, [
                            'journal_date' => $period->toDateString(),
                            'type' => 'standard',
                            'reference' => "DEP-{$asset->asset_tag}-{$period->format('Y-m')}",
                            'description' => "Monthly depreciation: {$asset->asset_name} ({$period->format('M Y')})",
                            'source_type' => FinFixedAssetDepreciation::class,
                            'source_id' => $depreciation->id,
                            'lines' => [
                                [
                                    'account_id' => $expenseAccountId,
                                    'description' => "Depreciation expense: {$asset->asset_name}",
                                    'debit' => $monthlyAmount,
                                    'credit' => 0,
                                ],
                                [
                                    'account_id' => $accumAccountId,
                                    'description' => "Accumulated depreciation: {$asset->asset_name}",
                                    'debit' => 0,
                                    'credit' => $monthlyAmount,
                                ],
                            ],
                        ]);
                        $journalId = $journal->id;
                    }
                }

                if ($journalId !== null) {
                    $depreciation->update(['journal_id' => $journalId]);
                }

                $updateData = ['accumulated_depreciation' => $newAccumulated];

                if ($newAccumulated >= $depreciableAmount) {
                    $updateData['status'] = 'fully_depreciated';
                }

                $asset->update($updateData);

                return $this->depreciationResult($asset, $depreciation->refresh(), false);
            });

            if ($result !== null) {
                $processed[] = $result;
            }
        }

        return $processed;
    }

    /**
     * Reverse one posted depreciation without erasing its execution record.
     */
    public function reverseDepreciation(
        FinFixedAssetDepreciation $depreciation,
        ?string $reason = null,
    ): FinJournal {
        return DB::transaction(function () use ($depreciation, $reason): FinJournal {
            $locator = FinFixedAssetDepreciation::query()
                ->join('fin_fixed_assets', 'fin_fixed_assets.id', '=', 'fin_fixed_asset_depreciations.fixed_asset_id')
                ->where('fin_fixed_asset_depreciations.id', $depreciation->id)
                ->select([
                    'fin_fixed_asset_depreciations.fixed_asset_id',
                    'fin_fixed_assets.organization_id',
                ])
                ->first();

            if (! $locator) {
                throw new RuntimeException('The fixed-asset depreciation execution no longer exists.');
            }

            $this->journalPostingService->lockJournalSequence((int) $locator->organization_id);

            $asset = FinFixedAsset::withTrashed()
                ->whereKey($locator->fixed_asset_id)
                ->where('organization_id', $locator->organization_id)
                ->lockForUpdate()
                ->firstOrFail();
            $depreciation = FinFixedAssetDepreciation::query()
                ->whereKey($depreciation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $depreciation->fixed_asset_id !== (int) $asset->id) {
                throw new RuntimeException('The depreciation execution has conflicting fixed-asset ownership.');
            }
            if ($depreciation->journal_id === null) {
                throw new InvalidArgumentException('This depreciation execution has no posted journal to reverse.');
            }

            $journal = FinJournal::query()->findOrFail($depreciation->journal_id);
            $this->assertDepreciationJournalLineage($depreciation, $journal);

            if ($depreciation->reversal_journal_id !== null) {
                $reversal = FinJournal::query()->findOrFail($depreciation->reversal_journal_id);
                if ((int) $journal->reversed_by_journal_id !== (int) $reversal->id
                    || (int) $reversal->reversal_of_journal_id !== (int) $journal->id) {
                    throw new RuntimeException('The depreciation execution has conflicting reversal lineage.');
                }
                $this->assertDepreciationJournalLineage($depreciation, $reversal);

                return $reversal;
            }

            if ($journal->reversed_by_journal_id !== null) {
                throw new RuntimeException('The depreciation journal was reversed without updating its execution record.');
            }
            if (bccomp((string) $asset->accumulated_depreciation, (string) $depreciation->amount, 2) < 0) {
                throw new RuntimeException('The fixed-asset balance is lower than the depreciation being reversed.');
            }

            $reversal = $this->journalPostingService->reverse($journal, $reason, [
                'source_type' => FinFixedAssetDepreciation::class,
                'source_id' => $depreciation->id,
            ]);

            $newAccumulated = bcsub(
                (string) $asset->accumulated_depreciation,
                (string) $depreciation->amount,
                2,
            );
            $assetUpdate = ['accumulated_depreciation' => $newAccumulated];
            if ($asset->status === 'fully_depreciated') {
                $assetUpdate['status'] = 'active';
            }

            $asset->update($assetUpdate);
            $depreciation->update(['reversal_journal_id' => $reversal->id]);

            return $reversal;
        });
    }

    private function depreciationResult(
        FinFixedAsset $asset,
        FinFixedAssetDepreciation $depreciation,
        bool $replayed,
    ): array {
        $depreciableAmount = (float) $asset->purchase_cost - (float) $asset->residual_value;

        return [
            'asset_id' => $asset->id,
            'asset_name' => $asset->asset_name,
            'depreciation_id' => $depreciation->id,
            'journal_id' => $depreciation->journal_id,
            'period' => $depreciation->depreciation_date->format('Y-m'),
            'amount' => (float) $depreciation->amount,
            'new_accumulated' => (float) $depreciation->accumulated_total,
            'book_value' => (float) $depreciation->book_value_after,
            'status' => (float) $depreciation->accumulated_total >= $depreciableAmount
                ? 'fully_depreciated'
                : 'active',
            'replayed' => $replayed,
            'reversed' => $depreciation->reversal_journal_id !== null,
        ];
    }

    private function assertDepreciationJournalLineage(
        FinFixedAssetDepreciation $depreciation,
        FinJournal $journal,
    ): void {
        if ($journal->source_type !== FinFixedAssetDepreciation::class
            || (int) $journal->source_id !== (int) $depreciation->id) {
            throw new RuntimeException('The depreciation journal has conflicting execution lineage.');
        }
    }

    /**
     * Dispose of an asset, recording the gain/loss on disposal.
     */
    public function disposeAsset(FinFixedAsset $asset, array $data): FinFixedAsset
    {
        $assetId = (int) $asset->getKey();
        $organizationId = $asset->organization_id === null
            ? null
            : (int) $asset->organization_id;
        if ($organizationId === null || $organizationId < 1) {
            throw new InvalidArgumentException('An organisation is required to dispose of a fixed asset.');
        }

        if (! array_key_exists('disposed_date', $data)
            || ! array_key_exists('disposal_proceeds', $data)
            || ! is_numeric($data['disposal_proceeds'])) {
            throw new InvalidArgumentException('A disposal date and numeric proceeds are required.');
        }

        $disposedDate = Carbon::parse((string) $data['disposed_date'])->toDateString();
        $rawProceeds = (float) $data['disposal_proceeds'];
        if (! is_finite($rawProceeds) || $rawProceeds < 0) {
            throw new InvalidArgumentException('Disposal proceeds cannot be negative.');
        }
        $proceeds = number_format($rawProceeds, 2, '.', '');
        $requestHash = FinFixedAssetDisposal::requestHash($disposedDate, $proceeds);

        return DB::transaction(function () use (
            $assetId,
            $organizationId,
            $disposedDate,
            $proceeds,
            $requestHash,
        ): FinFixedAsset {
            // LOCK ORDER: shared organisation journal-sequence mutex, then the
            // canonical fixed asset. The mutex is unconditional so no-GL
            // disposals cannot invert the order against a journalled operation.
            $this->journalPostingService->lockJournalSequence($organizationId);

            $asset = FinFixedAsset::forOrganization($organizationId)
                ->whereKey($assetId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = FinFixedAssetDisposal::query()
                ->where('fixed_asset_id', $asset->id)
                ->where('occurrence_type', FinFixedAssetDisposal::OCCURRENCE_TYPE)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->resolveDisposalReplay($asset, $existing, $requestHash);
            }

            if ($asset->status === 'disposed') {
                throw new RuntimeException(
                    'The fixed asset has terminal disposal state without canonical disposal lineage. '
                    .'Finance review is required.',
                );
            }
            if (! in_array($asset->status, ['active', 'fully_depreciated'], true)) {
                throw new InvalidArgumentException(
                    "Fixed asset status '{$asset->status}' cannot transition to disposed.",
                );
            }

            if ($asset->purchase_date !== null
                && $disposedDate < $asset->purchase_date->toDateString()) {
                throw new InvalidArgumentException('A fixed asset cannot be disposed before its purchase date.');
            }

            $purchaseCost = bcadd((string) $asset->purchase_cost, '0', 2);
            $accumulated = bcadd((string) $asset->accumulated_depreciation, '0', 2);
            if (bccomp($purchaseCost, '0.00', 2) < 0
                || bccomp($accumulated, '0.00', 2) < 0
                || bccomp($accumulated, $purchaseCost, 2) > 0) {
                throw new RuntimeException(
                    'The fixed asset has invalid cost or accumulated-depreciation values. Finance review is required.',
                );
            }
            $bookValue = bcsub($purchaseCost, $accumulated, 2);
            $gainLoss = bcsub($proceeds, $bookValue, 2);
            $postingMode = $asset->gl_asset_account_id
                ? FinFixedAssetDisposal::POSTING_MODE_JOURNAL
                : FinFixedAssetDisposal::POSTING_MODE_NO_GL;

            // This row is the durable one-disposal occurrence and the journal's
            // typed source. It, its journal link and terminal asset state commit
            // or roll back as one transaction.
            $disposal = FinFixedAssetDisposal::query()->create([
                'fixed_asset_id' => $asset->id,
                'occurrence_type' => FinFixedAssetDisposal::OCCURRENCE_TYPE,
                'posting_mode' => $postingMode,
                'disposed_date' => $disposedDate,
                'purchase_cost' => $purchaseCost,
                'accumulated_depreciation' => $accumulated,
                'book_value' => $bookValue,
                'disposal_proceeds' => $proceeds,
                'gain_loss' => $gainLoss,
                'request_hash' => $requestHash,
                'journal_digest' => null,
                'journal_id' => null,
                'created_by' => Auth::id(),
            ]);

            if ($postingMode === FinFixedAssetDisposal::POSTING_MODE_JOURNAL) {
                $lines = [];

                // DR Bank/Cash for proceeds (if any)
                if (bccomp($proceeds, '0.00', 2) > 0) {
                    $bankAccountId = $this->getDefaultAccountId($organizationId, '1000');
                    if (! $bankAccountId) {
                        throw new InvalidArgumentException(
                            'Bank account (1000) is not configured for this organisation — '
                            .'cannot post fixed-asset disposal proceeds.',
                        );
                    }
                    $lines[] = [
                        'account_id' => $bankAccountId,
                        'description' => "Disposal proceeds: {$asset->asset_name}",
                        'debit' => $proceeds,
                        'credit' => 0,
                    ];
                }

                // DR Accumulated Depreciation (clear it out)
                if (bccomp($accumulated, '0.00', 2) > 0) {
                    if (! $asset->gl_depreciation_account_id) {
                        throw new InvalidArgumentException(
                            'Assign an accumulated-depreciation GL account before disposing this asset.',
                        );
                    }
                    $lines[] = [
                        'account_id' => $asset->gl_depreciation_account_id,
                        'description' => "Clear accumulated depreciation: {$asset->asset_name}",
                        'debit' => $accumulated,
                        'credit' => 0,
                    ];
                }

                // CR Asset Account (remove the asset at cost)
                $lines[] = [
                    'account_id' => $asset->gl_asset_account_id,
                    'description' => "Disposal of asset: {$asset->asset_name}",
                    'debit' => 0,
                    'credit' => $purchaseCost,
                ];

                // DR/CR Gain or Loss on Disposal — the balancing leg. This MUST
                // post whenever there is a gain/loss, or the journal is out of
                // balance and JournalPostingService rejects it (rolling the whole
                // disposal back). Resolve the account from config (a dedicated
                // 8400 — NOT the old hardcoded 8100, which the chart uses for Bank
                // Fees) and fail loudly if it is missing rather than dropping the
                // line and silently posting an unbalanced journal.
                $balancingAmount = bcsub(bcsub($purchaseCost, $proceeds, 2), $accumulated, 2);
                if (bccomp($balancingAmount, '0.00', 2) !== 0) {
                    $gainLossCode = config('finance.fixed_asset.gain_loss_account', '8400');
                    $gainLossAccountId = $this->getDefaultAccountId($organizationId, $gainLossCode);
                    if (! $gainLossAccountId) {
                        throw new InvalidArgumentException(
                            "Gain/Loss on Asset Disposal account ({$gainLossCode}) is not configured for this organisation — "
                            .'cannot post a balanced disposal journal for a gain or loss. Add the account to the chart, then retry.'
                        );
                    }
                    if (bccomp($balancingAmount, '0.00', 2) > 0) {
                        // Loss: debit
                        $lines[] = [
                            'account_id' => $gainLossAccountId,
                            'description' => "Loss on disposal: {$asset->asset_name}",
                            'debit' => $balancingAmount,
                            'credit' => 0,
                        ];
                    } else {
                        // Gain: credit
                        $lines[] = [
                            'account_id' => $gainLossAccountId,
                            'description' => "Gain on disposal: {$asset->asset_name}",
                            'debit' => 0,
                            'credit' => bcsub('0.00', $balancingAmount, 2),
                        ];
                    }
                }

                if (count($lines) < 2) {
                    throw new InvalidArgumentException(
                        'The configured fixed-asset disposal cannot produce a complete journal.',
                    );
                }

                $reference = "DISP-{$asset->asset_tag}";
                $description = "Disposal of fixed asset: {$asset->asset_name}";
                $expectedJournalDigest = FinFixedAssetDisposal::journalDigest(
                    $organizationId,
                    $disposedDate,
                    $reference,
                    $description,
                    (int) $disposal->id,
                    $lines,
                );

                $journal = $this->journalPostingService->createAndPost($organizationId, [
                    'journal_date' => $disposedDate,
                    'type' => 'standard',
                    'reference' => $reference,
                    'description' => $description,
                    'source_type' => FinFixedAssetDisposal::class,
                    'source_id' => $disposal->id,
                    'lines' => $lines,
                ]);
                $sourceJournals = FinJournal::query()
                    ->where('source_type', FinFixedAssetDisposal::class)
                    ->where('source_id', $disposal->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $journal->setRelation('lines', $journal->lines()->orderBy('id')->lockForUpdate()->get());

                if ($sourceJournals->count() !== 1
                    || ! $sourceJournals->first()->is($journal)
                    || $journal->status !== 'posted'
                    || $journal->reversal_of_journal_id !== null
                    || $journal->reversed_by_journal_id !== null
                    || ! hash_equals(
                        $expectedJournalDigest,
                        $this->disposalJournalDigest($journal, $disposal),
                    )) {
                    throw new RuntimeException(
                        'The fixed-asset disposal journal did not match its canonical posting projection.',
                    );
                }

                $disposal->update([
                    'journal_digest' => $expectedJournalDigest,
                    'journal_id' => $journal->id,
                ]);
            }

            $asset->update([
                'status' => 'disposed',
                'disposed_date' => $disposedDate,
                'disposal_proceeds' => $proceeds,
            ]);

            return $asset->refresh();
        });
    }

    private function resolveDisposalReplay(
        FinFixedAsset $asset,
        FinFixedAssetDisposal $disposal,
        string $requestHash,
    ): FinFixedAsset {
        if ($disposal->occurrence_type !== FinFixedAssetDisposal::OCCURRENCE_TYPE
            || ! hash_equals($disposal->request_hash, $requestHash)) {
            throw new InvalidArgumentException(
                'This fixed asset has already been disposed with different details.',
            );
        }

        if ($disposal->posting_mode === FinFixedAssetDisposal::POSTING_MODE_LEGACY_UNVERIFIED) {
            throw new RuntimeException(
                'The legacy fixed-asset disposal has no verified journal lineage. Finance review is required.',
            );
        }

        $terminalStateMatches = $asset->status === 'disposed'
            && $asset->disposed_date?->toDateString() === $disposal->disposed_date->toDateString()
            && $asset->disposal_proceeds !== null
            && bccomp((string) $asset->disposal_proceeds, (string) $disposal->disposal_proceeds, 2) === 0
            && bccomp((string) $asset->purchase_cost, (string) $disposal->purchase_cost, 2) === 0
            && bccomp(
                (string) $asset->accumulated_depreciation,
                (string) $disposal->accumulated_depreciation,
                2,
            ) === 0;
        if (! $terminalStateMatches) {
            throw new RuntimeException(
                'The fixed asset and its canonical disposal occurrence disagree. Finance review is required.',
            );
        }

        if ($disposal->posting_mode === FinFixedAssetDisposal::POSTING_MODE_NO_GL) {
            if ($disposal->journal_id !== null || $disposal->journal_digest !== null) {
                throw new RuntimeException(
                    'The no-GL fixed-asset disposal has conflicting journal lineage. Finance review is required.',
                );
            }

            return $asset->refresh();
        }

        if ($disposal->posting_mode !== FinFixedAssetDisposal::POSTING_MODE_JOURNAL
            || $disposal->journal_id === null
            || ! is_string($disposal->journal_digest)
            || strlen($disposal->journal_digest) !== 64) {
            throw new RuntimeException(
                'The fixed-asset disposal is missing its required journal link. Finance review is required.',
            );
        }

        $sourceJournals = FinJournal::query()
            ->where('source_type', FinFixedAssetDisposal::class)
            ->where('source_id', $disposal->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $journal = $sourceJournals->count() === 1 ? $sourceJournals->first() : null;
        if ($journal === null
            || (int) $journal->id !== (int) $disposal->journal_id
            || (int) $journal->organization_id !== (int) $asset->organization_id
            || $journal->source_type !== FinFixedAssetDisposal::class
            || (int) $journal->source_id !== (int) $disposal->id
            || $journal->status !== 'posted'
            || $journal->reversal_of_journal_id !== null
            || $journal->reversed_by_journal_id !== null) {
            throw new RuntimeException(
                'The fixed-asset disposal journal has conflicting lineage. Finance review is required.',
            );
        }

        $journal->setRelation('lines', $journal->lines()->orderBy('id')->lockForUpdate()->get());
        if (! hash_equals(
            $disposal->journal_digest,
            $this->disposalJournalDigest($journal, $disposal),
        )) {
            throw new RuntimeException(
                'The fixed-asset disposal journal has conflicting lineage. Finance review is required.',
            );
        }

        return $asset->refresh();
    }

    private function disposalJournalDigest(
        FinJournal $journal,
        FinFixedAssetDisposal $disposal,
    ): string {
        return FinFixedAssetDisposal::journalDigest(
            (int) $journal->organization_id,
            $journal->journal_date->toDateString(),
            $journal->reference,
            $journal->description,
            (int) $disposal->id,
            $journal->lines->map(static fn ($line): array => [
                'account_id' => $line->account_id,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'cost_centre_id' => $line->cost_centre_id,
                'funding_stream_id' => $line->funding_stream_id,
                'client_id' => $line->client_id,
                'client_fund_id' => $line->client_fund_id,
                'site_id' => $line->site_id,
                'tax_rate_id' => $line->tax_rate_id,
                'tax_amount' => $line->tax_amount,
            ])->all(),
            (string) $journal->total_amount,
        );
    }

    /**
     * Get the asset register for an organisation, grouped by category.
     */
    public function getAssetRegister(?int $orgId): Collection
    {
        return FinFixedAsset::forOrganization($orgId)
            ->with(['depreciations' => function ($q) {
                $q->orderBy('depreciation_date');
            }, 'glAssetAccount:id,code,name', 'glDepreciationAccount:id,code,name', 'glExpenseAccount:id,code,name'])
            ->orderBy('category')
            ->orderBy('asset_name')
            ->get();
    }

    /**
     * Project future monthly depreciation schedule for an asset.
     */
    public function getDepreciationSchedule(FinFixedAsset $asset): array
    {
        $schedule = [];
        $depreciableAmount = (float) $asset->purchase_cost - (float) $asset->residual_value;
        $accumulated = (float) $asset->accumulated_depreciation;
        $bookValue = $asset->getBookValue();

        if ($accumulated >= $depreciableAmount || $asset->status !== 'active') {
            return $schedule;
        }

        // Use the last depreciation date or purchase date as starting point
        $lastDepreciation = $asset->depreciations()->orderByDesc('depreciation_date')->first();
        $startDate = $lastDepreciation
            ? Carbon::parse($lastDepreciation->depreciation_date)->addMonth()->startOfMonth()
            : Carbon::parse($asset->purchase_date)->addMonth()->startOfMonth();

        $month = $startDate->copy();
        $currentAccumulated = $accumulated;
        $currentBookValue = $bookValue;

        // Project up to remaining useful life months (or until fully depreciated)
        $maxMonths = $asset->useful_life_months * 2; // safety cap
        $projected = 0;

        while ($currentAccumulated < $depreciableAmount && $projected < $maxMonths) {
            $monthlyAmount = $this->calculateMonthlyDepreciationWithAccumulated($asset, $currentAccumulated);

            if ($monthlyAmount <= 0) {
                break;
            }

            $remaining = $depreciableAmount - $currentAccumulated;
            if ($monthlyAmount > $remaining) {
                $monthlyAmount = $remaining;
            }

            $monthlyAmount = round($monthlyAmount, 2);
            $currentAccumulated = round($currentAccumulated + $monthlyAmount, 2);
            $currentBookValue = round((float) $asset->purchase_cost - $currentAccumulated, 2);

            $schedule[] = [
                'month' => $month->format('Y-m'),
                'depreciation_amount' => $monthlyAmount,
                'accumulated' => $currentAccumulated,
                'book_value' => $currentBookValue,
            ];

            $month->addMonth();
            $projected++;
        }

        return $schedule;
    }

    /**
     * Calculate the monthly depreciation amount for an asset based on its method.
     */
    protected function calculateMonthlyDepreciation(FinFixedAsset $asset): float
    {
        return $this->calculateMonthlyDepreciationWithAccumulated($asset, (float) $asset->accumulated_depreciation);
    }

    /**
     * Calculate the monthly depreciation with a given accumulated amount.
     * For projections we need to pass in accumulated rather than reading from the model.
     */
    protected function calculateMonthlyDepreciationWithAccumulated(FinFixedAsset $asset, float $accumulated): float
    {
        if ($asset->useful_life_months <= 0) {
            return 0.0;
        }

        if ($asset->depreciation_method === 'straight_line') {
            return ((float) $asset->purchase_cost - (float) $asset->residual_value) / $asset->useful_life_months;
        }

        if ($asset->depreciation_method === 'diminishing_value') {
            $bookValue = (float) $asset->purchase_cost - $accumulated;

            return $bookValue * (2 / $asset->useful_life_months);
        }

        return 0.0;
    }

    /**
     * Explicitly capitalise an asset that was registered without GL accounts
     * (e.g. auto-captured from an operational purchase): posts the acquisition
     * journal (DR fixed-asset account / CR bank) once the GL asset account has
     * been assigned. Idempotent — throws if the acquisition already posted, so
     * the action is surfaced, never silently repeated.
     */
    public function capitaliseAsset(FinFixedAsset $asset): FinFixedAsset
    {
        $assetId = $asset->getKey();
        $orgId = $asset->organization_id;

        return DB::transaction(function () use ($assetId, $orgId): FinFixedAsset {
            $this->journalPostingService->lockJournalSequence($orgId);

            $asset = FinFixedAsset::forOrganization($orgId)
                ->whereKey($assetId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($asset->status === 'disposed') {
                throw new InvalidArgumentException('A disposed fixed asset cannot be capitalised.');
            }

            if ($asset->acquisition_journal_id) {
                throw new InvalidArgumentException('The acquisition journal for this asset has already been posted.');
            }

            if (! $asset->gl_asset_account_id) {
                throw new InvalidArgumentException('Assign a GL asset account before posting the acquisition.');
            }

            if (! $this->getDefaultAccountId($asset->organization_id, '1000')) {
                throw new InvalidArgumentException("Bank account '1000' not found for this organisation — cannot post the acquisition.");
            }

            $this->postAcquisitionJournal($asset->organization_id, $asset);

            return $asset->refresh();
        });
    }

    /**
     * Post the acquisition journal for a new asset. Idempotent: skips when the
     * asset already records an acquisition journal, and stores the journal id.
     */
    protected function postAcquisitionJournal(?int $orgId, FinFixedAsset $asset): void
    {
        if ($asset->acquisition_journal_id) {
            return;
        }

        $bankAccountId = $this->getDefaultAccountId($orgId, '1000');

        if (! $bankAccountId || ! $asset->gl_asset_account_id) {
            return;
        }

        $journal = $this->journalPostingService->createAndPost($orgId, [
            'journal_date' => $asset->purchase_date->toDateString(),
            'type' => 'standard',
            'reference' => "ACQ-{$asset->asset_tag}",
            'description' => "Acquisition of fixed asset: {$asset->asset_name}",
            'source_type' => FinFixedAsset::class,
            'source_id' => $asset->id,
            'lines' => [
                [
                    'account_id' => $asset->gl_asset_account_id,
                    'description' => "Fixed asset acquisition: {$asset->asset_name}",
                    'debit' => (float) $asset->purchase_cost,
                    'credit' => 0,
                ],
                [
                    'account_id' => $bankAccountId,
                    'description' => "Payment for asset: {$asset->asset_name}",
                    'debit' => 0,
                    'credit' => (float) $asset->purchase_cost,
                ],
            ],
        ]);

        $asset->update(['acquisition_journal_id' => $journal->id]);
    }

    /**
     * Find a default account by code for the organisation.
     */
    protected function getDefaultAccountId(?int $orgId, string $code): ?int
    {
        return FinAccount::forOrganization($orgId)
            ->where('code', $code)
            ->active()
            ->value('id');
    }
}
