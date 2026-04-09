<?php

namespace App\Observers;

use App\Domain\Finance\Jobs\ProcessFinancialEventJob;
use App\Domain\Finance\Models\FinFinancialEvent;
use App\Models\HouseLedgerEntry;
use Illuminate\Support\Facades\Log;

/**
 * Bridges HouseLedger entries into the central GL.
 *
 * GL account is resolved by entry category (config-driven) with fallback to generic accounts.
 *
 * Expense categories map to specific GL accounts for granular P&L reporting.
 * Income categories map to specific GL revenue accounts.
 * Unmapped categories fall back to the generic house expense/income accounts.
 *
 * Transfers are excluded (internal movement between ledgers).
 */
class HouseLedgerEntryObserver
{
    public function created(HouseLedgerEntry $entry): void
    {
        if ($entry->entry_type === 'transfer') {
            return;
        }

        if (! $entry->amount || bccomp((string) abs((float) $entry->amount), '0', 2) <= 0) {
            return;
        }

        if ($entry->journal_id) {
            return;
        }

        try {
            $ledger = $entry->ledger;
            if (! $ledger) {
                return;
            }

            $orgId = $entry->tenant_id ?? $ledger->tenant_id;
            if (! $orgId) {
                return;
            }

            $siteId = $ledger->site_id;
            $amount = (string) abs((float) $entry->amount);

            [$eventType, $debitCode, $creditCode] = $this->resolveAccounts($entry);

            if (! $eventType) {
                return;
            }

            ProcessFinancialEventJob::dispatch([
                'organization_id' => $orgId,
                'source_type' => HouseLedgerEntry::class,
                'source_id' => $entry->id,
                'event_type' => $eventType,
                'description' => "House ledger [{$entry->entry_type}]: {$entry->description}"
                    . " ({$entry->category})",
                'amount' => $amount,
                'event_date' => $entry->entry_date->toDateString(),
                'debit_account_code' => $debitCode,
                'credit_account_code' => $creditCode,
                'payment_type' => FinFinancialEvent::PAYMENT_CASH,
                'journal_type' => 'standard',
                'site_id' => $siteId,
                'source_updated_at' => $entry->updated_at?->toISOString(),
            ]);
        } catch (\Throwable $e) {
            Log::error("HouseLedgerEntryObserver: Failed to dispatch GL job for entry #{$entry->id}: {$e->getMessage()}");
        }
    }

    /**
     * Resolve GL accounts based on entry_type and category.
     *
     * Category mapping is config-driven. Unmapped categories use generic fallbacks.
     *
     * @return array{string|null, string, string} [event_type, debit_code, credit_code]
     */
    private function resolveAccounts(HouseLedgerEntry $entry): array
    {
        $category = strtolower(trim($entry->category ?? ''));

        return match ($entry->entry_type) {
            'income' => $this->resolveIncome($category),
            'expense' => $this->resolveExpense($category),
            'adjustment' => $this->resolveAdjustment($entry, $category),
            default => [null, '', ''],
        };
    }

    private function resolveExpense(string $category): array
    {
        $categoryMap = config('finance.house_ledger_expense_categories', []);
        $debitCode = $categoryMap[$category] ?? '6430'; // Fallback: generic House Operating Expense

        return [
            'house_ledger_expense',
            $debitCode,
            '1000', // CR Bank
        ];
    }

    private function resolveIncome(string $category): array
    {
        $categoryMap = config('finance.house_ledger_income_categories', []);
        $creditCode = $categoryMap[$category] ?? '4200'; // Fallback: generic House Income

        return [
            'house_ledger_income',
            '1000', // DR Bank
            $creditCode,
        ];
    }

    /**
     * Adjustments: positive = expense-like, negative = income-like.
     */
    private function resolveAdjustment(HouseLedgerEntry $entry, string $category): array
    {
        if ((float) $entry->amount >= 0) {
            return $this->resolveExpense($category);
        }

        return $this->resolveIncome($category);
    }
}
