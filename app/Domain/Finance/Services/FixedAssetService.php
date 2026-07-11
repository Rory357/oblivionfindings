<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDepreciation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    }

    /**
     * Run monthly depreciation for all active assets in an organisation.
     */
    public function runDepreciation(?int $orgId, string $depreciationDate): array
    {
        $date = Carbon::parse($depreciationDate);
        $processed = [];

        $assets = FinFixedAsset::forOrganization($orgId)
            ->active()
            ->get();

        foreach ($assets as $asset) {
            $depreciableAmount = (float) $asset->purchase_cost - (float) $asset->residual_value;
            $accumulated = (float) $asset->accumulated_depreciation;

            // Skip if fully depreciated
            if ($accumulated >= $depreciableAmount) {
                continue;
            }

            // Calculate monthly depreciation
            $monthlyAmount = $this->calculateMonthlyDepreciation($asset);

            if ($monthlyAmount <= 0) {
                continue;
            }

            // Cap at remaining depreciable amount
            $remaining = $depreciableAmount - $accumulated;
            if ($monthlyAmount > $remaining) {
                $monthlyAmount = $remaining;
            }

            $monthlyAmount = round($monthlyAmount, 2);
            $newAccumulated = round($accumulated + $monthlyAmount, 2);
            $bookValueAfter = round((float) $asset->purchase_cost - $newAccumulated, 2);

            DB::transaction(function () use ($orgId, $asset, $date, $monthlyAmount, $newAccumulated, $bookValueAfter, $depreciableAmount) {
                // Create depreciation record
                $journalId = null;

                // Post GL journal
                if ($asset->gl_expense_account_id || $asset->gl_depreciation_account_id) {
                    $expenseAccountId = $asset->gl_expense_account_id
                        ?? $this->getDefaultAccountId($orgId, '8000');
                    $accumAccountId = $asset->gl_depreciation_account_id
                        ?? $this->getDefaultAccountId($orgId, '1590');

                    if ($expenseAccountId && $accumAccountId) {
                        $journal = $this->journalPostingService->createAndPost($orgId, [
                            'journal_date' => $date->toDateString(),
                            'type' => 'standard',
                            'reference' => "DEP-{$asset->asset_tag}-{$date->format('Y-m')}",
                            'description' => "Monthly depreciation: {$asset->asset_name} ({$date->format('M Y')})",
                            'source_type' => FinFixedAsset::class,
                            'source_id' => $asset->id,
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

                FinFixedAssetDepreciation::create([
                    'fixed_asset_id' => $asset->id,
                    'depreciation_date' => $date->toDateString(),
                    'amount' => $monthlyAmount,
                    'accumulated_total' => $newAccumulated,
                    'book_value_after' => $bookValueAfter,
                    'journal_id' => $journalId,
                ]);

                // Update asset
                $updateData = ['accumulated_depreciation' => $newAccumulated];

                if ($newAccumulated >= $depreciableAmount) {
                    $updateData['status'] = 'fully_depreciated';
                }

                $asset->update($updateData);
            });

            $processed[] = [
                'asset_id' => $asset->id,
                'asset_name' => $asset->asset_name,
                'amount' => $monthlyAmount,
                'new_accumulated' => $newAccumulated,
                'book_value' => $bookValueAfter,
                'status' => $newAccumulated >= $depreciableAmount ? 'fully_depreciated' : 'active',
            ];
        }

        return $processed;
    }

    /**
     * Dispose of an asset, recording the gain/loss on disposal.
     */
    public function disposeAsset(FinFixedAsset $asset, array $data): FinFixedAsset
    {
        return DB::transaction(function () use ($asset, $data) {
            $disposedDate = $data['disposed_date'];
            $proceeds = (float) $data['disposal_proceeds'];
            $bookValue = $asset->getBookValue();
            $gainLoss = round($proceeds - $bookValue, 2);
            $orgId = $asset->organization_id;
            $accumulated = (float) $asset->accumulated_depreciation;
            $purchaseCost = (float) $asset->purchase_cost;

            // Post disposal journal if GL accounts are configured
            if ($asset->gl_asset_account_id) {
                $lines = [];

                // DR Bank/Cash for proceeds (if any)
                if ($proceeds > 0) {
                    $bankAccountId = $this->getDefaultAccountId($orgId, '1000');
                    if ($bankAccountId) {
                        $lines[] = [
                            'account_id' => $bankAccountId,
                            'description' => "Disposal proceeds: {$asset->asset_name}",
                            'debit' => $proceeds,
                            'credit' => 0,
                        ];
                    }
                }

                // DR Accumulated Depreciation (clear it out)
                if ($accumulated > 0 && $asset->gl_depreciation_account_id) {
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
                $balancingAmount = $purchaseCost - $proceeds - $accumulated;
                if (round($balancingAmount, 2) != 0) {
                    $gainLossCode = config('finance.fixed_asset.gain_loss_account', '8400');
                    $gainLossAccountId = $this->getDefaultAccountId($orgId, $gainLossCode);
                    if (! $gainLossAccountId) {
                        throw new \InvalidArgumentException(
                            "Gain/Loss on Asset Disposal account ({$gainLossCode}) is not configured for this organisation — "
                            .'cannot post a balanced disposal journal for a gain or loss. Add the account to the chart, then retry.'
                        );
                    }
                    if ($balancingAmount > 0) {
                        // Loss: debit
                        $lines[] = [
                            'account_id' => $gainLossAccountId,
                            'description' => "Loss on disposal: {$asset->asset_name}",
                            'debit' => round(abs($balancingAmount), 2),
                            'credit' => 0,
                        ];
                    } else {
                        // Gain: credit
                        $lines[] = [
                            'account_id' => $gainLossAccountId,
                            'description' => "Gain on disposal: {$asset->asset_name}",
                            'debit' => 0,
                            'credit' => round(abs($balancingAmount), 2),
                        ];
                    }
                }

                if (count($lines) >= 2) {
                    $this->journalPostingService->createAndPost($orgId, [
                        'journal_date' => $disposedDate,
                        'type' => 'standard',
                        'reference' => "DISP-{$asset->asset_tag}",
                        'description' => "Disposal of fixed asset: {$asset->asset_name}",
                        'source_type' => FinFixedAsset::class,
                        'source_id' => $asset->id,
                        'lines' => $lines,
                    ]);
                }
            }

            $asset->update([
                'status' => 'disposed',
                'disposed_date' => $disposedDate,
                'disposal_proceeds' => $proceeds,
            ]);

            return $asset->refresh();
        });
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
