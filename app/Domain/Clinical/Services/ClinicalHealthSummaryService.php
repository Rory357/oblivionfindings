<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Models\Client;

/**
 * Lightweight health summary for Client Profile.
 *
 * Returns structured data suitable for the Overview health summary card.
 * Designed for low query cost — single-client context only.
 */
class ClinicalHealthSummaryService
{
    public function __construct(
        protected ClinicalProtocolService $protocolService,
    ) {}

    /**
     * Get a complete health summary for a client.
     *
     * @return array{
     *     latest_observations: array<string, array|null>,
     *     recent_events: array{count: int, high_severity_count: int, items: array},
     *     protocol_compliance: array{rate: float, due_count: int, overdue_count: int},
     * }
     */
    public function getSummary(Client $client): array
    {
        return [
            'latest_observations' => $this->getLatestObservations($client),
            'recent_events' => $this->getRecentEvents($client),
            'protocol_compliance' => $this->getProtocolCompliance($client),
        ];
    }

    /**
     * Get the most recent observation of each type for a client.
     *
     * @return array<string, array{id: int, recorded_at: string, data: array, recorded_by: int}|null>
     */
    public function getLatestObservations(Client $client): array
    {
        $result = [];

        foreach (ObservationType::cases() as $type) {
            $latest = ClinicalObservation::query()
                ->forClient($client->id)
                ->ofType($type)
                ->orderByDesc('recorded_at')
                ->first();

            $result[$type->value] = $latest ? [
                'id' => $latest->id,
                'recorded_at' => $latest->recorded_at->toISOString(),
                'data' => $latest->data,
                'recorded_by' => $latest->recorded_by,
            ] : null;
        }

        return $result;
    }

    /**
     * Get recent clinical events summary for a client (last 30 days).
     *
     * @return array{count: int, high_severity_count: int, items: array}
     */
    public function getRecentEvents(Client $client, int $days = 30): array
    {
        $events = ClinicalEvent::query()
            ->forClient($client->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return [
            'count' => ClinicalEvent::query()
                ->forClient($client->id)
                ->where('occurred_at', '>=', now()->subDays($days))
                ->count(),
            'high_severity_count' => ClinicalEvent::query()
                ->forClient($client->id)
                ->where('occurred_at', '>=', now()->subDays($days))
                ->highSeverity()
                ->count(),
            'items' => $events->map(fn (ClinicalEvent $e) => [
                'id' => $e->id,
                'event_type' => $e->event_type->value,
                'event_type_label' => $e->event_type->label(),
                'severity' => $e->severity,
                'occurred_at' => $e->occurred_at->toISOString(),
                'status' => $e->status,
            ])->toArray(),
        ];
    }

    /**
     * Get protocol compliance snapshot for a client.
     *
     * @return array{rate: float, due_count: int, overdue_count: int}
     */
    public function getProtocolCompliance(Client $client): array
    {
        $due = $this->protocolService->getDueForClient($client);
        $overdue = $this->protocolService->getOverdue($client);
        $rate = $this->protocolService->getComplianceRate($client);

        return [
            'rate' => $rate,
            'due_count' => $due->count(),
            'overdue_count' => $overdue->count(),
        ];
    }
}
