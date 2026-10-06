<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** The authoritative close transition, shared with medication-linked incidents. */
final class IncidentClosureService
{
    public function __construct(
        private readonly IncidentJourneyService $journeys,
        private readonly IncidentAlertLifecycleSignalService $signals,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return array{ClientIncident, ?string, ?int} */
    public function close(ClientIncident $incident, User $actor, array $data, array $siteBypassPermissions): array
    {
        Gate::forUser($actor)->authorize('close', $incident);
        $this->siteAccess->assertCanAccessClientIncident($actor, $incident, $siteBypassPermissions);

        return DB::transaction(function () use ($incident, $actor, $data, $siteBypassPermissions): array {
            $locked = ClientIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('close', $locked);
            $this->siteAccess->assertCanAccessClientIncident($actor, $locked, $siteBypassPermissions);
            abort_unless($locked->status === 'reviewed', 403);
            try {
                $journey = $this->journeys->ensureForSubmittedIncident($locked, $actor);
                $locked = $journey->incident;
                $gate = $this->journeys->closeGate($locked);
            } catch (\DomainException) {
                abort(404);
            }
            if (! $gate->allowed) {
                return [$locked, implode(' ', $gate->blockers()), null];
            }
            $at = now()->startOfSecond();
            $locked->update([
                'status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => $at,
                'closed_outcome' => $data['closed_outcome'], 'closed_notes' => $data['closed_notes'] ?? null,
            ]);
            $outbox = $this->signals->recordClose($locked, $journey, $actor, $at, $data);

            return [$locked, null, $outbox->id];
        }, 3);
    }
}
