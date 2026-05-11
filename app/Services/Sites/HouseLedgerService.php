<?php

namespace App\Services\Sites;

use App\Models\HouseLedger;
use App\Models\HouseLedgerEntry;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HouseLedgerService
{
    /**
     * Get or create a ledger for the given site.
     */
    public function getOrCreateLedger(Site $site): HouseLedger
    {
        return HouseLedger::firstOrCreate(
            ['site_id' => $site->id],
            [
                'tenant_id' => $site->tenant_id ?? 1,
                'opening_balance' => 0,
                'current_balance' => 0,
                'currency' => 'NZD',
            ]
        );
    }

    /**
     * Add an entry to the ledger and update the running balance.
     */
    public function addEntry(HouseLedger $ledger, array $data, int $userId): HouseLedgerEntry
    {
        return DB::transaction(function () use ($ledger, $data, $userId) {
            // Lock the ledger row to prevent concurrent balance updates
            $ledger = HouseLedger::lockForUpdate()->find($ledger->id);

            $entryType = $data['entry_type'];
            $amount = (float) $data['amount'];

            // Calculate the balance change based on entry type
            $balanceChange = match ($entryType) {
                'income', 'transfer' => $amount,
                'expense' => -$amount,
                'adjustment' => $amount, // Signed amount: positive adds, negative subtracts
            };

            $newBalance = (float) $ledger->current_balance + $balanceChange;

            $entry = HouseLedgerEntry::create([
                'tenant_id' => $ledger->tenant_id,
                'house_ledger_id' => $ledger->id,
                'entry_type' => $entryType,
                'category' => $data['category'],
                'description' => $data['description'],
                'reference' => $data['reference'] ?? null,
                'amount' => $amount,
                'running_balance' => $newBalance,
                'entry_date' => $data['entry_date'],
                'recorded_by' => $userId,
                'approved_by' => $data['approved_by'] ?? null,
                'approved_at' => $data['approved_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'attachments' => $data['attachments'] ?? null,
            ]);

            $ledger->update(['current_balance' => $newBalance]);

            return $entry;
        });
    }

    /**
     * Get a statement of ledger entries with optional date range filtering.
     */
    public function getStatement(HouseLedger $ledger, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = $ledger->entries()->orderBy('entry_date')->orderBy('id');

        if ($from) {
            $query->where('entry_date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->where('entry_date', '<=', $to->toDateString());
        }

        return $query->get();
    }

    /**
     * Mark the ledger as reconciled.
     */
    public function reconcile(HouseLedger $ledger, int $userId): void
    {
        $ledger->update([
            'last_reconciled_at' => now(),
            'reconciled_by' => $userId,
        ]);
    }
}
