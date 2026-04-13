<?php

namespace App\Services\HealthClinical;

use App\Models\ClientMedicalProfile;

/**
 * Aggregates clinical data for a client into a health summary.
 *
 * Read-only. Combines observations, events, protocols, and medical profile
 * into a single composite view for the Client Profile health summary card.
 */
class HealthSummaryService
{
    public function __construct(
        private readonly ClinicalObservationService $observationService,
        private readonly ClinicalEventService $eventService,
        private readonly ProtocolService $protocolService,
    ) {}

    public function forClient(int $clientId): array
    {
        $profile = ClientMedicalProfile::where('client_id', $clientId)->first();

        return [
            'medical_profile' => $profile ? [
                'gp_name' => $profile->gp_name,
                'gp_practice' => $profile->gp_practice,
                'allergies' => $profile->allergies,
                'disabilities' => $profile->disabilities,
                'blood_type' => $profile->blood_type,
            ] : null,
            'recent_observations' => $this->observationService->recentForClient($clientId, 7),
            'active_protocols' => $this->protocolService->forClient($clientId),
            'recent_events' => $this->recentEvents($clientId),
        ];
    }

    private function recentEvents(int $clientId): array
    {
        return \App\Models\ClinicalEvent::query()
            ->where('client_id', $clientId)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->with('reporter:id,name')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->toArray();
    }
}
