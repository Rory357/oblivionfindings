<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class IncidentJourneyReconciler
{
    public function __construct(
        private readonly IncidentJourneyService $journeys,
        private readonly ReferenceNumberGenerator $references,
    ) {}

    public function reconcile(
        bool $apply = false,
        ?int $incidentId = null,
        int $chunk = 200,
    ): IncidentJourneyReconciliationResult {
        $result = new IncidentJourneyReconciliationResult($apply);
        $query = ClientIncident::query()
            ->where('status', '!=', 'draft')
            ->when($incidentId !== null, fn ($q) => $q->whereKey($incidentId))
            ->orderBy('id');

        $query->chunkById(max(1, $chunk), function (Collection $incidents) use ($apply, $result): void {
            foreach ($incidents as $incident) {
                $result->scanned++;

                try {
                    if ($apply) {
                        DB::transaction(function () use ($incident, $result): void {
                            $locked = ClientIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
                            $this->inspect($locked, $result, true);
                        }, 3);
                    } else {
                        $this->inspect($incident, $result, false);
                    }
                } catch (\Throwable $error) {
                    $result->failed((int) $incident->id, $error);
                }
            }
        });

        return $result;
    }

    private function inspect(
        ClientIncident $incident,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        $sourceEvents = HsEvent::query()
            ->where('source_type', ClientIncident::class)
            ->where('source_id', $incident->id)
            ->orderBy('id')
            ->get();
        $directEvent = $incident->hs_event_id
            ? HsEvent::query()->find($incident->hs_event_id)
            : null;

        if ($sourceEvents->count() > 1) {
            $result->ambiguous($incident->id, 'Multiple H&S events claim this incident source tuple.');
        }

        $sourceEvent = $sourceEvents->first();
        if ($directEvent && $sourceEvent && $directEvent->isNot($sourceEvent)) {
            $result->ambiguous($incident->id, 'Direct and source-tuple H&S links disagree.');
        }
        $event = $directEvent ?? $sourceEvent;

        $alerts = $this->alertCandidates($incident, $event);
        if ($alerts->count() > 1) {
            $result->issue($incident->id, 'duplicate_alert', 'More than one alert claims the incident journey.');
            $result->ambiguous($incident->id, 'Duplicate alert ownership requires manual review.');
        }
        $alert = $alerts->count() === 1 ? $alerts->first() : null;

        $this->repairSiteSnapshot($incident, $event, $result, $apply);

        if ($event === null) {
            $result->issue($incident->id, 'missing_hs', 'Submitted incident has no H&S event.');
            if ($apply && $alerts->count() <= 1) {
                $actor = $incident->reported_by ? User::query()->find($incident->reported_by) : null;
                $journey = $this->journeys->ensureForSubmittedIncident($incident->fresh(), $actor);
                $event = $journey->hsEvent;
                $alert = $journey->alert;
                $result->repaired('missing_hs');
            }
        }

        $this->repairLinks($incident, $event, $alert, $alerts->count() <= 1, $result, $apply);
        $this->repairReferences($incident, $event, $alert, $result, $apply);
        $this->repairWorksafeProjection($incident, $event, $result, $apply);
        $this->repairDismissedAlertTasks($incident, $alert, $result, $apply);
        $this->repairAcceptance($incident, $event, $result, $apply);
    }

    private function alertCandidates(ClientIncident $incident, ?HsEvent $event): Collection
    {
        $ids = collect([
            $incident->control_room_alert_id,
            $event?->control_room_alert_id,
        ])->filter()->map(fn ($id) => (int) $id);

        $contextIds = ControlRoomAlert::query()
            ->where(function ($query) use ($incident): void {
                $query->where('context->incident_id', $incident->id)
                    ->orWhere('context->normalized_data->incident_id', $incident->id);
            })
            ->pluck('id');

        return ControlRoomAlert::query()
            ->whereIn('id', $ids->merge($contextIds)->unique()->values())
            ->orderBy('id')
            ->get();
    }

    private function repairSiteSnapshot(
        ClientIncident $incident,
        ?HsEvent $event,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        if ($incident->site_id !== null) {
            return;
        }

        $incident->loadMissing(['shift:id,site_id', 'client:id,site_id']);
        $siteIds = collect([
            $event?->site_id,
            $incident->shift?->site_id,
            $incident->client?->site_id,
        ])->filter()->map(fn ($id) => (int) $id)->unique()->values();

        $result->issue($incident->id, 'missing_site', 'Incident has no frozen incident-time site.');
        if ($siteIds->count() !== 1) {
            $result->ambiguous($incident->id, 'Incident-time site cannot be inferred uniquely.');

            return;
        }

        if ($apply) {
            $incident->forceFill(['site_id' => $siteIds->first()])->saveQuietly();
            $result->repaired('missing_site');
        }
    }

    private function repairLinks(
        ClientIncident $incident,
        ?HsEvent $event,
        ?ControlRoomAlert $alert,
        bool $alertIsUnique,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        if ($event === null) {
            return;
        }

        $expectedAlertId = $alertIsUnique ? $alert?->id : null;
        $mismatch = (int) $incident->hs_event_id !== (int) $event->id
            || ($expectedAlertId !== null && (int) $incident->control_room_alert_id !== (int) $expectedAlertId)
            || ($expectedAlertId !== null && (int) $event->control_room_alert_id !== (int) $expectedAlertId)
            || ($alert !== null && data_get($alert->context, 'incident_id') != $incident->id);

        if (! $mismatch) {
            return;
        }

        $result->issue($incident->id, 'link_mismatch', 'Canonical direct links or alert context are incomplete.');
        if (! $apply || ! $alertIsUnique) {
            return;
        }

        $incident->forceFill(array_filter([
            'hs_event_id' => $event->id,
            'control_room_alert_id' => $expectedAlertId,
        ], fn ($value) => $value !== null))->saveQuietly();

        $event->forceFill([
            'source_type' => ClientIncident::class,
            'source_id' => $incident->id,
            'idempotency_key' => HsEvent::buildIdempotencyKey(
                ClientIncident::class,
                $incident->id,
                $event->event_category,
            ),
            'control_room_alert_id' => $expectedAlertId,
        ])->saveQuietly();

        if ($alert !== null) {
            $alert->forceFill([
                'context' => array_replace_recursive((array) $alert->context, [
                    'incident_id' => $incident->id,
                ]),
            ])->saveQuietly();
        }
        $result->repaired('link_mismatch');
    }

    private function repairReferences(
        ClientIncident $incident,
        ?HsEvent $event,
        ?ControlRoomAlert $alert,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        if ($incident->reference_number && ($event === null || $event->reference_number) && ($alert === null || $alert->reference_number)) {
            return;
        }

        $result->issue($incident->id, 'missing_reference', 'One or more journey records have no official reference.');
        if (! $apply) {
            return;
        }

        if (! $incident->reference_number) {
            $incident->forceFill(['reference_number' => $this->references->next(ClientIncident::REFERENCE_PREFIX)])->saveQuietly();
        }
        if ($event !== null && ! $event->reference_number) {
            $event->forceFill(['reference_number' => HsEvent::generateReferenceNumber()])->saveQuietly();
        }
        if ($alert !== null && ! $alert->reference_number) {
            $alert->forceFill(['reference_number' => $this->references->next(ControlRoomAlert::REFERENCE_PREFIX)])->saveQuietly();
        }
        $result->repaired('missing_reference');
    }

    private function repairWorksafeProjection(
        ClientIncident $incident,
        ?HsEvent $event,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        if ($event === null) {
            return;
        }

        $expected = [
            'is_notifiable' => (bool) $event->worksafe_notifiable,
            'worksafe_notification_status' => $event->worksafe_status,
            'worksafe_notified_at' => $event->worksafe_notified_at,
            'worksafe_reference' => $event->worksafe_reference,
            'site_preserved' => (bool) $event->worksafe_site_preserved,
        ];
        $drifted = (bool) $incident->is_notifiable !== $expected['is_notifiable']
            || $incident->worksafe_notification_status !== $expected['worksafe_notification_status']
            || $incident->worksafe_reference !== $expected['worksafe_reference']
            || (bool) $incident->site_preserved !== $expected['site_preserved']
            || $incident->worksafe_notified_at?->getTimestamp() !== $event->worksafe_notified_at?->getTimestamp();

        if (! $drifted) {
            return;
        }

        $result->issue($incident->id, 'worksafe_drift', 'Incident compatibility fields disagree with authoritative H&S WorkSafe state.');
        if ($apply) {
            $incident->forceFill($expected)->saveQuietly();
            $result->repaired('worksafe_drift');
        }
    }

    private function repairDismissedAlertTasks(
        ClientIncident $incident,
        ?ControlRoomAlert $alert,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        if ($alert === null || $alert->status !== ControlRoomAlert::STATUS_DISMISSED) {
            return;
        }

        $active = AlertTask::query()
            ->where('alert_id', $alert->id)
            ->whereNotIn('status', AlertTask::TERMINAL_STATUSES);
        if (! $active->exists()) {
            return;
        }

        $result->issue($incident->id, 'dismissed_active', 'Dismissed alert still owns active operational tasks.');
        if ($apply) {
            $active->update([
                'status' => AlertTask::STATUS_CANCELLED,
                'completed_at' => null,
                'updated_at' => now(),
            ]);
            $result->repaired('dismissed_active');
        }
    }

    private function repairAcceptance(
        ClientIncident $incident,
        ?HsEvent $event,
        IncidentJourneyReconciliationResult $result,
        bool $apply,
    ): void {
        if ($event === null
            || $event->handover_status === HsEvent::HANDOVER_ACCEPTED
            || $event->handover_status === HsEvent::HANDOVER_NOT_REQUIRED
        ) {
            return;
        }

        if ($event->owner_user_id === null && $event->status === HsEvent::STATUS_OPEN) {
            return;
        }

        $result->issue($incident->id, 'acceptance_backfill', 'Managed H&S event has no recorded handover acceptance.');
        if ($event->owner_user_id === null) {
            $result->ambiguous($incident->id, 'Managed H&S event has no owner to attribute acceptance to.');

            return;
        }

        if ($apply) {
            $event->forceFill([
                'handover_status' => HsEvent::HANDOVER_ACCEPTED,
                'accepted_by_user_id' => $event->owner_user_id,
                'accepted_at' => $event->updated_at ?? now(),
                'acceptance_notes' => 'Backfilled from existing managed H&S ownership.',
            ])->saveQuietly();
            $result->repaired('acceptance_backfill');
        }
    }
}
