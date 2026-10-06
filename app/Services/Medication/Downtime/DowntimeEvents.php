<?php

namespace App\Services\Medication\Downtime;

use App\Models\MedicationDowntime;
use App\Models\MedicationPaperEntry;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;

/** P09 adapter. Call last, inside the caller's retryable domain transaction. */
class DowntimeEvents
{
    public function __construct(private readonly MedicationEventRecorder $events) {}

    public function record(MedicationDowntime $downtime, string $action, User $actor, ?MedicationPaperEntry $entry = null, array $facts = [], ?int $clientId = null, bool $controlled = false): void
    {
        $this->events->append(new MedicationEventData(
            siteId: (int) $downtime->site_id,
            kind: 'downtime.'.$action,
            subjectType: $entry ? 'medication_paper_entry' : 'medication_downtime',
            subjectId: (string) ($entry?->id ?? $downtime->id),
            actorId: (int) $actor->id,
            occurredAt: CarbonImmutable::now('UTC'),
            summary: 'Downtime paper record: '.str_replace('_', ' ', $action),
            facts: ['downtime_id' => (int) $downtime->id] + $facts,
            clientId: $entry ? (int) $entry->client_id : $clientId,
            controlled: $controlled || (bool) ($entry?->snapshot['controlled'] ?? false),
        ));
    }

    public function packMade(User $actor, int $siteId, string $day, bool $controlled): void
    {
        $this->events->append(new MedicationEventData(
            siteId: $siteId, kind: 'export.downtime_pack', subjectType: 'medication_downtime_pack',
            subjectId: $siteId.':'.$day, actorId: (int) $actor->id,
            occurredAt: CarbonImmutable::now('UTC'), summary: 'Downtime pack made',
            facts: ['nz_date' => $day, 'purpose' => DowntimePackService::PURPOSE, 'controlled_pages_included' => $controlled],
        ));
    }
}
