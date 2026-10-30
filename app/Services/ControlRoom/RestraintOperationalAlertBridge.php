<?php

namespace App\Services\ControlRoom;

use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\RestraintEvent;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Internal source delivery; the caller retains current source/actor authority. */
final class RestraintOperationalAlertBridge
{
    private const SEVERITY_RANK = ['low' => 0, 'medium' => 1, 'normal' => 1, 'high' => 2, 'critical' => 3, 'urgent' => 3];

    public function __construct(private readonly IncidentAlertOperationalInitializer $operations) {}

    public function deliver(string $severity, array $context): ControlRoomAlert
    {
        $eventId = $context['restraint_event_id'] ?? null;
        $revision = $context['source_revision'] ?? null;
        $deliveryId = $context['delivery_id'] ?? null;
        if (! is_int($eventId) || $eventId < 1 || ! is_int($revision) || $revision < 1
            || ! is_string($deliveryId) || ! Str::isUuid($deliveryId)) {
            throw new InvalidArgumentException('Exact restraint source and delivery identity are required.');
        }

        return DB::transaction(function () use ($severity, $context, $eventId, $revision, $deliveryId): ControlRoomAlert {
            // Serialises concurrent deliveries for this source, including first creation.
            $event = RestraintEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if (! $event
                || ! array_key_exists('client_id', $context) || $event->client_id !== $context['client_id']
                || ! array_key_exists('site_id', $context) || $event->site_id !== $context['site_id']) {
                throw new InvalidArgumentException('The operational delivery does not match the current restraint source.');
            }

            $alerts = ControlRoomAlert::query()->where('source', 'operations')
                ->where('alert_type', 'operations.restraint_event')
                ->where('context->restraint_event_id', $eventId)
                ->where('client_id', $event->client_id)->where('site_id', $event->site_id)
                ->orderByDesc('id')->lockForUpdate()->get();
            foreach ($alerts as $accepted) {
                $receipts = $accepted->context['source_deliveries'] ?? [];
                if (! is_array($receipts)) {
                    throw new RuntimeException('The retained source response receipts need repair.');
                }
                foreach ($receipts as $receipt) {
                    if (($receipt['delivery_id'] ?? null) === $deliveryId) {
                        if (($receipt['source_revision'] ?? null) !== $revision || ($receipt['severity'] ?? null) !== $severity) {
                            throw new InvalidArgumentException('The delivery identity was already used for different source facts.');
                        }
                        $this->assertPersisted($accepted, $event, $revision, $deliveryId, false);

                        return $accepted;
                    }
                }
            }

            // Accepted UUID replay above is historical. A new acceptance must
            // still match the exact current source revision and risk policy.
            $requiredSeverity = $event->injury_occurred ? 'high' : ($event->within_support_plan === false ? 'medium' : null);
            if ((int) $event->source_revision !== $revision || $requiredSeverity !== $severity) {
                throw new InvalidArgumentException('The operational delivery is superseded or does not match source risk.');
            }

            $previous = $alerts->first(fn ($candidate): bool => collect($candidate->context['source_deliveries'] ?? [])
                ->contains(fn ($receipt): bool => ($receipt['source_revision'] ?? null) === $revision)) ?? $alerts->first();
            $higherRisk = $previous && self::SEVERITY_RANK[$severity] > (self::SEVERITY_RANK[$previous->severity] ?? PHP_INT_MAX);
            $create = ! $previous || ($higherRisk && $previous->isTerminal());
            $acceptedAt = now()->utc()->toIso8601String();
            $receipt = ['source_revision' => $revision, 'delivery_id' => $deliveryId, 'severity' => $severity, 'accepted_at' => $acceptedAt];
            if ($create) {
                $alert = ControlRoomAlert::query()->create([
                    'source' => 'operations', 'alert_type' => 'operations.restraint_event',
                    'severity' => $severity, 'status' => ControlRoomAlert::STATUS_OPEN,
                    'client_id' => $event->client_id, 'site_id' => $event->site_id, 'triggered_at' => now(),
                    'context' => [
                        'restraint_event_id' => $eventId, 'client_id' => $event->client_id, 'site_id' => $event->site_id,
                        'creation_source_revision' => $revision, 'source_revision' => $revision,
                        'source_deliveries' => [$receipt], 'previous_alert_id' => $previous?->id,
                    ],
                ]);
                if (! $alert->exists || ! $alert->id) {
                    throw new RuntimeException('The source response was not durably created.');
                }
                $this->operations->initialiseNewAlert($alert);
            } else {
                $alert = $previous;
                $retained = $alert->context ?? [];
                if (! array_key_exists('creation_source_revision', $retained)) {
                    // An older response did not capture a creation revision.
                    $retained['creation_source_revision'] = null;
                }
                $retained['source_revision'] = $revision;
                $retained['source_deliveries'] = [...($retained['source_deliveries'] ?? []), $receipt];
                $changes = ['context' => $retained];
                if ($higherRisk) {
                    $changes['severity'] = $severity;
                    // Preserve any explicit higher operational priority.
                    if (! $alert->priority || (self::SEVERITY_RANK[$alert->priority] ?? PHP_INT_MAX) < self::SEVERITY_RANK[$severity]) {
                        $changes['priority'] = $severity;
                    }
                }
                $alert->forceFill($changes)->saveQuietly();
                if ($higherRisk) {
                    $this->operations->initialiseNewAlert($alert);
                }
            }

            AuditLogger::logOrFail('control_room.restraint_source_accepted', $alert, [
                'restraint_event_id' => $eventId, 'source_revision' => $revision, 'delivery_id' => $deliveryId,
                'severity' => $severity, 'new_response' => $create, 'previous_alert_id' => $create ? $previous?->id : null,
            ], systemActor: true);
            $fresh = $alert->fresh();
            if (! $fresh) {
                throw new RuntimeException('The accepted source response is unavailable.');
            }
            $this->assertPersisted($fresh, $event, $revision, $deliveryId);

            return $fresh;
        });
    }

    private function assertPersisted(ControlRoomAlert $alert, RestraintEvent $event, int $revision, string $deliveryId, bool $currentRevision = true): void
    {
        $context = $alert->context ?? [];
        $receipts = array_filter($context['source_deliveries'] ?? [],
            fn ($receipt): bool => is_array($receipt) && ($receipt['source_revision'] ?? null) === $revision
                && ($receipt['delivery_id'] ?? null) === $deliveryId);
        if ((int) ($context['restraint_event_id'] ?? 0) !== (int) $event->id || $alert->client_id !== $event->client_id
            || $alert->site_id !== $event->site_id || ($currentRevision && ($context['source_revision'] ?? null) !== $revision) || count($receipts) !== 1) {
            throw new RuntimeException('Exact source response acceptance was not durably confirmed.');
        }
        if (AuditLog::query()->where('action', 'control_room.restraint_source_accepted')
            ->where('auditable_type', $alert->getMorphClass())->where('auditable_id', $alert->id)
            ->where('meta->delivery_id', $deliveryId)->where('meta->source_revision', $revision)->count() !== 1) {
            throw new RuntimeException('The source response acceptance audit is unavailable.');
        }
    }
}
