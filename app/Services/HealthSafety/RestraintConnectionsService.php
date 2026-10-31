<?php

namespace App\Services\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Each destination has independent current authority; missing links do not close any source. */
class RestraintConnectionsService
{
    public function __construct(private readonly RestraintAccessService $access, private readonly IncidentAccessService $incidents) {}

    public function incidentForLink(RestraintEvent $row, int $id, User $current, CurrentAuthorizationReads $reads): ClientIncident
    {
        $incident = $reads->query(ClientIncident::query())->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($this->sameIncidentContext($row, $incident), 404);
        $this->access->assertIncident($row, $incident, $current, $reads, true);
        abort_unless($this->incidents->canView($current, $incident, $reads) && Gate::forUser($current)->allows('update', $incident), 404);

        return $incident;
    }

    /** Existing capture-time association does not authorise a later link command. */
    public function incidentForCapture(RestraintEvent $row, int $id, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation = 'capture'): ClientIncident
    {
        abort_unless(in_array($operation, ['capture', 'respite_capture'], true), 403);
        $this->access->assertEvent($row, $current, $reads, $sites, $operation);
        $incident = $reads->query(ClientIncident::query())->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($this->sameIncidentContext($row, $incident) && $this->incidents->canView($current, $incident, $reads) && Gate::forUser($current)->allows('update', $incident), 404);

        return $incident;
    }

    public function project(RestraintEvent $row, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $result = ['client' => null, 'incident' => null, 'plan' => null, 'plan_version' => null, 'plan_approval' => null, 'stay' => null, 'health_safety' => null, 'control_room' => null];
        $client = $reads->query(Client::query())->find($row->client_id);
        if ($client && Gate::forUser($current)->allows('view', $client)) {
            $result['client'] = ['id' => (int) $client->id, 'href' => '/clients/'.$client->id];
        }
        if ($row->related_incident_id) {
            $incident = $reads->query(ClientIncident::query())->find($row->related_incident_id);
            if ($incident && $this->sameIncidentContext($row, $incident) && $this->incidents->canView($current, $incident, $reads)) {
                $result['incident'] = ['id' => (int) $incident->id, 'href' => '/incidents/'.$incident->id];
            }
        }
        if ($row->behaviour_support_plan_id) {
            $plan = $reads->query(BehaviourSupportPlan::query())->find($row->behaviour_support_plan_id);
            if ($plan) {
                $result['plan'] = $this->conceal(function () use ($plan, $current, $reads, $sites): array {
                    $this->access->assertPlan($plan, $current, $reads, $sites, 'view');

                    return ['id' => (int) $plan->id, 'href' => '/health-safety/restraints/plans/'.$plan->id];
                });
            }
        }
        if ($result['plan'] && $row->behaviour_support_plan_version_id) {
            $binding = $this->conceal(function () use ($row, $plan, $current, $reads, $sites): array {
                $version = $reads->query(BehaviourSupportPlanVersion::query())->findOrFail($row->behaviour_support_plan_version_id);
                $this->access->assertVersion($version, $plan, $current, $reads, $sites);
                abort_unless($version->kind === 'published' && (int) $version->client_id === (int) $row->client_id && (int) $version->site_id === (int) $row->site_id && $version->stay_id === ($row->stay_id === null ? null : (int) $row->stay_id), 404);
                $candidate = $reads->query(BehaviourSupportPlanVersion::query())->findOrFail($version->candidate_version_id);
                $this->access->assertVersion($candidate, $plan, $current, $reads, $sites);
                abort_unless($candidate->kind === 'candidate' && $candidate->site_id === $version->site_id && $candidate->stay_id === $version->stay_id && hash_equals($version->content_digest, $candidate->content_digest), 404);
                $approval = $reads->query(BehaviourSupportPlanApproval::query())->findOrFail($version->approval_id);
                abort_unless((int) $approval->behaviour_support_plan_id === (int) $plan->id && (int) $approval->plan_version_id === (int) $candidate->id && (int) $approval->client_id === (int) $row->client_id && (int) $approval->site_id === (int) $row->site_id && $approval->stay_id === $version->stay_id && $approval->decision === 'approve' && hash_equals($approval->content_digest, $candidate->content_digest) && (int) ($row->plan_authorization_snapshot['plan_id'] ?? 0) === (int) $plan->id && (int) ($row->plan_authorization_snapshot['approval_id'] ?? 0) === (int) $approval->id && (int) ($row->plan_authorization_snapshot['plan_version_id'] ?? 0) === (int) $version->id, 404);

                return ['version' => ['id' => (int) $version->id, 'href' => '/health-safety/restraints/plans/'.$plan->id.'?version='.$version->id], 'approval' => ['id' => (int) $approval->id, 'href' => '/health-safety/restraints/plans/'.$plan->id.'?approval='.$approval->id]];
            });
            $result['plan_version'] = $binding['version'] ?? null;
            $result['plan_approval'] = $binding['approval'] ?? null;
        }
        if ($row->stay_id && $current->canDo('respite.viewAny')) {
            $result['stay'] = $this->conceal(function () use ($row, $current, $reads): array {
                $request = clone request();
                $request->setUserResolver(fn () => $current);
                $stay = app(RespiteStayScope::class)->resolveAuthorizedStay($request, (int) $row->stay_id, false, $reads);
                abort_unless((int) $stay->client_id === (int) $row->client_id && app(RespiteStayScope::class)->siteId($stay) === (int) $row->site_id, 404);

                return ['id' => (int) $stay->id, 'href' => '/respite/stays/'.$stay->id];
            });
        }
        $hs = $reads->query(HsEvent::query())->whereIn('source_type', [RestraintEvent::class, $row->getMorphClass()])->where('source_id', $row->id)->where('event_category', HsEvent::CATEGORY_RESTRAINT)->where('client_id', $row->client_id)->where('site_id', $row->site_id)->where('staff_id', $row->created_by)->first();
        if ($hs && app(HsEventAccessService::class)->canView($hs, $current)) {
            $result['health_safety'] = ['id' => (int) $hs->id, 'href' => '/health-safety/events/'.$hs->id];
        }
        if ($hs?->control_room_alert_id) {
            $alert = $reads->query(ControlRoomAlert::query())->find($hs->control_room_alert_id);
            if ($alert && (int) ($alert->context['restraint_event_id'] ?? 0) === (int) $row->id && (int) $alert->client_id === (int) $row->client_id && (int) $alert->site_id === (int) $row->site_id && Gate::forUser($current)->allows('view', $alert)) {
                $result['control_room'] = ['id' => (int) $alert->id, 'href' => '/control-room/alerts/'.$alert->id];
            }
        }

        return $result;
    }

    private function sameIncidentContext(RestraintEvent $event, ClientIncident $incident): bool
    {
        return (int) $incident->client_id === (int) $event->client_id
            && $incident->site_id !== null && $event->site_id !== null
            && (int) $incident->site_id === (int) $event->site_id
            && ($incident->respite_stay_id === null ? null : (int) $incident->respite_stay_id)
                === ($event->stay_id === null ? null : (int) $event->stay_id);
    }

    private function conceal(callable $operation): ?array
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            return null;
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return null;
            } throw $error;
        }
    }
}
