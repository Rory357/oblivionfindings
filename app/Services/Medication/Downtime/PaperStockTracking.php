<?php

namespace App\Services\Medication\Downtime;

use App\Models\ClientMedication;
use App\Models\MedicationFollowup;
use App\Models\MedicationPaperEntry;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Main's decision D2 (EA-001, Lane C1): a paper dose given while the
 * medicine's stock wasn't yet pack-tracked is posted to the clinical record
 * without pack evidence. Nothing is taken off stock or the register for it
 * (a later counted opening already reflects what was physically left);
 * instead the medicine is flagged for a counted stock recount: a lead
 * follow-up ("stock-discrepancy:paper-{entry}") that a P06 count or a
 * witnessed controlled count completes.
 *
 * Whether a downtime can be finished while those recounts are outstanding
 * is the organisation's setting (DowntimeSettings).
 */
final class PaperStockTracking
{
    public const SETTLEMENT = 'untracked_recount';

    private const SOURCE = 'stock-discrepancy';

    /** Pack tracking was not established for this stock at the paper time. */
    public static function untrackedAt(ClientMedication $order, CarbonInterface $at): bool
    {
        $stock = $order->stock()->first();

        return $stock === null || $stock->lots_started_at === null || $stock->lots_started_at->greaterThan($at);
    }

    public static function untrackedEntry(MedicationPaperEntry $entry, ClientMedication $order): bool
    {
        return $entry->outcome === 'given' && self::untrackedAt($order, $entry->given_at);
    }

    public static function sourceKey(int $entryId): string
    {
        return self::SOURCE.':paper-'.$entryId;
    }

    /** Raise the lead follow-up to count this medicine's stock (idempotent per paper entry). */
    public function flagRecount(MedicationPaperEntry $entry, ClientMedication $order): void
    {
        $order->loadMissing('client');
        app(MedicationFollowupService::class)->ensureForSource(
            self::SOURCE, 'paper-'.$entry->id, $order->client, $order, null, null, null,
            ['paper_entry_id' => (int) $entry->id, 'downtime_id' => (int) $entry->downtime_id, 'recount' => true,
                'source_url' => self::recountUrl($order)],
        );
    }

    /** Where the count happens: the witnessed register, or P06 Counts. */
    public static function recountUrl(ClientMedication $order): string
    {
        return $order->controlled_drug
            ? '/emar/controlled?'.http_build_query(['client_id' => (int) $order->client_id, 'client_medication_id' => (int) $order->id])
            : '/emar/stock/packs?'.http_build_query(['view' => 'counts', 'medication_id' => (int) $order->id]);
    }

    /**
     * A count of this medicine settles every open paper recount for it.
     * Called inside the count's own transaction, after the count is written.
     */
    public function completeRecounts(ClientMedication $order, User $actor, array $facts, ?array &$auditEvents = null): void
    {
        $keys = MedicationFollowup::query()->where('client_medication_id', $order->id)
            ->where('source_key', 'like', self::SOURCE.':paper-%')->whereNull('completed_at')->pluck('source_key');
        foreach ($keys as $key) {
            app(MedicationFollowupService::class)->completeFromSource($key, $actor, 'recounted', $facts, $auditEvents);
        }
    }

    /**
     * Posted untracked paper doses in this downtime still waiting for their recount.
     *
     * @return Collection<int, MedicationPaperEntry>
     */
    public function outstandingRecounts(int $downtimeId): Collection
    {
        $entries = MedicationPaperEntry::query()->where('downtime_id', $downtimeId)->where('outcome', 'given')->whereHas('posting')->get();
        $open = MedicationFollowup::query()->whereIn('source_key', $entries->map(fn ($entry) => self::sourceKey((int) $entry->id))->all())
            ->whereNull('completed_at')->pluck('source_key')->all();

        return $entries->filter(fn (MedicationPaperEntry $entry) => in_array(self::sourceKey((int) $entry->id), $open, true))->values();
    }

    /** For the finish message: one line per medicine still to recount. */
    public static function describe(Collection $entries): string
    {
        return $entries->map(fn (MedicationPaperEntry $entry) => ($entry->snapshot['medicine'] ?? 'A medicine').' for '.($entry->snapshot['person'] ?? 'a person'))
            ->unique()->implode('; ');
    }
}
