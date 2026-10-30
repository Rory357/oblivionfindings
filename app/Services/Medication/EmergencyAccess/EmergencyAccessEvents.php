<?php

namespace App\Services\Medication\EmergencyAccess;

use App\Models\ClientBreakGlassAccess;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** The P10-to-P09 adapter. Every caller appends only after its last domain write. */
class EmergencyAccessEvents
{
    public function __construct(private readonly MedicationEventRecorder $recorder) {}

    public function record(ClientBreakGlassAccess $grant, string $action, ?User $actor, array $detail = [], ?CarbonInterface $occurredAt = null): void
    {
        $this->recordMany($grant, [['action' => $action, 'detail' => $detail, 'at' => $occurredAt]], $actor);
    }

    public function recordMany(ClientBreakGlassAccess $grant, array $events, ?User $actor): void
    {
        $this->recorder->appendMany(array_map(fn (array $event) => new MedicationEventData(
            siteId: (int) $grant->client->site_id,
            kind: 'emergency_access',
            subjectType: 'emergency_access',
            subjectId: (string) $grant->id,
            actorId: $actor?->id,
            occurredAt: CarbonImmutable::instance($event['at'] ?? now()),
            summary: 'Emergency access '.str_replace('_', ' ', $event['action']).' — EA-'.$grant->id,
            facts: ['action' => $event['action'], 'break_glass_access_id' => (int) $grant->id] + array_intersect_key($event['detail'] ?? [], array_flip(['count', 'window_days', 'expires_at', 'ended_how', 'outcome', 'corrects_review_id'])),
            clientId: (int) $grant->client_id,
        ), $events));
    }
}
