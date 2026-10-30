<?php

namespace App\Services\Medication\Audit;

use App\Models\MedicationEvent;
use Illuminate\Support\Facades\DB;

final class MedicationEventChain
{
    /**
     * The head is locked to give the scan a consistent endpoint. Call inside a
     * transaction; callers must authorize the Site before verifying its chain.
     * No person facts or fingerprints are exposed by this summary.
     * @return array{site_id:int, intact:bool, events:int, latest_sequence:int, broken_at:int|null}
     */
    public function verify(int $siteId): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Verify a medication chain inside a transaction.');
        }
        DB::table('medication_event_heads')->insertOrIgnore(['site_id' => $siteId, 'sequence' => 0, 'hash' => str_repeat('0', 64)]);
        $head = DB::table('medication_event_heads')->where('site_id', $siteId)->lockForUpdate()->first();
        $sequence = 0;
        $hash = str_repeat('0', 64);
        $broken = null;
        foreach (MedicationEvent::query()->where('site_id', $siteId)->orderBy('sequence')->cursor() as $event) {
            if ($event->sequence !== $sequence + 1 || ! hash_equals($hash, $event->previous_hash) || ! $event->hasValidFingerprint()) {
                $broken = $event->sequence;
                break;
            }
            $sequence = $event->sequence;
            $hash = $event->hash;
        }
        if ($broken === null && ((int) $head->sequence !== $sequence || ! hash_equals($head->hash, $hash))) {
            $broken = $sequence + 1;
        }

        return ['site_id' => $siteId, 'intact' => $broken === null, 'events' => $sequence, 'latest_sequence' => (int) $head->sequence, 'broken_at' => $broken];
    }
}
