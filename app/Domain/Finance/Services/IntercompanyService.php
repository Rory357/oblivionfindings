<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinConsolidationGroup;
use App\Domain\Finance\Models\FinIntercompanyTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IntercompanyService
{
    public function __construct(
        private readonly JournalPostingService $journalService,
    ) {}

    /**
     * Create an intercompany transaction record.
     * Optionally posts journals in both entities if account IDs are provided.
     */
    public function createTransaction(FinConsolidationGroup $group, array $data): FinIntercompanyTransaction
    {
        return DB::transaction(function () use ($group, $data) {
            $ict = FinIntercompanyTransaction::create([
                'group_id' => $group->id,
                'from_entity_id' => $data['from_entity_id'],
                'to_entity_id' => $data['to_entity_id'],
                'transaction_date' => $data['transaction_date'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'status' => 'pending',
                'created_by' => Auth::id(),
            ]);

            return $ict;
        });
    }

    /**
     * Post journals in both entities for an intercompany transaction.
     * Creates a journal in the "from" entity (expense/payable) and the "to" entity (revenue/receivable).
     */
    public function postTransaction(FinIntercompanyTransaction $ict): void
    {
        if ($ict->status !== 'pending') {
            throw new \InvalidArgumentException(
                "Intercompany transaction #{$ict->id} cannot be posted: status is '{$ict->status}', expected 'pending'."
            );
        }

        $ict->loadMissing(['fromEntity', 'toEntity']);

        DB::transaction(function () use ($ict) {
            $fromOrgId = $ict->fromEntity->organization_id;
            $toOrgId = $ict->toEntity->organization_id;
            $amount = (string) $ict->amount;

            // Find intercompany accounts for the "from" entity (payer).
            // We need an expense account and a payable (liability) account.
            $fromExpenseAccount = $this->findOrCreateIntercompanyAccount($fromOrgId, 'expense', 'ICT Expense');
            $fromPayableAccount = $this->findOrCreateIntercompanyAccount($fromOrgId, 'liability', 'ICT Payable');

            // Create and post journal in the "from" entity
            $fromJournal = $this->journalService->createAndPost($fromOrgId, [
                'journal_date' => $ict->transaction_date->toDateString(),
                'type' => 'general',
                'reference' => "ICT-{$ict->id}",
                'description' => "Intercompany: {$ict->description} (to {$ict->toEntity->entity_name})",
                'lines' => [
                    [
                        'account_id' => $fromExpenseAccount->id,
                        'description' => $ict->description,
                        'debit' => $amount,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $fromPayableAccount->id,
                        'description' => $ict->description,
                        'debit' => 0,
                        'credit' => $amount,
                    ],
                ],
            ]);

            // Find intercompany accounts for the "to" entity (receiver).
            // We need a revenue account and a receivable (asset) account.
            $toRevenueAccount = $this->findOrCreateIntercompanyAccount($toOrgId, 'revenue', 'ICT Revenue');
            $toReceivableAccount = $this->findOrCreateIntercompanyAccount($toOrgId, 'asset', 'ICT Receivable');

            // Create and post journal in the "to" entity
            $toJournal = $this->journalService->createAndPost($toOrgId, [
                'journal_date' => $ict->transaction_date->toDateString(),
                'type' => 'general',
                'reference' => "ICT-{$ict->id}",
                'description' => "Intercompany: {$ict->description} (from {$ict->fromEntity->entity_name})",
                'lines' => [
                    [
                        'account_id' => $toReceivableAccount->id,
                        'description' => $ict->description,
                        'debit' => $amount,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $toRevenueAccount->id,
                        'description' => $ict->description,
                        'debit' => 0,
                        'credit' => $amount,
                    ],
                ],
            ]);

            $ict->update([
                'from_journal_id' => $fromJournal->id,
                'to_journal_id' => $toJournal->id,
                'status' => 'posted',
            ]);
        });
    }

    /**
     * Find or create a system intercompany account for the given organization.
     */
    private function findOrCreateIntercompanyAccount(int $orgId, string $type, string $name): FinAccount
    {
        $codePrefix = match ($type) {
            'expense' => '9100',
            'liability' => '2900',
            'revenue' => '4900',
            'asset' => '1900',
            default => '9999',
        };

        $account = FinAccount::where('organization_id', $orgId)
            ->where('code', $codePrefix)
            ->where('type', $type)
            ->where('is_system', true)
            ->first();

        if (! $account) {
            $account = FinAccount::create([
                'organization_id' => $orgId,
                'code' => $codePrefix,
                'name' => $name,
                'type' => $type,
                'is_system' => true,
                'is_active' => true,
                'opening_balance' => 0,
                'gst_applicable' => false,
                'created_by' => Auth::id(),
            ]);
        }

        return $account;
    }
}
