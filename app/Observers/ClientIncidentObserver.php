<?php

namespace App\Observers;

use App\Domain\Governance\Services\IncidentEscalationService;
use App\Models\ClientIncident;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

class ClientIncidentObserver implements ShouldHandleEventsAfterCommit
{
    /** @var list<string> */
    private const JOURNEY_RELEVANT_FIELDS = [
        'status',
        'submitted_at',
        'type',
        'severity',
        'site_id',
        'client_id',
        'shift_id',
        'reported_by',
        'is_notifiable',
        'worksafe_notification_status',
        'worksafe_notified_at',
        'worksafe_reference',
        'site_preserved',
    ];

    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly IncidentEscalationService $governanceEscalation,
    ) {}

    public function created(ClientIncident $incident): void
    {
        if ($incident->status === 'draft') {
            return;
        }

        $this->ensureJourney($incident);
        $this->maybeEscalateToGovernance($incident);
    }

    public function updated(ClientIncident $incident): void
    {
        if ($incident->status === 'draft' || ! $this->journeyNeedsSynchronising($incident)) {
            return;
        }

        $this->ensureJourney($incident);
        $this->maybeEscalateToGovernance($incident);
    }

    private function journeyNeedsSynchronising(ClientIncident $incident): bool
    {
        if ($incident->wasChanged('status')
            && $incident->getOriginal('status') === 'draft'
            && $incident->status !== 'draft'
        ) {
            return true;
        }

        return $incident->wasChanged(self::JOURNEY_RELEVANT_FIELDS);
    }

    private function ensureJourney(ClientIncident $incident): void
    {
        try {
            $this->bridge->bridgeClientIncident($incident);
        } catch (\Throwable $exception) {
            Log::error('incident_journey_repair_required', [
                'incident_id' => $incident->id,
                'status' => $incident->status,
                'changed_fields' => array_keys($incident->getChanges()),
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Bridge a high/critical incident into the governance escalation track.
     * Idempotent — the service deduplicates by (client_incident_id, reason).
     */
    private function maybeEscalateToGovernance(ClientIncident $incident): void
    {
        try {
            $this->governanceEscalation->escalateClientIncident($incident);
        } catch (\Throwable $exception) {
            Log::warning('ClientIncidentObserver: governance escalation failed', [
                'incident_id' => $incident->id,
                'severity' => $incident->severity,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
