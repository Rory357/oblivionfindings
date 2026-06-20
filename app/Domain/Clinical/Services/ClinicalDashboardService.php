<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;
use App\Domain\Clinical\Enums\News2Band;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Models\ClinicalRiskAssessment;
use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Enums\AlertSeverity;
use App\Models\BehaviourAbcEntry;
use App\Models\CarePlan;
use App\Models\Client;
use App\Models\ClientBowelEntry;
use App\Models\ClientFluidEntry;
use App\Models\ClientMedicalProfile;
use App\Models\ClientSeizureEntry;
use App\Models\ClientSleepEntry;
use App\Models\RestraintEvent;
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
            // Assessments due for review (review date reached).
            'assessments' => ClinicalRiskAssessment::reviewDue()->count(),
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
     * The live clinical card shown in a record wizard's rail once a client is
     * picked: allergies, the latest vitals baseline (+ its NEWS2), and the
     * client's active observation protocols. (Resus/ACP status has no data source
     * yet — deferred rather than fabricated.)
     *
     * @return array{allergies: array, disabilities: array, blood_type: ?string, baseline_vitals: ?array, active_protocols: array}
     */
    public function getClinicalCard(Client $client): array
    {
        $profile = ClientMedicalProfile::where('client_id', $client->id)->first();

        $latestVitals = ClinicalObservation::query()
            ->forClient($client->id)
            ->where('observation_type', ObservationType::Vitals->value)
            ->orderByDesc('recorded_at')
            ->first();

        return [
            'allergies' => $profile?->allergies ?? [],
            'disabilities' => $profile?->disabilities ?? [],
            'blood_type' => $profile?->blood_type,
            'baseline_vitals' => $latestVitals ? [
                'recorded_at' => $latestVitals->recorded_at->toISOString(),
                'summary' => $this->summariseVitals($latestVitals->data ?? []),
                'news2_score' => $latestVitals->news2_score,
                'news2_band' => $latestVitals->news2_band?->value,
                'news2_band_label' => $latestVitals->news2_band?->label(),
            ] : null,
            'active_protocols' => ClinicalProtocol::query()
                ->where('client_id', $client->id)
                ->where('is_active', true)
                ->orderBy('observation_type')
                ->get(['id', 'name', 'observation_type'])
                ->map(fn (ClinicalProtocol $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->observation_type->label(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summariseVitals(array $data): string
    {
        return implode(' · ', array_filter([
            isset($data['systolic'], $data['diastolic']) ? "BP {$data['systolic']}/{$data['diastolic']}" : null,
            isset($data['pulse']) ? "HR {$data['pulse']}" : null,
            isset($data['temperature']) ? "Temp {$data['temperature']}°C" : null,
            isset($data['o2_saturation']) ? "SpO₂ {$data['o2_saturation']}%" : null,
        ]));
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
     * Paginated cross-client ABC behaviour register (Behaviour tab).
     *
     * @param  array{client_id?: int|null, behaviour_function?: string|null, intensity?: string|null, site_id?: int|null, date_from?: string|null, date_to?: string|null}  $filters
     */
    public function getBehaviourRegister(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return BehaviourAbcEntry::query()
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'recorder:id,name'])
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['behaviour_function'] ?? null, fn ($q, $f) => $q->where('behaviour_function', $f))
            ->when($filters['intensity'] ?? null, fn ($q, $i) => $q->where('intensity', $i))
            ->when($filters['site_id'] ?? null, fn ($q, $id) => $q->where('site_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', Carbon::parse($d)->endOfDay()))
            ->orderByDesc('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Stat cards for the Behaviour tab: volume, escalation/harm counts and the
     * function-of-behaviour + intensity breakdowns (last 30 days).
     *
     * @return array{total_7d: int, total_30d: int, escalated_30d: int, harm_30d: int, function_breakdown: array<string, int>, intensity_mix: array<string, int>}
     */
    public function getBehaviourRegisterStats(): array
    {
        $now = Carbon::now();
        $from = $now->copy()->subDays(30);

        $functionBreakdown = BehaviourAbcEntry::where('occurred_at', '>=', $from)
            ->whereNotNull('behaviour_function')
            ->selectRaw('behaviour_function, COUNT(*) as count')
            ->groupBy('behaviour_function')
            ->pluck('count', 'behaviour_function')
            ->toArray();

        $intensityMix = BehaviourAbcEntry::where('occurred_at', '>=', $from)
            ->selectRaw('intensity, COUNT(*) as count')
            ->groupBy('intensity')
            ->pluck('count', 'intensity')
            ->toArray();

        return [
            'total_7d' => BehaviourAbcEntry::where('occurred_at', '>=', $now->copy()->subDays(7))->count(),
            'total_30d' => BehaviourAbcEntry::where('occurred_at', '>=', $from)->count(),
            'escalated_30d' => BehaviourAbcEntry::where('occurred_at', '>=', $from)->where('escalated', true)->count(),
            'harm_30d' => BehaviourAbcEntry::where('occurred_at', '>=', $from)->where('harm_occurred', true)->count(),
            'function_breakdown' => $functionBreakdown,
            'intensity_mix' => $intensityMix,
        ];
    }

    /**
     * Filter options for the Behaviour tab (clients, sites, function + intensity).
     *
     * @return array<string, mixed>
     */
    public function getBehaviourFilterOptions(): array
    {
        return [
            'clients' => Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'functions' => BehaviourFunction::options(),
            'intensities' => collect(BehaviourAbcEntry::INTENSITIES)->map(fn ($i) => ['value' => $i, 'label' => ucfirst($i)])->values(),
        ];
    }

    /**
     * Cross-client clinical risk-assessments register (FRAT / Braden / MUST /
     * IDDSI). Org-scoped via organization_id; the controller serialises rows.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getAssessmentsRegister(int $organizationId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return ClinicalRiskAssessment::query()
            ->where('organization_id', $organizationId)
            ->with(['client:id,first_name,last_name,site_id', 'client.site:id,name', 'assessor:id,name'])
            ->withCount('attachments')
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['assessment_type'] ?? null, fn ($q, $t) => $q->where('assessment_type', $t))
            ->when($filters['risk_band'] ?? null, fn ($q, $b) => $q->where('risk_band', $b))
            ->when($filters['review_due'] ?? null, fn ($q) => $q->reviewDue())
            ->orderByDesc('assessed_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Stat cards for the Assessments tab.
     *
     * @return array{total: int, high_risk: int, review_due: int, by_type: array<string, int>}
     */
    public function getAssessmentsRegisterStats(int $organizationId): array
    {
        $scope = fn () => ClinicalRiskAssessment::query()->where('organization_id', $organizationId);

        return [
            'total' => $scope()->count(),
            'high_risk' => $scope()->whereIn('risk_band', [ClinicalRiskBand::High->value, ClinicalRiskBand::VeryHigh->value])->count(),
            'review_due' => $scope()->reviewDue()->count(),
            'by_type' => $scope()
                ->selectRaw('assessment_type, COUNT(*) as count')
                ->groupBy('assessment_type')
                ->pluck('count', 'assessment_type')
                ->toArray(),
        ];
    }

    /**
     * Filter options + tool catalogue for the Assessments tab.
     *
     * @return array<string, mixed>
     */
    public function getAssessmentsFilterOptions(): array
    {
        return [
            'clients' => Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
            'types' => array_map(fn (ClinicalAssessmentType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'short' => $t->shortLabel(),
                'domain' => $t->domain(),
                'scored' => $t->isScored(),
                'tool_version' => $t->toolVersion(),
            ], ClinicalAssessmentType::cases()),
            'bands' => array_map(fn (ClinicalRiskBand $b) => [
                'value' => $b->value,
                'label' => $b->label(),
                'tone' => $b->tone(),
            ], ClinicalRiskBand::cases()),
        ];
    }

    /**
     * Read-only Care Plans review/sign-off lens (links out to /operations/care-plans).
     * Org-scoped via CarePlan.organization_id.
     *
     * @return array{plans: array<int, array<string, mixed>>, stats: array{active: int, reviews_overdue: int, awaiting_sign_off: int}}
     */
    public function getCarePlanLens(int $organizationId): array
    {
        $plans = CarePlan::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->with(['client:id,first_name,last_name', 'reviewer:id,name'])
            ->withCount(['signOffs', 'goals'])
            ->orderByRaw('next_review_at IS NULL, next_review_at asc')
            ->limit(50)
            ->get()
            ->map(fn (CarePlan $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'plan_type' => $p->plan_type,
                'status' => $p->status,
                'next_review_at' => $p->next_review_at?->toDateString(),
                'review_overdue' => $p->next_review_at !== null && $p->next_review_at->isPast(),
                'goals_count' => (int) $p->goals_count,
                'unsigned' => (int) $p->sign_offs_count === 0,
                'client' => $p->client ? ['id' => $p->client->id, 'name' => trim("{$p->client->first_name} {$p->client->last_name}")] : null,
            ]);

        $scope = fn () => CarePlan::query()->where('organization_id', $organizationId)->where('status', 'active');

        return [
            'plans' => $plans->all(),
            'stats' => [
                'active' => $scope()->count(),
                'reviews_overdue' => $scope()->where('next_review_at', '<', now())->count(),
                'awaiting_sign_off' => $scope()->doesntHave('signOffs')->count(),
            ],
        ];
    }

    /**
     * Cross-client Health Monitoring rollup (fluid / bowel / seizure / sleep).
     * Reads the per-client capture stores directly (decision #3: read both stores,
     * don't migrate); all four carry organization_id so org-scoping is direct.
     * NB sleep keys on slept_at, not occurred_at.
     *
     * @param  array{client_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function getMonitoringRollup(int $organizationId, array $filters = []): array
    {
        $clientId = $filters['client_id'] ?? null;
        $now = Carbon::now();
        $from = $now->copy()->subDays(30);
        $sevenDays = $now->copy()->subDays(7);

        $scoped = fn (string $model) => $model::query()
            ->where('organization_id', $organizationId)
            ->when($clientId, fn ($q, $id) => $q->where('client_id', $id));

        $clientName = fn ($e) => $e->client ? trim("{$e->client->first_name} {$e->client->last_name}") : 'Unknown';

        return [
            'stats' => [
                'fluid_30d' => $scoped(ClientFluidEntry::class)->where('occurred_at', '>=', $from)->count(),
                'fluid_intake_ml_7d' => (int) $scoped(ClientFluidEntry::class)->where('occurred_at', '>=', $sevenDays)->where('direction', 'intake')->sum('volume_ml'),
                'bowel_30d' => $scoped(ClientBowelEntry::class)->where('occurred_at', '>=', $from)->count(),
                'seizures_30d' => $scoped(ClientSeizureEntry::class)->where('occurred_at', '>=', $from)->count(),
                'seizures_escalated_30d' => $scoped(ClientSeizureEntry::class)->where('occurred_at', '>=', $from)->where('escalated', true)->count(),
                'sleep_avg_hours_7d' => round((float) $scoped(ClientSleepEntry::class)->where('slept_at', '>=', $sevenDays)->avg('hours_slept'), 1),
            ],
            'recent_fluid' => $scoped(ClientFluidEntry::class)->with('client:id,first_name,last_name')->orderByDesc('occurred_at')->limit(12)->get()
                ->map(fn ($e) => ['id' => $e->id, 'occurred_at' => $e->occurred_at?->toISOString(), 'direction' => $e->direction, 'fluid_type' => $e->fluid_type, 'volume_ml' => $e->volume_ml, 'client_name' => $clientName($e)])->all(),
            'recent_seizures' => $scoped(ClientSeizureEntry::class)->with('client:id,first_name,last_name')->orderByDesc('occurred_at')->limit(12)->get()
                ->map(fn ($e) => ['id' => $e->id, 'occurred_at' => $e->occurred_at?->toISOString(), 'duration_seconds' => $e->duration_seconds, 'seizure_type' => $e->seizure_type, 'escalated' => (bool) $e->escalated, 'client_name' => $clientName($e)])->all(),
            'recent_sleep' => $scoped(ClientSleepEntry::class)->with('client:id,first_name,last_name')->orderByDesc('slept_at')->limit(12)->get()
                ->map(fn ($e) => ['id' => $e->id, 'slept_at' => $e->slept_at?->toISOString(), 'hours_slept' => $e->hours_slept, 'quality' => $e->quality, 'interruptions' => $e->interruptions, 'client_name' => $clientName($e)])->all(),
            'recent_bowel' => $scoped(ClientBowelEntry::class)->with('client:id,first_name,last_name')->orderByDesc('occurred_at')->limit(12)->get()
                ->map(fn ($e) => ['id' => $e->id, 'occurred_at' => $e->occurred_at?->toISOString(), 'bristol_type' => $e->bristol_type, 'client_name' => $clientName($e)])->all(),
        ];
    }

    /**
     * Read-only Restraint register lens (links out to /health-safety/restraints).
     * RestraintEvent has no organization_id — scope through the client to avoid a
     * cross-tenant leak.
     *
     * @return array{events: array<int, array<string, mixed>>, stats: array{total_30d: int, off_plan: int, with_injury: int, review_due: int}}
     */
    public function getRestraintLens(int $organizationId): array
    {
        $orgClient = fn ($q) => $q->where('organization_id', $organizationId);

        $events = RestraintEvent::query()
            ->whereHas('client', $orgClient)
            ->with(['client:id,first_name,last_name', 'authorisedBy:id,name'])
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (RestraintEvent $r) => [
                'id' => $r->id,
                'started_at' => $r->started_at?->toISOString(),
                'restraint_type' => $r->restraint_type,
                'severity' => $r->severity,
                'duration_minutes' => $r->duration_minutes,
                'within_support_plan' => (bool) $r->within_support_plan,
                'injury_occurred' => (bool) $r->injury_occurred,
                'review_due' => $r->reviewed_at === null,
                'authorised_by' => $r->authorisedBy?->name,
                'client' => $r->client ? ['id' => $r->client->id, 'name' => trim("{$r->client->first_name} {$r->client->last_name}")] : null,
            ]);

        $scope = fn () => RestraintEvent::query()->whereHas('client', $orgClient);

        return [
            'events' => $events->all(),
            'stats' => [
                'total_30d' => $scope()->where('started_at', '>=', now()->subDays(30))->count(),
                'off_plan' => $scope()->where('within_support_plan', false)->count(),
                'with_injury' => $scope()->where('injury_occurred', true)->count(),
                'review_due' => $scope()->whereNull('reviewed_at')->count(),
            ],
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
            ->withCount('attachments')
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
