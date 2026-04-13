<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Enums\AlertSeverity;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lightweight dashboard metrics for the Health & Clinical module.
 *
 * All queries are intentionally simple aggregates — no complex joins
 * or reporting logic. Designed for the thin V1 dashboard only.
 */
class ClinicalDashboardService
{
    /**
     * @return array{
     *     protocols_active: int,
     *     observations_today: int,
     *     observations_7d: int,
     *     schedules_due: int,
     *     schedules_overdue: int,
     *     events_30d: int,
     *     events_high_severity_30d: int,
     *     compliance_rate_30d: float,
     * }
     */
    public function getKpis(): array
    {
        $now = Carbon::now();

        return [
            'protocols_active' => ClinicalProtocol::active()->count(),
            'observations_today' => ClinicalObservation::where('recorded_at', '>=', $now->copy()->startOfDay())->count(),
            'observations_7d' => ClinicalObservation::where('recorded_at', '>=', $now->copy()->subDays(7))->count(),
            'schedules_due' => ClinicalProtocolSchedule::pending()->count(),
            'schedules_overdue' => ClinicalProtocolSchedule::overdue()->count(),
            'events_30d' => ClinicalEvent::where('occurred_at', '>=', $now->copy()->subDays(30))->count(),
            'events_high_severity_30d' => ClinicalEvent::where('occurred_at', '>=', $now->copy()->subDays(30))
                ->whereIn('severity', [AlertSeverity::HIGH, AlertSeverity::CRITICAL])
                ->count(),
            'compliance_rate_30d' => $this->calculateComplianceRate($now->copy()->subDays(30), $now),
        ];
    }

    /**
     * Get overdue observation schedule items with protocol and client context.
     *
     * @return array<int, array{id: int, protocol_name: string, observation_type: string, observation_type_label: string, client_name: string, client_id: int, due_at: string, hours_overdue: int}>
     */
    public function getOverdueItems(int $limit = 20): array
    {
        return ClinicalProtocolSchedule::query()
            ->overdue()
            ->with(['protocol.client:id,first_name,last_name'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->map(function (ClinicalProtocolSchedule $schedule) {
                $protocol = $schedule->protocol;
                $client = $protocol?->client;

                return [
                    'id' => $schedule->id,
                    'protocol_name' => $protocol?->name ?? 'Unknown',
                    'observation_type' => $protocol?->observation_type->value ?? 'unknown',
                    'observation_type_label' => $protocol?->observation_type->label() ?? 'Unknown',
                    'client_name' => $client ? trim("{$client->first_name} {$client->last_name}") : 'Unknown',
                    'client_id' => $client?->id,
                    'due_at' => $schedule->due_at->toISOString(),
                    'hours_overdue' => (int) $schedule->due_at->diffInHours(now()),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get recent clinical events.
     *
     * @return array<int, array{id: int, event_type: string, event_type_label: string, severity: string, client_name: string, client_id: int, occurred_at: string, status: string}>
     */
    public function getRecentEvents(int $limit = 10): array
    {
        return ClinicalEvent::query()
            ->where('occurred_at', '>=', now()->subDays(30))
            ->with(['client:id,first_name,last_name', 'reporter:id,name'])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function (ClinicalEvent $event) {
                $client = $event->client;

                return [
                    'id' => $event->id,
                    'event_type' => $event->event_type->value,
                    'event_type_label' => $event->event_type->label(),
                    'severity' => $event->severity,
                    'client_name' => $client ? trim("{$client->first_name} {$client->last_name}") : 'Unknown',
                    'client_id' => $client?->id,
                    'occurred_at' => $event->occurred_at->toISOString(),
                    'status' => $event->status,
                    'reporter_name' => $event->reporter?->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get recent observation activity.
     *
     * @return array<int, array{id: int, observation_type: string, observation_type_label: string, client_name: string, recorder_name: string|null, recorded_at: string}>
     */
    public function getRecentObservations(int $limit = 10): array
    {
        return ClinicalObservation::query()
            ->with(['client:id,first_name,last_name', 'recorder:id,name'])
            ->orderByDesc('recorded_at')
            ->limit($limit)
            ->get()
            ->map(function (ClinicalObservation $obs) {
                $client = $obs->client;

                return [
                    'id' => $obs->id,
                    'observation_type' => $obs->observation_type->value,
                    'observation_type_label' => $obs->observation_type->label(),
                    'client_name' => $client ? trim("{$client->first_name} {$client->last_name}") : 'Unknown',
                    'recorder_name' => $obs->recorder?->name,
                    'recorded_at' => $obs->recorded_at->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Paginated, filterable observation register for cross-client oversight.
     *
     * @param  array{
     *     client_id?: int|null,
     *     observation_type?: string|null,
     *     recorded_by?: int|null,
     *     site_id?: int|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     * } $filters
     */
    public function getObservationRegister(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return ClinicalObservation::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'recorder:id,name',
                'shift:id,starts_at,ends_at',
                'site:id,name',
                'protocolSchedule:id,clinical_protocol_id,due_at,status',
                'protocolSchedule.protocol:id,name,observation_type,frequency',
            ])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['observation_type'] ?? null, function ($q, $type) {
                $enum = ObservationType::tryFrom($type);
                if ($enum) {
                    $q->ofType($enum);
                }
            })
            ->when($filters['recorded_by'] ?? null, fn ($q, $id) => $q->where('recorded_by', $id))
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('recorded_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('recorded_at', '<=', Carbon::parse($d)->endOfDay()))
            ->orderByDesc('recorded_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Hero-card stats for the observation register page.
     *
     * @return array{total_7d: int, total_30d: int, by_type: array<string, int>}
     */
    public function getObservationRegisterStats(): array
    {
        $now = Carbon::now();

        $byType = ClinicalObservation::query()
            ->where('recorded_at', '>=', $now->copy()->subDays(30))
            ->selectRaw('observation_type, COUNT(*) as count')
            ->groupBy('observation_type')
            ->pluck('count', 'observation_type')
            ->toArray();

        return [
            'total_7d' => ClinicalObservation::where('recorded_at', '>=', $now->copy()->subDays(7))->count(),
            'total_30d' => ClinicalObservation::where('recorded_at', '>=', $now->copy()->subDays(30))->count(),
            'by_type' => $byType,
        ];
    }

    protected function calculateComplianceRate(Carbon $from, Carbon $to): float
    {
        $total = ClinicalProtocolSchedule::query()
            ->whereBetween('due_at', [$from, $to])
            ->count();

        if ($total === 0) {
            return 100.0;
        }

        $completed = ClinicalProtocolSchedule::query()
            ->whereBetween('due_at', [$from, $to])
            ->where('status', 'completed')
            ->count();

        return round(($completed / $total) * 100, 1);
    }
}
