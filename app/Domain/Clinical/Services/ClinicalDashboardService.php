<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\News2Band;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
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
     *     clients_on_watch: int,
     * }
     */
    public function getKpis(): array
    {
        $now = Carbon::now();

        return [
            'protocols_active' => ClinicalProtocol::active()->count(),
            'clients_on_watch' => $this->clientsOnWatchCount(),
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
     * Attention-badge counts for the command-centre two-tier tabs (group pills
     * sum their sub-tabs). Reuses the already-computed KPI snapshot where possible
     * to avoid duplicate queries.
     *
     * @param  array{schedules_overdue?: int}|null  $kpis  optional pre-computed getKpis() result
     * @return array<string, int>
     */
    public function getTabCounts(?array $kpis = null): array
    {
        $kpis ??= $this->getKpis();
        $now = Carbon::now();

        return [
            // Observations needing attention = overdue protocol schedules to record.
            'observations' => $kpis['schedules_overdue'] ?? 0,
            // Clinical events awaiting RN sign-off (last 30 days).
            'clinical_events' => ClinicalEvent::whereNull('reviewed_at')
                ->where('occurred_at', '>=', $now->copy()->subDays(30))
                ->count(),
        ];
    }

    /**
     * Deterioration watch: clients whose MOST RECENT vitals observation (last 7
     * days) carries a NEWS2 band of Medium or High. Latest-per-client is resolved
     * in PHP over the bounded recent-vitals set.
     */
    private function clientsOnWatchCount(): int
    {
        return $this->latestVitalsPerClient()
            ->filter(fn ($rows) => $rows->first()->news2_band instanceof News2Band
                && $rows->first()->news2_band->isOnWatch())
            ->count();
    }

    /**
     * The deterioration watch list for the Overview: each on-watch client with
     * their latest NEWS2 score + band and a short score sparkline. Sorted worst
     * first.
     *
     * @return array<int, array{client_id: int, client_name: string, site: ?string, news2_score: int, news2_band: string, band_label: string, recorded_at: string, sparkline: array<int, int>}>
     */
    public function getDeteriorationWatch(int $limit = 10): array
    {
        return $this->latestVitalsPerClient()
            ->filter(fn ($rows) => $rows->first()->news2_band instanceof News2Band
                && $rows->first()->news2_band->isOnWatch())
            ->map(function ($rows) {
                $latest = $rows->first();

                return [
                    'client_id' => $latest->client_id,
                    'client_name' => $latest->client
                        ? trim("{$latest->client->first_name} {$latest->client->last_name}")
                        : 'Unknown',
                    'site' => $latest->client?->site?->name,
                    'news2_score' => $latest->news2_score,
                    'news2_band' => $latest->news2_band->value,
                    'band_label' => $latest->news2_band->label(),
                    'recorded_at' => $latest->recorded_at->toISOString(),
                    // Oldest → newest for the sparkline.
                    'sparkline' => $rows->take(8)->reverse()->pluck('news2_score')->map(fn ($v) => (int) $v)->values()->all(),
                ];
            })
            ->sortByDesc('news2_score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Recent vitals (with a NEWS2 band), grouped by client and ordered newest
     * first within each group — the shared basis for the watch count + list.
     */
    private function latestVitalsPerClient(): \Illuminate\Support\Collection
    {
        return ClinicalObservation::query()
            ->where('observation_type', ObservationType::Vitals->value)
            ->whereNotNull('news2_band')
            ->where('recorded_at', '>=', Carbon::now()->subDays(7))
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name'])
            ->orderByDesc('recorded_at')
            ->get(['id', 'client_id', 'news2_score', 'news2_band', 'recorded_at'])
            ->groupBy('client_id');
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

    /**
     * Paginated, filterable event register for cross-client oversight.
     *
     * @param  array{
     *     client_id?: int|null,
     *     event_type?: string|null,
     *     severity?: string|null,
     *     site_id?: int|null,
     *     follow_up_status?: string|null,
     *     review_status?: string|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     * } $filters
     */
    public function getEventRegister(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return ClinicalEvent::query()
            ->with([
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'site:id,name',
                'reporter:id,name',
                'reviewer:id,name',
            ])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['event_type'] ?? null, function ($q, $type) {
                $enum = ClinicalEventType::tryFrom($type);

                if ($enum) {
                    $q->ofType($enum);
                }
            })
            ->when($filters['severity'] ?? null, fn ($q, $severity) => $q->where('severity', $severity))
            ->when($filters['site_id'] ?? null, function ($q, $siteId) {
                $q->where(function ($siteQuery) use ($siteId) {
                    $siteQuery->where('site_id', $siteId)
                        ->orWhere(function ($legacySiteQuery) use ($siteId) {
                            $legacySiteQuery
                                ->whereNull('site_id')
                                ->whereHas('client', fn ($clientQuery) => $clientQuery->where('site_id', $siteId));
                        });
                });
            })
            ->when($filters['follow_up_status'] ?? null, function ($q, $status) {
                match ($status) {
                    'none' => $q->where('requires_followup', false),
                    'required' => $q->where('requires_followup', true),
                    'pending' => $q->where('requires_followup', true)->whereNull('followup_completed_at'),
                    'completed' => $q->where('requires_followup', true)->whereNotNull('followup_completed_at'),
                    default => null,
                };
            })
            ->when($filters['review_status'] ?? null, function ($q, $status) {
                if ($status === 'reviewed') {
                    $q->whereNotNull('reviewed_at');
                }

                if ($status === 'unreviewed') {
                    $q->whereNull('reviewed_at');
                }
            })
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->where('occurred_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->where('occurred_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderByDesc('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Hero-card stats for the event register page.
     *
     * @return array{total_7d: int, total_30d: int, pending_follow_ups: int, unreviewed: int}
     */
    public function getEventRegisterStats(): array
    {
        $now = Carbon::now();

        return [
            'total_7d' => ClinicalEvent::where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
            'total_30d' => ClinicalEvent::where('occurred_at', '>=', $now->copy()->subDays(30))->count(),
            'pending_follow_ups' => ClinicalEvent::query()
                ->where('requires_followup', true)
                ->whereNull('followup_completed_at')
                ->count(),
            'unreviewed' => ClinicalEvent::query()
                ->whereNull('reviewed_at')
                ->count(),
        ];
    }

    /**
     * Paginated, filterable protocol register for cross-client management.
     *
     * @param  array{
     *     client_id?: int|null,
     *     observation_type?: string|null,
     *     frequency?: string|null,
     *     status?: string|null,
     * } $filters
     */
    public function getProtocolRegister(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $now = Carbon::now();

        return ClinicalProtocol::query()
            ->with([
                'client:id,first_name,last_name',
                'creator:id,name',
            ])
            ->withCount([
                'schedules',
                'schedules as pending_schedules_count' => fn ($query) => $query->where('status', 'pending'),
                'schedules as overdue_schedules_count' => fn ($query) => $query
                    ->where('status', 'pending')
                    ->where('due_at', '<', $now),
                'schedules as completed_schedules_30d_count' => fn ($query) => $query
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', $now->copy()->subDays(30)),
            ])
            ->when($filters['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['observation_type'] ?? null, function ($query, $type) {
                $enum = ObservationType::tryFrom($type);

                if ($enum) {
                    $query->ofType($enum);
                }
            })
            ->when($filters['frequency'] ?? null, function ($query, $frequency) {
                $enum = ProtocolFrequency::tryFrom($frequency);

                if ($enum) {
                    $query->where('frequency', $enum);
                }
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Hero-card stats for the protocol register page.
     *
     * @return array{
     *     active_protocols: int,
     *     inactive_protocols: int,
     *     schedules_due: int,
     *     schedules_overdue: int,
     *     compliance_rate_30d: float,
     * }
     */
    public function getProtocolRegisterStats(): array
    {
        $kpis = $this->getKpis();

        return [
            'active_protocols' => $kpis['protocols_active'],
            'inactive_protocols' => ClinicalProtocol::query()
                ->where('is_active', false)
                ->count(),
            'schedules_due' => $kpis['schedules_due'],
            'schedules_overdue' => $kpis['schedules_overdue'],
            'compliance_rate_30d' => $kpis['compliance_rate_30d'],
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
