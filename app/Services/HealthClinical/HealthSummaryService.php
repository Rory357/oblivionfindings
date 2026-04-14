<?php

namespace App\Services\HealthClinical;

use App\Domain\Clinical\Models\ClinicalEvent as DomainClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation as DomainClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol as DomainClinicalProtocol;
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
            'recent_observations' => $this->recentObservations($clientId),
            'active_protocols' => $this->activeProtocols($clientId),
            'recent_events' => $this->recentEvents($clientId),
        ];
    }

    private function recentObservations(int $clientId): array
    {
        return DomainClinicalObservation::query()
            ->forClient($clientId)
            ->where('recorded_at', '>=', now()->subDays(7))
            ->with('recorder:id,name')
            ->orderByDesc('recorded_at')
            ->limit(20)
            ->get()
            ->map(fn (DomainClinicalObservation $observation) => [
                'id' => $observation->id,
                'observation_type' => $observation->observation_type->value,
                'recorded_at' => $observation->recorded_at?->toIso8601String(),
                'notes' => $observation->notes,
                'recorder' => $observation->recorder?->only(['id', 'name']),
            ])
            ->toArray();
    }

    private function activeProtocols(int $clientId): array
    {
        return DomainClinicalProtocol::query()
            ->where('client_id', $clientId)
            ->where('is_active', true)
            ->with(['creator:id,name', 'schedules'])
            ->orderBy('observation_type')
            ->get()
            ->map(function (DomainClinicalProtocol $protocol) {
                $pendingSchedule = $protocol->schedules
                    ->where('status', 'pending')
                    ->sortBy('due_at')
                    ->first();
                $completedSchedule = $protocol->schedules
                    ->where('status', 'completed')
                    ->sortByDesc('completed_at')
                    ->first();

                return [
                    'id' => $protocol->id,
                    'observation_type' => $protocol->observation_type->value,
                    'frequency' => $protocol->frequency->value,
                    'next_due_at' => $pendingSchedule?->due_at?->toIso8601String(),
                    'last_recorded_at' => $completedSchedule?->completed_at?->toIso8601String(),
                    'is_overdue' => (bool) $pendingSchedule?->isOverdue(),
                    'notes' => $protocol->instructions,
                    'created_by' => $protocol->creator?->name,
                ];
            })
            ->toArray();
    }

    private function recentEvents(int $clientId): array
    {
        return DomainClinicalEvent::query()
            ->forClient($clientId)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->with('reporter:id,name')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (DomainClinicalEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type->value,
                'severity' => $event->severity,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'description' => $event->description,
                'follow_up_required' => $event->requires_followup,
                'follow_up_completed_at' => $event->followup_completed_at?->toIso8601String(),
                'reporter' => $event->reporter?->only(['id', 'name']),
            ])
            ->toArray();
    }
}
