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
        return $site->houseLedger()->firstOrCreate([], [
            'opening_balance' => 0,
            'current_balance' => 0,
            'currency' => 'NZD',
        ]);
    }

    /**
     * Add an entry to the ledger and update the running balance.
     */
    public function addEntry(Site $site, array $data, int $userId): HouseLedgerEntry
    {
        $this->getOrCreateLedger($site);

        return DB::transaction(function () use ($site, $data, $userId) {
            $ledger = HouseLedger::query()
                ->where('site_id', $site->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $entryType = $data['entry_type'];
            $amount = (float) $data['amount'];

            // Calculate the balance change based on entry type
            $balanceChange = match ($entryType) {
                'income', 'transfer' => $amount,
                'expense' => -$amount,
                'adjustment' => $amount, // Signed amount: positive adds, negative subtracts
            };

            $newBalance = (float) $ledger->current_balance + $balanceChange;

            $entry = $ledger->entries()->create([
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
            $entry->setRelation('ledger', $ledger);

            $ledger->update(['current_balance' => $newBalance]);

            return $entry;
        });
    }

    /**
     * Get a statement of ledger entries with optional date range filtering.
     */
    public function getStatement(Site $site, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $ledger = $this->getOrCreateLedger($site);
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
    public function reconcile(Site $site, int $userId): HouseLedger
    {
        $ledger = $this->getOrCreateLedger($site);
        $ledger->update([
            'last_reconciled_at' => now(),
            'reconciled_by' => $userId,
        ]);

        return $ledger;
    }
}
