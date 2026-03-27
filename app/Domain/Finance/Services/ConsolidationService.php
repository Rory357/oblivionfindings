<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinAccountMapping;
use App\Domain\Finance\Models\FinConsolidationEntity;
use App\Domain\Finance\Models\FinConsolidationGroup;
use App\Domain\Finance\Models\FinConsolidationRun;
use App\Domain\Finance\Models\FinIntercompanyTransaction;
use App\Domain\Finance\Models\FinJournalLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConsolidationService
{
    public function __construct(
        private readonly JournalPostingService $journalService,
    ) {}

    /**
     * Run a full consolidation for a group over a date range.
     */
    public function runConsolidation(FinConsolidationGroup $group, string $periodFrom, string $periodTo): FinConsolidationRun
    {
        $run = FinConsolidationRun::create([
            'group_id' => $group->id,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'status' => 'processing',
            'created_by' => Auth::id(),
        ]);

        try {
            $group->loadMissing(['entities', 'accountMappings']);

            $consolidatedData = [
                'revenue' => '0',
                'expenses' => '0',
                'assets' => '0',
                'liabilities' => '0',
                'equity' => '0',
                'accounts' => [],
            ];

            // 1. Aggregate each entity's trial balance
            foreach ($group->entities as $entity) {
                if (! $entity->is_active) {
                    continue;
                }

                $trialBalance = $this->getEntityTrialBalance($entity, $periodFrom, $periodTo);
                $this->aggregateIntoConsolidation($consolidatedData, $trialBalance, $entity);
            }

            // 2. Eliminate intercompany transactions
            $eliminations = $this->eliminateIntercompanyTransactions($group, $run, $periodFrom, $periodTo);

            // 3. Apply eliminations to consolidated data
            foreach ($eliminations as $elim) {
                $consolidatedData['revenue'] = bcsub($consolidatedData['revenue'], (string) $elim['revenue_impact'], 2);
                $consolidatedData['expenses'] = bcsub($consolidatedData['expenses'], (string) $elim['expense_impact'], 2);
            }

            $run->update([
                'status' => 'completed',
                'total_revenue' => $consolidatedData['revenue'],
                'total_expenses' => $consolidatedData['expenses'],
                'total_assets' => $consolidatedData['assets'],
                'total_liabilities' => $consolidatedData['liabilities'],
                'total_equity' => $consolidatedData['equity'],
                'eliminations_count' => count($eliminations),
                'eliminations_amount' => collect($eliminations)->sum('amount'),
                'report_data' => $consolidatedData,
            ]);

            return $run->refresh();
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Get trial balance data for a single entity (organization) for the given period.
     * Queries posted journals and sums debit/credit by account.
     *
     * @return array<int, array{account_id: int, account_code: string, account_name: string, account_type: string, debit: string, credit: string, balance: string}>
     */
    private function getEntityTrialBalance(FinConsolidationEntity $entity, string $periodFrom, string $periodTo): array
    {
        $rows = FinJournalLine::query()
            ->join('fin_journals', 'fin_journal_lines.journal_id', '=', 'fin_journals.id')
            ->join('fin_accounts', 'fin_journal_lines.account_id', '=', 'fin_accounts.id')
            ->where('fin_journals.organization_id', $entity->organization_id)
            ->where('fin_journals.status', 'posted')
            ->whereBetween('fin_journals.journal_date', [$periodFrom, $periodTo])
            ->groupBy('fin_journal_lines.account_id', 'fin_accounts.code', 'fin_accounts.name', 'fin_accounts.type')
            ->select([
                'fin_journal_lines.account_id',
                'fin_accounts.code as account_code',
                'fin_accounts.name as account_name',
                'fin_accounts.type as account_type',
                DB::raw('COALESCE(SUM(fin_journal_lines.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(fin_journal_lines.credit), 0) as total_credit'),
            ])
            ->get();

        $trialBalance = [];

        foreach ($rows as $row) {
            $debit = (string) $row->total_debit;
            $credit = (string) $row->total_credit;

            // Calculate balance based on account type:
            // Asset/Expense: debit - credit (debit-normal)
            // Liability/Equity/Revenue: credit - debit (credit-normal)
            if (in_array($row->account_type, ['asset', 'expense'])) {
                $balance = bcsub($debit, $credit, 2);
            } else {
                $balance = bcsub($credit, $debit, 2);
            }

            $trialBalance[] = [
                'account_id' => $row->account_id,
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'account_type' => $row->account_type,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ];
        }

        return $trialBalance;
    }

    /**
     * Aggregate an entity's trial balance into the consolidated totals.
     * Applies ownership percentage and consolidation method.
     */
    private function aggregateIntoConsolidation(array &$consolidatedData, array $trialBalance, FinConsolidationEntity $entity): void
    {
        $ownershipFactor = bcdiv((string) $entity->ownership_percentage, '100', 4);

        // For equity method, we only include the share of net income, not full line items
        $isEquityMethod = $entity->consolidation_method === 'equity';

        if ($isEquityMethod) {
            // Equity method: only include share of net income as a single equity line
            $netIncome = '0';
            foreach ($trialBalance as $item) {
                if ($item['account_type'] === 'revenue') {
                    $netIncome = bcadd($netIncome, $item['balance'], 2);
                } elseif ($item['account_type'] === 'expense') {
                    $netIncome = bcsub($netIncome, $item['balance'], 2);
                }
            }
            $share = bcmul($netIncome, $ownershipFactor, 2);
            $consolidatedData['equity'] = bcadd($consolidatedData['equity'], $share, 2);

            $consolidatedData['accounts'][] = [
                'entity_id' => $entity->id,
                'entity_name' => $entity->entity_name,
                'account_code' => 'EQUITY-INV',
                'account_name' => "Investment in {$entity->entity_name}",
                'account_type' => 'equity',
                'balance' => $share,
                'method' => 'equity',
            ];

            return;
        }

        // Full or proportional consolidation
        foreach ($trialBalance as $item) {
            // For proportional method, apply ownership percentage; for full, use 100%
            $factor = $entity->consolidation_method === 'proportional' ? $ownershipFactor : '1';
            $adjustedBalance = bcmul($item['balance'], $factor, 2);

            // Accumulate into the correct category
            match ($item['account_type']) {
                'revenue' => $consolidatedData['revenue'] = bcadd($consolidatedData['revenue'], $adjustedBalance, 2),
                'expense' => $consolidatedData['expenses'] = bcadd($consolidatedData['expenses'], $adjustedBalance, 2),
                'asset' => $consolidatedData['assets'] = bcadd($consolidatedData['assets'], $adjustedBalance, 2),
                'liability' => $consolidatedData['liabilities'] = bcadd($consolidatedData['liabilities'], $adjustedBalance, 2),
                'equity' => $consolidatedData['equity'] = bcadd($consolidatedData['equity'], $adjustedBalance, 2),
                default => null,
            };

            // Check if there is a mapping for this account
            $mapping = $this->findAccountMapping($item['account_id'], $entity);

            $consolidatedData['accounts'][] = [
                'entity_id' => $entity->id,
                'entity_name' => $entity->entity_name,
                'account_code' => $mapping ? $mapping->consolidated_account_code : $item['account_code'],
                'account_name' => $mapping ? $mapping->consolidated_account_name : $item['account_name'],
                'account_type' => $item['account_type'],
                'balance' => $adjustedBalance,
                'method' => $entity->consolidation_method,
                'source_account_code' => $item['account_code'],
            ];
        }
    }

    /**
     * Find account mapping for a source account within an entity's group.
     */
    private function findAccountMapping(int $sourceAccountId, FinConsolidationEntity $entity): ?FinAccountMapping
    {
        return FinAccountMapping::where('group_id', $entity->group_id)
            ->where('entity_id', $entity->id)
            ->where('source_account_id', $sourceAccountId)
            ->first();
    }

    /**
     * Eliminate intercompany transactions for the consolidation run.
     * Finds posted ICTs in the period and marks them as eliminated.
     *
     * @return array<int, array{amount: float, revenue_impact: string, expense_impact: string}>
     */
    private function eliminateIntercompanyTransactions(
        FinConsolidationGroup $group,
        FinConsolidationRun $run,
        string $periodFrom,
        string $periodTo,
    ): array {
        $icts = FinIntercompanyTransaction::where('group_id', $group->id)
            ->where('status', 'posted')
            ->whereBetween('transaction_date', [$periodFrom, $periodTo])
            ->get();

        $eliminations = [];

        foreach ($icts as $ict) {
            // Each ICT represents revenue in one entity and expense in another.
            // To eliminate, we reduce both revenue and expense by the transaction amount.
            $amount = (string) $ict->amount;

            $eliminations[] = [
                'ict_id' => $ict->id,
                'amount' => (float) $ict->amount,
                'revenue_impact' => $amount,
                'expense_impact' => $amount,
                'from_entity' => $ict->from_entity_id,
                'to_entity' => $ict->to_entity_id,
                'description' => $ict->description,
            ];

            // Mark the ICT as eliminated
            $ict->update([
                'status' => 'eliminated',
                'eliminated_in_run_id' => $run->id,
            ]);
        }

        return $eliminations;
    }
}
