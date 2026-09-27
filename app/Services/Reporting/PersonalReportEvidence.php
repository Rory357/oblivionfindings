<?php

namespace App\Services\Reporting;

use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\ClientGeofenceMonitor;
use App\Models\ClientGeofenceRule;
use App\Models\ClientGeofenceRuleVersion;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\LoneWorkerSession;
use App\Services\HealthSafety\LoneWorkerSignalService;
use Carbon\CarbonImmutable;

/** Read canonical retained evidence; never replay signal projection or start collection. */
final class PersonalReportEvidence
{
    public function clientAlert(ControlRoomAlert $alert, DeviceAssignment $assignment, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $origin = $alert->originSignal;
        if (! $origin || (int) $origin->client_id !== (int) $assignment->assignable_id || (int) $origin->site_id !== (int) $assignment->custody_site_id
            || (int) data_get($alert->context, 'signal_id') !== (int) $origin->id) {
            return false;
        }
        $fleetId = data_get($origin->normalized_data, 'fleet_signal_id');
        if (! $fleetId || (int) data_get($alert->context, 'normalized_data.fleet_signal_id') !== (int) $fleetId) {
            return false;
        }
        $fleet = FleetSignal::find($fleetId);
        $event = $fleet ? $this->event($fleet, $assignment, $from, $to) : null;
        if (! $fleet || ! $event) {
            return false;
        }
        if (isset($fleet->payload['assignment_id']) && (int) $fleet->payload['assignment_id'] !== (int) $assignment->id) {
            return false;
        }
        if ($fleet->signal_type === 'resident.zone_breach') {
            return $this->zone($fleet, $assignment, $from, $to) !== null;
        }
        if ($fleet->signal_type === 'resident.fall_detected') {
            return $event->event_type === 'fall_detected' && (int) data_get($fleet->payload, 'assignment_id') === (int) $assignment->id;
        }

        return in_array($fleet->signal_type, ['sos', 'panic', 'resident.sos'], true) && in_array($event->event_type, ['sos', 'panic'], true);
    }

    public function zone(FleetSignal $signal, DeviceAssignment $assignment, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $event = $this->event($signal, $assignment, $from, $to);
        $monitor = ClientGeofenceMonitor::find(data_get($signal->payload, 'client_zone_monitor_id'));
        if (! $event || ! $monitor || (int) $monitor->assignment_id !== (int) $assignment->id || (int) $monitor->consent_id !== (int) $assignment->consent_id
            || $event->occurred_at->lt($monitor->started_at) || ($monitor->ended_at && $event->occurred_at->gte($monitor->ended_at))) {
            return null;
        }
        $rule = ClientGeofenceRule::find($monitor->rule_id);
        $version = ClientGeofenceRuleVersion::find($monitor->version_id);
        if (! $rule || ! $version || (int) $rule->client_id !== (int) $assignment->assignable_id || (int) $rule->site_id !== (int) $assignment->custody_site_id || (int) $version->rule_id !== (int) $rule->id) {
            return null;
        }

        return ['reference' => 'fleet_signal:'.$signal->id, 'date' => $event->occurred_at->setTimezone('Pacific/Auckland')->toDateString(),
            'resource' => $assignment->device->name, 'site' => null, 'status' => 'Reported zone breach', 'observed_at' => $event->occurred_at->toISOString(),
            'received_at' => $event->received_at?->toISOString(), 'event_type' => $signal->signal_type, 'rule_id' => (string) $rule->id,
            'geometry_version' => (string) $version->id, 'accuracy' => $event->accuracy_m];
    }

    public function staffAlert(ControlRoomAlert $alert, LoneWorkerSession $session): bool
    {
        $origin = $alert->originSignal;
        $normalized = data_get($alert->context, 'normalized_data');
        if (! $origin || $alert->alert_type !== LoneWorkerSignalService::canonicalAlertType($origin->signal_type_code)) {
            return false;
        }
        if (! $origin || ! is_array($normalized) || (int) data_get($alert->context, 'signal_id') !== (int) $origin->id
            || $origin->site_id !== $alert->site_id || $origin->client_id !== $alert->client_id) {
            return false;
        }
        foreach ([$origin->normalized_data, $normalized] as $context) {
            if (! is_array($context) || ($context['source_module'] ?? null) !== 'lone_worker' || ($context['signal_type'] ?? null) !== $origin->signal_type_code
                || (int) ($context['lone_worker_session_id'] ?? 0) !== (int) $session->id || (int) ($context['worker_user_id'] ?? 0) !== (int) $session->user_id
                || (int) ($context['site_id'] ?? 0) !== (int) $session->site_id || ($context['client_id'] ?? null) !== $session->client_id) {
                return false;
            }
        }

        return true;
    }

    private function event(FleetSignal $signal, DeviceAssignment $assignment, CarbonImmutable $from, CarbonImmutable $to): ?FleetTelemetryEvent
    {
        if ((int) $signal->device_id !== (int) $assignment->device_id) {
            return null;
        }
        $event = FleetTelemetryEvent::whereKey($signal->source_event_id ?: data_get($signal->payload, 'event_id'))
            ->where('device_id', $assignment->device_id)->where('consent_blocked', false)->whereBetween('occurred_at', [$from, $to])->first();
        if (! $event || (int) $event->asset_id !== (int) $signal->asset_id || ! $event->occurred_at->equalTo($signal->occurred_at)) {
            return null;
        }

        return $event;
    }
}
