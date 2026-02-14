<?php

namespace App\Domain\Roadmap\Services;

use App\Domain\Roadmap\Events\InitiativeSuggestionCreated;
use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeCategory;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Models\Asset;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Alert;
use App\Models\FleetSignal;
use App\Models\Integration\IntegrationEvent;
use App\Models\Timesheet;

class RoadmapSuggestionService
{
    protected const OPEN_STATUSES = [
        InitiativeSuggestion::STATUS_TRIAGE_PENDING,
        InitiativeSuggestion::STATUS_ACCEPTED,
        InitiativeSuggestion::STATUS_SNOOZED,
    ];

    public function ingestAll(?int $tenantId = null): array
    {
        return [
            'control_room' => $this->ingestControlRoomRecurring($tenantId),
            'incidents' => $this->ingestIncidentClusters($tenantId),
            'assets' => $this->ingestAssetLifecycle($tenantId),
            'fleet' => $this->ingestFleetSignals($tenantId),
            'hr' => $this->ingestHrCapacity($tenantId),
            'it_health' => $this->ingestItHealth($tenantId),
        ];
    }

    public function ingestControlRoomRecurring(?int $tenantId = null): int
    {
        $rows = Alert::query()
            ->forTenant($tenantId)
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('site_id, COALESCE(provider, "unknown") as provider, title, COUNT(*) as total')
            ->groupBy('site_id', 'provider', 'title')
            ->havingRaw('COUNT(*) >= 10')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $key = sprintf('control:%s:%s:%s:14d', $row->provider, $row->site_id ?? 0, md5((string) $row->title));
            $suggestion = $this->upsertSuggestion([
                'tenant_id' => $tenantId,
                'source' => 'control_room',
                'source_key' => (string) ($row->site_id ?? 'global'),
                'title' => 'Recurring control room alerts at site '.($row->site_id ?? 'unknown'),
                'summary' => sprintf('%s recurring %d times in 14 days.', $row->title, $row->total),
                'dedupe_key' => $key,
                'score_hint' => min(100, 40 + ((int) $row->total * 2)),
                'raw_payload' => [
                    'site_id' => $row->site_id,
                    'provider' => $row->provider,
                    'title' => $row->title,
                    'count' => (int) $row->total,
                ],
                'rate_limit_days' => 30,
            ]);

            if ($suggestion->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function ingestIncidentClusters(?int $tenantId = null): int
    {
        $windowStart = now()->subDays(90);
        $rows = ClientIncident::query()
            ->where('occurred_at', '>=', $windowStart)
            ->selectRaw('type, severity, COUNT(*) as total')
            ->groupBy('type', 'severity')
            ->havingRaw('COUNT(*) >= 3')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $incidentExamples = ClientIncident::query()
                ->where('occurred_at', '>=', $windowStart)
                ->where('type', $row->type)
                ->where('severity', $row->severity)
                ->orderByDesc('occurred_at')
                ->limit(5)
                ->get([
                    'id',
                    'title',
                    'description',
                    'review_notes',
                    'closed_notes',
                    'occurred_at',
                    'location',
                ]);

            $key = sprintf('incident:%s:%s:90d', $row->type, $row->severity);
            $suggestion = $this->upsertSuggestion([
                'tenant_id' => $tenantId,
                'source' => 'incidents',
                'source_key' => $row->type,
                'title' => 'Recurring incident theme: '.$row->type,
                'summary' => sprintf('Detected %d incidents with %s severity over 90 days.', $row->total, $row->severity),
                'dedupe_key' => $key,
                'score_hint' => min(100, 35 + ((int) $row->total * 3)),
                'raw_payload' => [
                    'type' => $row->type,
                    'severity' => $row->severity,
                    'count' => (int) $row->total,
                    'window_days' => 90,
                    'incident_examples' => $incidentExamples
                        ->map(fn (ClientIncident $incident) => [
                            'id' => $incident->id,
                            'title' => $incident->title,
                            'occurred_at' => $incident->occurred_at?->toIso8601String(),
                            'location' => $incident->location,
                        ])
                        ->values()
                        ->all(),
                    'incident_notes' => $this->collectIncidentNotes($incidentExamples->all()),
                ],
                'rate_limit_days' => 45,
            ]);

            if ($suggestion->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function ingestAssetLifecycle(?int $tenantId = null): int
    {
        $warrantyThreshold = now()->addMonths(6)->toDateString();
        $maintenanceThreshold = now()->subDays(30)->toDateString();
        $rows = Asset::query()
            ->where('status', 'active')
            ->where(function ($query) use ($warrantyThreshold, $maintenanceThreshold) {
                $query->whereDate('warranty_expires_at', '<=', $warrantyThreshold)
                    ->orWhereDate('maintenance_due_at', '<=', $maintenanceThreshold);
            })
            ->selectRaw('site_id, category, COUNT(*) as total')
            ->groupBy('site_id', 'category')
            ->havingRaw('COUNT(*) >= 2')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $assetDetailQuery = Asset::query()
                ->where('status', 'active')
                ->where(function ($query) use ($warrantyThreshold, $maintenanceThreshold) {
                    $query->whereDate('warranty_expires_at', '<=', $warrantyThreshold)
                        ->orWhereDate('maintenance_due_at', '<=', $maintenanceThreshold);
                })
                ->when(
                    $row->site_id === null,
                    fn ($query) => $query->whereNull('site_id'),
                    fn ($query) => $query->where('site_id', $row->site_id),
                )
                ->when(
                    $row->category === null,
                    fn ($query) => $query->whereNull('category'),
                    fn ($query) => $query->where('category', $row->category),
                );

            $assetExamples = (clone $assetDetailQuery)
                ->orderByRaw('maintenance_due_at IS NULL')
                ->orderBy('maintenance_due_at')
                ->orderBy('warranty_expires_at')
                ->limit(5)
                ->get([
                    'id',
                    'name',
                    'asset_tag',
                    'description',
                    'notes',
                    'maintenance_due_at',
                    'warranty_expires_at',
                    'risk_level',
                ]);

            $maintenanceOverdueCount = (clone $assetDetailQuery)
                ->whereDate('maintenance_due_at', '<=', $maintenanceThreshold)
                ->count();
            $warrantyExpiringCount = (clone $assetDetailQuery)
                ->whereDate('warranty_expires_at', '<=', $warrantyThreshold)
                ->count();

            $key = sprintf('asset:%s:%s:q%s', $row->category ?? 'unknown', $row->site_id ?? 0, now()->quarter);
            $suggestion = $this->upsertSuggestion([
                'tenant_id' => $tenantId,
                'source' => 'assets',
                'source_key' => (string) ($row->site_id ?? 'global'),
                'title' => 'Asset lifecycle risk at site '.($row->site_id ?? 'unknown'),
                'summary' => sprintf('%d %s assets are overdue for lifecycle attention.', $row->total, $row->category ?? 'uncategorized'),
                'dedupe_key' => $key,
                'score_hint' => min(100, 30 + ((int) $row->total * 4)),
                'raw_payload' => [
                    'site_id' => $row->site_id,
                    'category' => $row->category,
                    'count' => (int) $row->total,
                    'maintenance_overdue_count' => $maintenanceOverdueCount,
                    'warranty_expiring_count' => $warrantyExpiringCount,
                    'asset_examples' => $assetExamples
                        ->map(fn (Asset $asset) => [
                            'id' => $asset->id,
                            'name' => $asset->name,
                            'asset_tag' => $asset->asset_tag,
                            'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
                            'warranty_expires_at' => $asset->warranty_expires_at?->toDateString(),
                            'risk_level' => $asset->risk_level,
                        ])
                        ->values()
                        ->all(),
                    'asset_notes' => $this->collectAssetNotes($assetExamples->all()),
                ],
                'rate_limit_days' => 90,
            ]);

            if ($suggestion->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function ingestFleetSignals(?int $tenantId = null): int
    {
        $rows = FleetSignal::query()
            ->where('occurred_at', '>=', now()->subDays(30))
            ->selectRaw('signal_type, COUNT(*) as total')
            ->groupBy('signal_type')
            ->havingRaw('COUNT(*) >= 15')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $key = sprintf('fleet:%s:30d', $row->signal_type);
            $suggestion = $this->upsertSuggestion([
                'tenant_id' => $tenantId,
                'source' => 'fleet',
                'source_key' => $row->signal_type,
                'title' => 'Fleet safety trend: '.$row->signal_type,
                'summary' => sprintf('Fleet signal %s occurred %d times in 30 days.', $row->signal_type, $row->total),
                'dedupe_key' => $key,
                'score_hint' => min(100, 20 + ((int) $row->total * 2)),
                'raw_payload' => [
                    'signal_type' => $row->signal_type,
                    'count' => (int) $row->total,
                ],
                'rate_limit_days' => 30,
            ]);

            if ($suggestion->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function ingestHrCapacity(?int $tenantId = null): int
    {
        $rows = Timesheet::query()
            ->where('work_date', '>=', now()->subDays(28)->toDateString())
            ->selectRaw('user_id, COUNT(*) as long_shift_days')
            ->whereRaw('TIMESTAMPDIFF(HOUR, starts_at, ends_at) >= 12')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= 6')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $dedupeKey = 'hr:long_shift_cluster:28d';
        $suggestion = $this->upsertSuggestion([
            'tenant_id' => $tenantId,
            'source' => 'hr',
            'source_key' => 'long_shift_cluster',
            'title' => 'Workforce capacity risk trend',
            'summary' => sprintf('%d staff members exceeded 12-hour shift thresholds repeatedly.', $rows->count()),
            'dedupe_key' => $dedupeKey,
            'score_hint' => min(100, 30 + ($rows->count() * 3)),
            'raw_payload' => [
                'affected_users' => $rows->pluck('user_id')->values()->all(),
                'count' => $rows->count(),
            ],
            'rate_limit_days' => 30,
        ]);

        return $suggestion->wasRecentlyCreated ? 1 : 0;
    }

    public function ingestItHealth(?int $tenantId = null): int
    {
        $rows = IntegrationEvent::query()
            ->forTenant($tenantId)
            ->where('occurred_at', '>=', now()->subDays(14))
            ->whereIn('severity', ['warn', 'critical'])
            ->selectRaw('provider, event_type, site_id, COUNT(*) as total')
            ->groupBy('provider', 'event_type', 'site_id')
            ->havingRaw('COUNT(*) >= 8')
            ->get();

        $created = 0;
        foreach ($rows as $row) {
            $key = sprintf('it:%s:%s:%s:14d', $row->provider, $row->event_type, $row->site_id ?? 0);
            $suggestion = $this->upsertSuggestion([
                'tenant_id' => $tenantId,
                'source' => 'it_health',
                'source_key' => (string) ($row->site_id ?? 'global'),
                'title' => 'IT reliability issue: '.$row->provider,
                'summary' => sprintf('%s/%s repeated %d times in 14 days.', $row->provider, $row->event_type, $row->total),
                'dedupe_key' => $key,
                'score_hint' => min(100, 25 + ((int) $row->total * 2)),
                'raw_payload' => [
                    'provider' => $row->provider,
                    'event_type' => $row->event_type,
                    'site_id' => $row->site_id,
                    'count' => (int) $row->total,
                ],
                'rate_limit_days' => 21,
            ]);

            if ($suggestion->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function upsertSuggestion(array $payload): InitiativeSuggestion
    {
        $tenantId = $payload['tenant_id'] ?? null;
        $dedupeKey = $payload['dedupe_key'];
        $rateLimitDays = (int) ($payload['rate_limit_days'] ?? 30);

        $existing = InitiativeSuggestion::query()
            ->forTenant($tenantId)
            ->where('dedupe_key', $dedupeKey)
            ->whereIn('status', self::OPEN_STATUSES)
            ->first();

        if ($existing) {
            if (! $existing->isRateLimited()) {
                $existing->update([
                    'summary' => $payload['summary'] ?? $existing->summary,
                    'raw_payload' => $payload['raw_payload'] ?? $existing->raw_payload,
                    'score_hint' => $payload['score_hint'] ?? $existing->score_hint,
                    'last_seen_at' => now(),
                ]);
                $existing->bumpHitCounter();
            } elseif (array_key_exists('raw_payload', $payload) && $payload['raw_payload'] !== null) {
                // Keep triage noise down while still refreshing detail context for reviewers.
                $existing->update([
                    'raw_payload' => $payload['raw_payload'],
                ]);
            }

            return $existing;
        }

        $dailyCount = InitiativeSuggestion::query()
            ->forTenant($tenantId)
            ->where('source', $payload['source'])
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $rateLimitedUntil = $dailyCount >= 20 ? now()->addDay() : null;

        $suggestion = InitiativeSuggestion::create([
            'tenant_id' => $tenantId,
            'source' => $payload['source'],
            'source_key' => $payload['source_key'] ?? null,
            'title' => $payload['title'],
            'summary' => $payload['summary'] ?? null,
            'raw_payload' => $payload['raw_payload'] ?? null,
            'dedupe_key' => $dedupeKey,
            'score_hint' => $payload['score_hint'] ?? null,
            'status' => InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'hit_count' => 1,
            'rate_limited_until' => $rateLimitedUntil ?? now()->addDays($rateLimitDays),
        ]);

        event(new InitiativeSuggestionCreated($suggestion));

        return $suggestion;
    }

    public function triage(
        InitiativeSuggestion $suggestion,
        string $status,
        ?int $triageOwnerId = null,
        ?\DateTimeInterface $snoozedUntil = null,
        ?string $triageNotes = null,
        bool $replaceTriageNotes = false,
    ): InitiativeSuggestion {
        $allowed = [
            InitiativeSuggestion::STATUS_TRIAGE_PENDING,
            InitiativeSuggestion::STATUS_ACCEPTED,
            InitiativeSuggestion::STATUS_REJECTED,
            InitiativeSuggestion::STATUS_SNOOZED,
        ];

        if (! in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid triage status.');
        }

        $suggestion->update([
            'status' => $status,
            'triage_owner_id' => $triageOwnerId ?? $suggestion->triage_owner_id,
            'snoozed_until' => $status === InitiativeSuggestion::STATUS_SNOOZED ? $snoozedUntil : null,
            'triage_notes' => $replaceTriageNotes ? $triageNotes : $suggestion->triage_notes,
        ]);

        return $suggestion;
    }

    public function convertToInitiative(
        InitiativeSuggestion $suggestion,
        array $overrides,
        int $userId
    ): Initiative {
        $tenantId = $suggestion->tenant_id;
        $categoryKey = $overrides['category_key'] ?? $this->defaultCategoryForSource($suggestion->source);

        $category = InitiativeCategory::query()
            ->forTenant($tenantId)
            ->where('key', $categoryKey)
            ->orderByRaw('tenant_id IS NULL')
            ->first();

        if (! $category) {
            $category = InitiativeCategory::create([
                'tenant_id' => $tenantId,
                'key' => $categoryKey,
                'name' => ucfirst(str_replace('_', ' ', $categoryKey)),
                'sort_order' => 999,
                'is_active' => true,
            ]);
        }

        $initiative = Initiative::create([
            'tenant_id' => $tenantId,
            'title' => $overrides['title'] ?? $suggestion->title,
            'summary' => $overrides['summary'] ?? $suggestion->summary,
            'category_id' => $category->id,
            'stream' => $overrides['stream'] ?? $this->defaultStreamForCategory($category->key),
            'status' => $overrides['status'] ?? Initiative::STATUS_DRAFT,
            'owner_user_id' => $overrides['owner_user_id'] ?? $userId,
            'sponsor_user_id' => $overrides['sponsor_user_id'] ?? null,
            'next_decision' => $overrides['next_decision'] ?? 'Triage and scope definition',
            'decision_due_at' => $overrides['decision_due_at'] ?? now()->addDays(14)->toDateString(),
            'target_fiscal_year' => $overrides['target_fiscal_year'] ?? now()->year,
            'target_quarter' => $overrides['target_quarter'] ?? now()->quarter,
            'cost_estimate_low' => $overrides['cost_estimate_low'] ?? null,
            'cost_estimate_high' => $overrides['cost_estimate_high'] ?? null,
            'benefit_summary' => $overrides['benefit_summary'] ?? null,
            'risk_summary' => $overrides['risk_summary'] ?? null,
            'dependency_summary' => $overrides['dependency_summary'] ?? null,
            'impact_profile' => $overrides['impact_profile'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $suggestion->update([
            'status' => InitiativeSuggestion::STATUS_CONVERTED,
            'converted_initiative_id' => $initiative->id,
            'triage_owner_id' => $userId,
            'triage_notes' => array_key_exists('triage_notes', $overrides)
                ? $this->formatNoteValue(
                    is_string($overrides['triage_notes']) ? $overrides['triage_notes'] : null,
                    2000,
                )
                : $suggestion->triage_notes,
        ]);

        return $initiative;
    }

    /**
     * @param  array<int, ClientIncident>  $incidents
     * @return array<int, string>
     */
    protected function collectIncidentNotes(array $incidents): array
    {
        $notes = [];
        foreach ($incidents as $incident) {
            foreach ([$incident->description, $incident->review_notes, $incident->closed_notes] as $value) {
                $formatted = $this->formatNoteValue($value);
                if ($formatted !== null) {
                    $notes[] = $formatted;
                }
            }
        }

        return array_values(array_slice(array_unique($notes), 0, 8));
    }

    /**
     * @param  array<int, Asset>  $assets
     * @return array<int, string>
     */
    protected function collectAssetNotes(array $assets): array
    {
        $notes = [];
        foreach ($assets as $asset) {
            foreach ([$asset->notes, $asset->description] as $value) {
                $formatted = $this->formatNoteValue($value);
                if ($formatted !== null) {
                    $notes[] = $formatted;
                }
            }
        }

        return array_values(array_slice(array_unique($notes), 0, 8));
    }

    protected function formatNoteValue(?string $value, int $maxLength = 240): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) <= $maxLength) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $maxLength - 3).'...';
    }

    protected function defaultCategoryForSource(string $source): string
    {
        return match ($source) {
            'control_room', 'it_health' => 'it',
            'assets' => 'maintenance',
            'fleet' => 'operations',
            'hr' => 'operations',
            'incidents' => 'continuous_improvement',
            default => 'operations',
        };
    }

    protected function defaultStreamForCategory(string $categoryKey): string
    {
        return match ($categoryKey) {
            'it' => 'it',
            'maintenance', 'facilities' => 'maintenance',
            'overheads' => 'overheads',
            'continuous_improvement' => 'continuous_improvement',
            default => 'operations',
        };
    }
}
