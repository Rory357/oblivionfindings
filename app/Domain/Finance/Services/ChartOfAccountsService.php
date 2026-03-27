<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinJournalLine;
use Illuminate\Support\Facades\DB;

class ChartOfAccountsService
{
    /**
     * Returns hierarchical account tree grouped by type.
     * Each node has children array and calculated balance.
     */
    public function getAccountTree(?int $orgId): array
    {
        $accounts = FinAccount::forOrganization($orgId)
            ->with('children')
            ->orderBy('code')
            ->get();

        // Pre-calculate balances for all accounts in bulk
        $balances = $this->calculateBulkBalances($orgId);

        $types = ['asset', 'liability', 'equity', 'revenue', 'expense'];
        $tree = [];

        foreach ($types as $type) {
            $rootAccounts = $accounts
                ->where('type', $type)
                ->whereNull('parent_id');

            $tree[$type] = $rootAccounts->map(function ($account) use ($accounts, $balances) {
                return $this->buildNode($account, $accounts, $balances);
            })->values()->toArray();
        }

        return $tree;
    }

    /**
     * Build a tree node for an account with its children.
     */
    private function buildNode(FinAccount $account, $allAccounts, array $balances): array
    {
        $children = $allAccounts->where('parent_id', $account->id);

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'sub_type' => $account->sub_type,
            'is_system' => $account->is_system,
            'is_active' => $account->is_active,
            'gst_applicable' => $account->gst_applicable,
            'description' => $account->description,
            'balance' => $balances[$account->id] ?? (float) $account->opening_balance,
            'children' => $children->map(function ($child) use ($allAccounts, $balances) {
                return $this->buildNode($child, $allAccounts, $balances);
            })->values()->toArray(),
        ];
    }

    /**
     * Calculate balances for all accounts in an org in bulk.
     */
    private function calculateBulkBalances(?int $orgId): array
    {
        $accounts = FinAccount::forOrganization($orgId)->get(['id', 'type', 'opening_balance']);

        $totals = FinJournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('organization_id', $orgId))
            ->whereHas('journal', fn ($q) => $q->where('status', 'posted'))
            ->select('account_id')
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debits')
            ->selectRaw('COALESCE(SUM(credit), 0) as total_credits')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $balances = [];
        foreach ($accounts as $account) {
            $entry = $totals->get($account->id);
            $debits = $entry ? (float) $entry->total_debits : 0.0;
            $credits = $entry ? (float) $entry->total_credits : 0.0;
            $opening = (float) $account->opening_balance;

            if (in_array($account->type, ['asset', 'expense'])) {
                $balances[$account->id] = $debits - $credits + $opening;
            } else {
                $balances[$account->id] = $credits - $debits + $opening;
            }
        }

        return $balances;
    }

    /**
     * Returns journal lines for an account within date range, with running balance.
     */
    public function getAccountLedger(int $accountId, ?string $startDate, ?string $endDate): array
    {
        $account = FinAccount::findOrFail($accountId);

        $query = FinJournalLine::where('account_id', $accountId)
            ->whereHas('journal', function ($q) use ($startDate, $endDate) {
                $q->where('status', 'posted');
                if ($startDate) {
                    $q->where('journal_date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->where('journal_date', '<=', $endDate);
                }
            })
            ->with(['journal:id,journal_number,journal_date,description'])
            ->orderBy(
                DB::raw('(SELECT journal_date FROM fin_journals WHERE fin_journals.id = fin_journal_lines.journal_id)')
            )
            ->orderBy('id')
            ->get();

        // Calculate opening balance (sum of all transactions before start date)
        $openingBalance = (float) $account->opening_balance;
        if ($startDate) {
            $priorTotals = FinJournalLine::where('account_id', $accountId)
                ->whereHas('journal', fn ($q) => $q->where('status', 'posted')->where('journal_date', '<', $startDate))
                ->selectRaw('COALESCE(SUM(debit), 0) as total_debits, COALESCE(SUM(credit), 0) as total_credits')
                ->first();

            $debits = (float) $priorTotals->total_debits;
            $credits = (float) $priorTotals->total_credits;

            if (in_array($account->type, ['asset', 'expense'])) {
                $openingBalance += $debits - $credits;
            } else {
                $openingBalance += $credits - $debits;
            }
        }

        $runningBalance = $openingBalance;
        $lines = [];

        foreach ($query as $line) {
            $debit = (float) $line->debit;
            $credit = (float) $line->credit;

            if (in_array($account->type, ['asset', 'expense'])) {
                $runningBalance += $debit - $credit;
            } else {
                $runningBalance += $credit - $debit;
            }

            $lines[] = [
                'id' => $line->id,
                'date' => $line->journal->journal_date->toDateString(),
                'journal_number' => $line->journal->journal_number,
                'journal_id' => $line->journal->id,
                'description' => $line->description ?: $line->journal->description,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => round($runningBalance, 2),
            ];
        }

        return [
            'opening_balance' => round($openingBalance, 2),
            'lines' => $lines,
            'closing_balance' => round($runningBalance, 2),
        ];
    }

    /**
     * Create a new account. Validates code uniqueness per org.
     */
    public function createAccount(?int $orgId, array $data): FinAccount
    {
        $exists = FinAccount::forOrganization($orgId)
            ->where('code', $data['code'])
            ->exists();

        if ($exists) {
            throw new \InvalidArgumentException('An account with this code already exists.');
        }

        return FinAccount::create(array_merge($data, [
            'organization_id' => $orgId,
        ]));
    }

    /**
     * Update an account. Cannot change type if journal lines exist.
     * Cannot edit system accounts' code or type.
     */
    public function updateAccount(FinAccount $account, array $data): FinAccount
    {
        if ($account->is_system) {
            unset($data['code'], $data['type']);
        }

        if (isset($data['type']) && $data['type'] !== $account->type) {
            $hasJournalLines = $account->journalLines()->exists();
            if ($hasJournalLines) {
                throw new \InvalidArgumentException('Cannot change account type when journal lines exist.');
            }
        }

        // Check code uniqueness if code is changing
        if (isset($data['code']) && $data['code'] !== $account->code) {
            $exists = FinAccount::forOrganization($account->organization_id)
                ->where('code', $data['code'])
                ->where('id', '!=', $account->id)
                ->exists();

            if ($exists) {
                throw new \InvalidArgumentException('An account with this code already exists.');
            }
        }

        $account->update($data);

        return $account->fresh();
    }

    /**
     * Delete an account. Only if no journal lines reference it and not a system account.
     */
    public function deleteAccount(FinAccount $account): void
    {
        if ($account->is_system) {
            throw new \InvalidArgumentException('System accounts cannot be deleted.');
        }

        if ($account->journalLines()->exists()) {
            throw new \InvalidArgumentException('Cannot delete account with existing journal entries.');
        }

        $account->delete();
    }

    /**
     * Returns array of accounts with debit/credit balances for trial balance.
     */
    public function getTrialBalanceData(?int $orgId, ?string $asOfDate): array
    {
        $accounts = FinAccount::forOrganization($orgId)
            ->active()
            ->orderBy('code')
            ->get();

        $query = FinJournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('organization_id', $orgId)->where('is_active', true))
            ->whereHas('journal', function ($q) use ($asOfDate) {
                $q->where('status', 'posted');
                if ($asOfDate) {
                    $q->where('journal_date', '<=', $asOfDate);
                }
            })
            ->select('account_id')
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debits')
            ->selectRaw('COALESCE(SUM(credit), 0) as total_credits')
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        $trialBalance = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $entry = $query->get($account->id);
            $debits = $entry ? (float) $entry->total_debits : 0.0;
            $credits = $entry ? (float) $entry->total_credits : 0.0;
            $opening = (float) $account->opening_balance;

            // Determine normal balance side
            $debitBalance = 0;
            $creditBalance = 0;

            if (in_array($account->type, ['asset', 'expense'])) {
                $net = $debits - $credits + $opening;
                if ($net >= 0) {
                    $debitBalance = $net;
                } else {
                    $creditBalance = abs($net);
                }
            } else {
                $net = $credits - $debits + $opening;
                if ($net >= 0) {
                    $creditBalance = $net;
                } else {
                    $debitBalance = abs($net);
                }
            }

            // Only include accounts with a balance
            if ($debitBalance != 0 || $creditBalance != 0) {
                $trialBalance[] = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debit' => round($debitBalance, 2),
                    'credit' => round($creditBalance, 2),
                ];

                $totalDebits += $debitBalance;
                $totalCredits += $creditBalance;
            }
        }

        return [
            'accounts' => $trialBalance,
            'total_debits' => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
        ];
    }
}
