<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCurrency;
use App\Domain\Finance\Models\FinFxRevaluation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FxRevaluationService
{
    public function __construct(
        protected CurrencyService $currencyService,
        protected JournalPostingService $journalPostingService,
    ) {}

    /**
     * Calculate unrealised FX gain/loss for all foreign-currency open items.
     *
     * Scans:
     * - Open (unpaid/partially-paid) foreign-currency bills
     * - Foreign-currency bank account balances
     *
     * Returns an array of line items with gain/loss details.
     *
     * @return array{items: array, total_gain_loss: float}
     */
    public function calculateUnrealisedGainLoss(?int $orgId, string $date): array
    {
        $baseCurrency = FinCurrency::forOrganization($orgId)->base()->first();

        if (! $baseCurrency) {
            return ['items' => [], 'total_gain_loss' => 0.0];
        }

        $items = [];
        $totalGainLoss = 0.0;

        // 1. Open foreign-currency bills
        $bills = FinBill::forOrganization($orgId)
            ->whereNotNull('currency_id')
            ->where('currency_id', '!=', $baseCurrency->id)
            ->whereIn('status', ['draft', 'approved', 'partially_paid'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->get();

        foreach ($bills as $bill) {
            $amountDue = $bill->getAmountDue();
            $bookedRate = (float) ($bill->exchange_rate ?? 1.0);
            $currentRate = $this->currencyService->getExchangeRate(
                $orgId,
                $bill->currency_id,
                $baseCurrency->id,
                $date
            );

            $bookedBaseValue = round($amountDue * $bookedRate, 2);
            $currentBaseValue = round($amountDue * $currentRate, 2);
            $gainLoss = round($currentBaseValue - $bookedBaseValue, 2);

            if (abs($gainLoss) > 0.00) {
                $currency = FinCurrency::find($bill->currency_id);
                $items[] = [
                    'type' => 'bill',
                    'reference' => $bill->bill_number,
                    'currency_code' => $currency?->code ?? '???',
                    'foreign_amount' => $amountDue,
                    'booked_rate' => $bookedRate,
                    'current_rate' => $currentRate,
                    'booked_base_value' => $bookedBaseValue,
                    'current_base_value' => $currentBaseValue,
                    'gain_loss' => $gainLoss,
                ];
                $totalGainLoss += $gainLoss;
            }
        }

        // 2. Foreign-currency bank account balances
        $bankAccounts = FinBankAccount::forOrganization($orgId)
            ->active()
            ->whereNotNull('currency_id')
            ->where('currency_id', '!=', $baseCurrency->id)
            ->where('current_balance', '!=', 0)
            ->get();

        foreach ($bankAccounts as $account) {
            $balance = (float) $account->current_balance;
            $currency = FinCurrency::find($account->currency_id);

            // The bank account's exchange rate at time of last reconciliation
            // For simplicity, we use the currency table's stored rate as "booked"
            $bookedRate = (float) ($currency?->exchange_rate ?? 1.0);
            $currentRate = $this->currencyService->getExchangeRate(
                $orgId,
                $account->currency_id,
                $baseCurrency->id,
                $date
            );

            $bookedBaseValue = round($balance * $bookedRate, 2);
            $currentBaseValue = round($balance * $currentRate, 2);
            $gainLoss = round($currentBaseValue - $bookedBaseValue, 2);

            if (abs($gainLoss) > 0.00) {
                $items[] = [
                    'type' => 'bank_account',
                    'reference' => $account->name,
                    'currency_code' => $currency?->code ?? '???',
                    'foreign_amount' => $balance,
                    'booked_rate' => $bookedRate,
                    'current_rate' => $currentRate,
                    'booked_base_value' => $bookedBaseValue,
                    'current_base_value' => $currentBaseValue,
                    'gain_loss' => $gainLoss,
                ];
                $totalGainLoss += $gainLoss;
            }
        }

        $totalGainLoss = round($totalGainLoss, 2);

        return [
            'items' => $items,
            'total_gain_loss' => $totalGainLoss,
        ];
    }

    /**
     * Create a draft FX revaluation record.
     */
    public function createRevaluation(?int $orgId, string $date): FinFxRevaluation
    {
        $result = $this->calculateUnrealisedGainLoss($orgId, $date);

        return FinFxRevaluation::create([
            'organization_id' => $orgId,
            'revaluation_date' => $date,
            'total_gain_loss' => $result['total_gain_loss'],
            'status' => 'draft',
            'notes' => 'Unrealised FX gain/loss revaluation with ' . count($result['items']) . ' item(s).',
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Post a draft revaluation by creating a journal entry via JournalPostingService.
     */
    public function postRevaluation(FinFxRevaluation $reval): FinFxRevaluation
    {
        if ($reval->status !== 'draft') {
            throw new \InvalidArgumentException(
                "FX Revaluation #{$reval->id} cannot be posted: status is '{$reval->status}', expected 'draft'."
            );
        }

        return DB::transaction(function () use ($reval) {
            $totalGainLoss = (float) $reval->total_gain_loss;

            if (abs($totalGainLoss) < 0.01) {
                throw new \InvalidArgumentException(
                    'FX Revaluation has no material gain or loss to post.'
                );
            }

            // Determine debit/credit accounts
            // Unrealised FX Gain/Loss uses account 8300 (we'll look it up, or use a sensible default)
            $fxGainLossAccount = \App\Domain\Finance\Models\FinAccount::forOrganization($reval->organization_id)
                ->where('code', '8300')
                ->first();

            $retainedEarningsAccount = \App\Domain\Finance\Models\FinAccount::forOrganization($reval->organization_id)
                ->where('code', '3000')
                ->first();

            if (! $fxGainLossAccount || ! $retainedEarningsAccount) {
                throw new \InvalidArgumentException(
                    'Required GL accounts (8300 - FX Gain/Loss, 3000 - Retained Earnings) not found. '
                    . 'Please ensure these accounts exist in the Chart of Accounts.'
                );
            }

            // Build journal lines
            $lines = [];
            if ($totalGainLoss > 0) {
                // Gain: debit retained earnings, credit FX gain/loss
                $lines[] = [
                    'account_id' => $retainedEarningsAccount->id,
                    'description' => 'Unrealised FX gain revaluation',
                    'debit' => abs($totalGainLoss),
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_id' => $fxGainLossAccount->id,
                    'description' => 'Unrealised FX gain revaluation',
                    'debit' => 0,
                    'credit' => abs($totalGainLoss),
                ];
            } else {
                // Loss: debit FX gain/loss, credit retained earnings
                $lines[] = [
                    'account_id' => $fxGainLossAccount->id,
                    'description' => 'Unrealised FX loss revaluation',
                    'debit' => abs($totalGainLoss),
                    'credit' => 0,
                ];
                $lines[] = [
                    'account_id' => $retainedEarningsAccount->id,
                    'description' => 'Unrealised FX loss revaluation',
                    'debit' => 0,
                    'credit' => abs($totalGainLoss),
                ];
            }

            $journal = $this->journalPostingService->createAndPost($reval->organization_id, [
                'journal_date' => $reval->revaluation_date->toDateString(),
                'type' => 'adjustment',
                'reference' => 'FX-REVAL-' . $reval->id,
                'description' => 'FX Revaluation — unrealised gain/loss as at ' . $reval->revaluation_date->toDateString(),
                'lines' => $lines,
            ]);

            $reval->update([
                'status' => 'posted',
                'journal_id' => $journal->id,
                'fiscal_period_id' => $journal->fiscal_period_id,
            ]);

            return $reval->refresh();
        });
    }
}
