<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\ItTicket;
use App\Models\Site;
use BackedEnum;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MonitoringIncidentEvidenceService
{
    public function __construct(
        private readonly ItTicketLinkService $links,
    ) {}

    public function captureFleetIfMissing(ItTicket $ticket, FleetSignal $offline, ?ControlRoomAlert $alert): MonitoringIncidentEvidenceSnapshot
    {
        return DB::transaction(function () use ($ticket, $offline, $alert): MonitoringIncidentEvidenceSnapshot {
            $ticket = ItTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $existing = MonitoringIncidentEvidenceSnapshot::query()->where('it_ticket_id', $ticket->id)->first();
            if ($existing) {
                return $existing;
            }
            $offline = FleetSignal::query()->whereKey($offline->id)->lockForUpdate()->firstOrFail();
            $alert = $alert ? ControlRoomAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail() : null;
            $siteId = $this->links->canonicalFleetSiteId($offline, $alert);
            if ($siteId === null || (int) $ticket->site_id !== $siteId || ! $this->links->isMonitoringWork($ticket, $alert)
                || $ticket->work_type !== 'incident' || $ticket->is_organisation_wide) {
                throw new DomainException('source_scope_changed');
            }
            $device = Device::query()->whereKey($offline->device_id)->firstOrFail();
            $asset = Asset::query()->whereKey($offline->asset_id)->firstOrFail();
            foreach ([[$device, 'affected_device'], [$asset, 'affected_asset'], ...($alert ? [[$alert, 'source_alert']] : [])] as [$record, $relationship]) {
                if (! $ticket->links()->where('relationship', $relationship)->where('linkable_type', $record->getMorphClass())
                    ->where('linkable_id', $record->id)->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION)->where('context->fleet_signal_id', $offline->id)->exists()) {
                    throw new DomainException('canonical_evidence_unavailable');
                }
            }
            $capturedAt = now();
            $snapshot = [
                'captured_at' => $capturedAt->toIso8601String(),
                'site' => ['id' => $siteId, 'name' => $this->safe(Site::query()->findOrFail($siteId)->name)],
                'alert' => $alert ? ['id' => $alert->id, 'reference' => $this->safe($alert->reference_number),
                    'type' => $this->safe($alert->alert_type), 'severity' => $this->safe($alert->severity),
                    'source' => $this->safe($alert->source), 'triggered_at' => $alert->triggered_at?->toIso8601String()] : null,
                'ticket' => ['id' => $ticket->id, 'reference' => $ticket->reference, 'title' => $this->safe($ticket->title)],
                'asset' => ['id' => $asset->id, 'name' => $this->safe($asset->name), 'asset_tag' => $this->safe($asset->asset_tag)],
                'device' => ['id' => $device->id, 'uid' => $this->safe($device->device_uid), 'name' => $this->safe($device->name),
                    'domain' => $this->value($device->domain), 'category' => $this->safe($device->category),
                    'subcategory' => $this->safe($device->subcategory), 'status' => $this->value($device->status),
                    'health_status' => $this->value($device->health_status), 'last_seen_at' => $device->last_seen_at?->toIso8601String()],
                'observation' => ['id' => $offline->id, 'event_type' => 'device.offline', 'source' => 'fleet',
                    'severity' => $alert?->severity, 'occurred_at' => $offline->occurred_at?->toIso8601String(),
                    'message' => MonitoringTechnicalSummary::observation('device.offline'),
                    'availability_episode_key' => $offline->idempotency_key],
            ];

            return MonitoringIncidentEvidenceSnapshot::query()->create([
                'it_ticket_id' => $ticket->id, 'control_room_alert_id' => $alert?->id,
                'device_id' => $device->id, 'device_event_id' => null, 'fleet_signal_id' => $offline->id,
                'asset_id' => $asset->id, 'site_id' => $siteId, 'evidence_version' => 3,
                'captured_at' => $capturedAt, 'snapshot' => $snapshot,
                'checksum' => MonitoringIncidentEvidenceSnapshot::checksumFor($snapshot),
            ]);
        });
    }

    public function captureIfMissing(
        ItTicket $ticket,
        Device $device,
        ?ControlRoomAlert $alert,
        DeviceEvent $event,
        ?string $monitorCorrelationKey,
    ): MonitoringIncidentEvidenceSnapshot {
        return DB::transaction(function () use ($ticket, $device, $alert, $event, $monitorCorrelationKey): MonitoringIncidentEvidenceSnapshot {
            $ticket = ItTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $device = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $alert = $alert ? ControlRoomAlert::query()->whereKey($alert->getKey())->lockForUpdate()->firstOrFail() : null;
            $event = DeviceEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $existing = MonitoringIncidentEvidenceSnapshot::query()
                ->where('it_ticket_id', $ticket->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $siteId = $alert ? $this->links->canonicalMonitoringSiteId($device, $alert, true)
                : $this->links->canonicalDeviceSiteId($device, true);
            $canonicalLinks = $ticket->links()
                ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION)
                ->where(function ($query) use ($device, $alert): void {
                    $query
                        ->where(function ($deviceLink) use ($device): void {
                            $deviceLink
                                ->where('relationship', 'affected_device')
                                ->where('linkable_type', $device->getMorphClass())
                                ->where('linkable_id', $device->id);
                        })
                        ->when($alert !== null, fn ($links) => $links->orWhere(function ($alertLink) use ($alert): void {
                            $alertLink
                                ->where('relationship', 'source_alert')
                                ->where('linkable_type', $alert->getMorphClass())
                                ->where('linkable_id', $alert->id);
                        }));
                })
                ->count();

            if (! $this->links->isMonitoringWork($ticket, $alert)
                || $ticket->work_type !== 'incident'
                || $ticket->is_organisation_wide
                || $siteId === null
                || (int) $ticket->site_id !== $siteId
                || (int) $event->device_id !== (int) $device->id
                || $canonicalLinks !== ($alert === null ? 1 : 2)
                || ($alert === null && (! in_array($event->event_type, MonitoringIssueEpisode::FAILURE_TYPES, true)
                    || ! MonitoringWorkRouting::hasDirectSourceEvidence($event, $siteId)))) {
                throw new DomainException('Monitoring incident evidence is not canonical.');
            }

            $site = Site::query()->whereKey($siteId)->firstOrFail(['id', 'name']);
            $capturedAt = now();
            $snapshot = $this->snapshot(
                $ticket,
                $device,
                $alert,
                $event,
                $site,
                $capturedAt->toIso8601String(),
                $monitorCorrelationKey,
            );

            return MonitoringIncidentEvidenceSnapshot::query()->create([
                'control_room_alert_id' => $alert?->id,
                'it_ticket_id' => $ticket->id,
                'device_id' => $device->id,
                'device_event_id' => $event->id,
                'site_id' => $siteId,
                'evidence_version' => $alert === null ? 2 : 1,
                'captured_at' => $capturedAt,
                'snapshot' => $snapshot,
                'checksum' => MonitoringIncidentEvidenceSnapshot::checksumFor($snapshot),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(
        ItTicket $ticket,
        Device $device,
        ?ControlRoomAlert $alert,
        DeviceEvent $event,
        Site $site,
        string $capturedAt,
        ?string $monitorCorrelationKey,
    ): array {
        return [
            'captured_at' => $capturedAt,
            'site' => [
                'id' => $site->id,
                'name' => $this->safe($site->name),
            ],
            'alert' => $alert ? [
                'id' => $alert->id,
                'reference' => $this->safe($alert->reference_number),
                'type' => $this->safe($alert->alert_type),
                'severity' => $this->safe($alert->severity),
                'source' => $this->safe($alert->source),
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
            ] : null,
            'ticket' => [
                'id' => $ticket->id,
                'reference' => $this->safe($ticket->reference),
                'title' => $this->safe($ticket->title),
            ],
            'device' => [
                'id' => $device->id,
                'uid' => $this->safe($device->device_uid),
                'name' => $this->safe($device->name),
                'domain' => $this->value($device->domain),
                'category' => $this->safe($device->category),
                'subcategory' => $this->safe($device->subcategory),
                'status' => $this->value($device->status),
                'health_status' => $this->value($device->health_status),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            ],
            'observation' => [
                'id' => $event->id,
                'event_type' => $this->safe($event->event_type),
                'severity' => $this->safe($event->severity),
                'source' => $this->safe($event->source),
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'message' => MonitoringTechnicalSummary::observation($event->event_type),
                'monitor_correlation_key' => $monitorCorrelationKey,
                MonitoringIssueEpisode::field($event->event_type).'_key' => MonitoringIssueEpisode::fromEvent($event, (int) $site->id)['key'] ?? null,
            ],
        ];
    }

    private function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private function safe(mixed $value, int $limit = 255): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $clean = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', (string) $value) ?? '';
        $clean = preg_replace('/\s+/u', ' ', trim($clean)) ?? '';
        $clean = preg_replace(
            '/\b(authorization|api[_ -]?key|token|secret|password|community)\s*[:=]\s*[^\s,;]+/iu',
            '$1=[redacted]',
            $clean,
        ) ?? '';

        return $clean === '' ? null : Str::limit($clean, $limit, '');
    }
}
