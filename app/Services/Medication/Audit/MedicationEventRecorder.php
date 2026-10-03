<?php

namespace App\Services\Medication\Audit;

use App\Models\Client;
use App\Models\MedicationEvent;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Head locks are the LAST locks of the caller-owned, retryable domain transaction. */
class MedicationEventRecorder
{
    public function append(MedicationEventData $data): MedicationEvent
    {
        return $this->appendMany([$data])[0];
    }

    /** @param list<MedicationEventData> $items @return list<MedicationEvent> */
    public function appendMany(array $items): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Append medication events inside the domain transaction, after all domain writes.');
        }
        if ($items === []) {
            return [];
        }
        $siteIds = array_values(array_unique(array_map(fn (MedicationEventData $item) => $item->siteId, $items)));
        sort($siteIds, SORT_NUMERIC);
        if (Site::query()->whereIn('id', $siteIds)->count() !== count($siteIds)) {
            throw new LogicException('A medication event must belong to an existing canonical Site.');
        }
        $clientIds = array_values(array_unique(array_filter(array_map(fn ($item) => $item->clientId, $items))));
        $clients = Client::query()->whereIn('id', $clientIds)->pluck('site_id', 'id');
        foreach ($items as $item) {
            if ($item->clientId !== null && (int) ($clients[$item->clientId] ?? 0) !== $item->siteId) {
                throw new LogicException('The medication event person must belong to its canonical Site.');
            }
            if ($item->correctsEventId !== null && ! MedicationEvent::query()->whereKey($item->correctsEventId)->where('site_id', $item->siteId)->exists()) {
                throw new LogicException('A correction must reference an event at the same Site.');
            }
            MedicationEventFingerprint::of($item->facts);
        }
        $heads = [];
        foreach ($siteIds as $siteId) {
            DB::table('medication_event_heads')->insertOrIgnore(['site_id' => $siteId, 'sequence' => 0, 'hash' => str_repeat('0', 64)]);
            $heads[$siteId] = DB::table('medication_event_heads')->where('site_id', $siteId)->lockForUpdate()->first();
        }
        $events = [];
        foreach ($items as $item) {
            $head = $heads[$item->siteId];
            $event = new MedicationEvent([
                'site_id' => $item->siteId, 'sequence' => (int) $head->sequence + 1,
                'client_id' => $item->clientId, 'actor_id' => $item->actorId,
                'kind' => $item->kind, 'subject_type' => $item->subjectType, 'subject_id' => $item->subjectId,
                'occurred_at' => $item->occurredAt->utc()->format('Y-m-d H:i:s.u'),
                'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'summary' => $item->summary, 'facts' => $item->facts, 'controlled' => $item->controlled,
                'corrects_event_id' => $item->correctsEventId, 'previous_hash' => $head->hash,
            ]);
            $event->hash = MedicationEventFingerprint::of($event->fingerprintPayload());
            $event->saveOrFail();
            DB::table('medication_event_heads')->where('site_id', $item->siteId)->update(['sequence' => $event->sequence, 'hash' => $event->hash]);
            $head->sequence = $event->sequence;
            $head->hash = $event->hash;
            $events[] = $event;
        }

        return $events;
    }
}
