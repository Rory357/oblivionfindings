<?php

namespace App\Services\Sites;

use App\Models\HouseLedger;
use App\Models\HouseLedgerEntry;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class HouseLedgerPresenter
{
    public static function payload(
        Site $site,
        HouseLedger $ledger,
        LengthAwarePaginator $entries,
        ?User $user,
        array $filters = ['from' => null, 'to' => null],
    ): array {
        return [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'display_type' => $site->display_type ?? $site->type,
            ],
            'ledger' => self::ledger($ledger),
            'entries' => self::entries($entries),
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'canCreate' => (bool) $user?->canDo('sites.ledger.create'),
            'canManage' => (bool) $user?->canDo('sites.ledger.manage'),
        ];
    }

    public static function ledger(HouseLedger $ledger): array
    {
        return [
            'id' => $ledger->id,
            'opening_balance' => (float) $ledger->opening_balance,
            'current_balance' => (float) $ledger->current_balance,
            'currency' => $ledger->currency,
            'last_reconciled_at' => $ledger->last_reconciled_at?->toISOString(),
            'reconciled_by' => $ledger->reconciled_by,
        ];
    }

    public static function entries(LengthAwarePaginator $entries): array
    {
        return [
            'data' => $entries->getCollection()
                ->map(fn (HouseLedgerEntry $entry) => self::entry($entry))
                ->values()
                ->all(),
            'links' => [
                'prev' => $entries->previousPageUrl(),
                'next' => $entries->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'from' => $entries->firstItem(),
                'to' => $entries->lastItem(),
            ],
        ];
    }

    public static function entry(HouseLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_type' => $entry->entry_type,
            'category' => $entry->category,
            'description' => $entry->description,
            'reference' => $entry->reference,
            'amount' => (float) $entry->amount,
            'running_balance' => (float) $entry->running_balance,
            'entry_date' => $entry->entry_date?->toDateString(),
            'recorded_by' => $entry->recordedBy ? [
                'id' => $entry->recordedBy->id,
                'name' => $entry->recordedBy->name,
            ] : null,
            'approved_by' => $entry->approvedBy ? [
                'id' => $entry->approvedBy->id,
                'name' => $entry->approvedBy->name,
            ] : null,
            'approved_at' => $entry->approved_at?->toISOString(),
            'approval_state' => $entry->approved_at ? 'approved' : 'pending',
            'notes' => $entry->notes,
            'attachments' => $entry->attachments ?? [],
        ];
    }
}
