<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;

/** Candidate selection only. All effective routes remain subject to current record eligibility. */
final class IncidentRoutingResolver
{
    public function __construct(private readonly IncidentOwnerEligibility $eligibility, private readonly AuthorizationEvidenceLockService $authorization) {}

    public function resolve(ClientIncident $incident, array $snapshot, CurrentAuthorizationReads $reads, CarbonImmutable $at): array
    {
        $reads->assertActive();
        $scope = $this->eligibility->isRestricted($incident, $reads) ? 'restricted' : 'ordinary';
        $result = ['scope' => $scope, 'owner_user_id' => null, 'route' => null, 'reason_code' => 'configuration_missing',
            'triage_user_id' => null, 'escalation_user_id' => null, 'escalation_after_minutes' => null, 'monitored' => false];
        if (! ($snapshot['configuration_valid'] ?? false)) {
            $result['reason_code'] = ($snapshot['state'] ?? '') === 'repair_required' ? 'configuration_invalid' : 'configuration_missing';
            return $result;
        }
        $config = $snapshot['configuration'];
        $instant = $at->utc()->format('Y-m-d\TH:i:s.u\Z');
        $routes = array_values(array_filter($config['routes'], fn ($rule) => (int) $incident->site_id > 0 && $rule['site_id'] === (int) $incident->site_id
            && $rule['scope'] === $scope && $rule['effective_from'] <= $instant && ($rule['effective_until'] === null || $instant < $rule['effective_until'])));
        $result['reason_code'] = count($routes) > 1 ? 'routing_ambiguous' : 'route_missing';
        if (count($routes) === 1) {
            $rule = $routes[0];
            $candidates = [];
            if ($rule['cover_user_id'] !== null && $rule['cover_from'] <= $instant && $instant < $rule['cover_until']) {
                $candidates['configured_cover'] = $rule['cover_user_id'];
            }
            $candidates['configured_site_lead'] = $rule['lead_user_id'];
            $result['reason_code'] = 'recipient_unavailable';
            foreach ($candidates as $route => $id) {
                if ($this->eligible($id, $incident, $reads)) {
                    $result['owner_user_id'] = $id;
                    $result['route'] = $route;
                    $result['reason_code'] = 'eligible_configured_owner';
                    break;
                }
            }
        }
        if (($triage = $config['triage']) !== null) {
            foreach (['user_id' => 'triage_user_id', 'escalation_user_id' => 'escalation_user_id'] as $key => $field) {
                if ($this->eligible($triage[$key], $incident, $reads, true)) {
                    $result[$field] = $triage[$key];
                }
            }
            $result['escalation_after_minutes'] = $triage['escalation_after_minutes'];
            $result['monitored'] = $result['triage_user_id'] !== null && $result['escalation_user_id'] !== null;
        }
        return $result;
    }

    public function eligible(int $id, ClientIncident $incident, CurrentAuthorizationReads $reads, bool $triage = false): bool
    {
        if (! $reads->query(User::query()->whereKey($id))->exists()) {
            return false;
        }
        $candidate = $this->authorization->lockForUserWithoutWaiting($id, IncidentRoutingSettings::PERMISSIONS);
        return $this->eligibility->canOwn($candidate, $incident, $reads)
            && (! $triage || $this->eligibility->canManage($candidate, $incident, $reads));
    }
}
