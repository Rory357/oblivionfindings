<?php

namespace App\Services\Operations;

use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Services\ShiftCoverageService;
use Carbon\Carbon;

/**
 * Shared presentation for recurring shift series, used by the Rostering
 * "Recurring" tab (list cards + detail pop-up). Keeps the controller thin and
 * lets the standalone redirect routes and the tab share one source of truth.
 */
class ShiftSeriesPresenter
{
    public function __construct(private ShiftCoverageService $coverage) {}

    /**
     * List payload for the Recurring tab cards. Not organization-scoped — the
     * shift_series table has no organization_id; tenancy flows through the
     * linked client/site, matching the historical standalone index page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 100): array
    {
        return ShiftSeries::query()
            ->with([
                'client:id,first_name,last_name',
                'site:id,name,type',
                'staff:id,name',
                'serviceContext:id,name,type',
            ])
            ->withCount([
                'shifts as occurrences_total',
                'shifts as active_occurrences_count' => fn ($query) => $query->whereNotIn('status', ['completed', 'cancelled']),
                'shifts as open_occurrences_count' => fn ($query) => $query
                    ->whereNull('user_id')
                    ->whereNotIn('status', ['completed', 'cancelled']),
                'shifts as replacement_occurrences_count' => fn ($query) => $query
                    ->whereHas('replacementRequests', fn ($replacementQuery) => $replacementQuery->active()),
            ])
            ->withMin([
                'shifts as next_starts_at' => fn ($query) => $query
                    ->whereNotIn('status', ['completed', 'cancelled'])
                    ->where('ends_at', '>=', now()->startOfDay()),
            ], 'starts_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (ShiftSeries $row) => $this->summary($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(ShiftSeries $row): array
    {
        return [
            'id' => $row->id,
            'status' => $row->status,
            'shift_type' => $row->shift_type ?? 'standard',
            'client' => $row->client ? [
                'id' => $row->client->id,
                'name' => trim($row->client->first_name.' '.$row->client->last_name),
            ] : null,
            'site' => $row->site ? [
                'id' => $row->site->id,
                'name' => $row->site->name,
                'type' => $row->site->type,
            ] : null,
            'staff' => $row->staff ? ['id' => $row->staff->id, 'name' => $row->staff->name] : null,
            'service_context' => $row->serviceContext ? [
                'id' => $row->serviceContext->id,
                'name' => $row->serviceContext->name,
                'type' => $row->serviceContext->type,
            ] : null,
            'location' => $row->location,
            'weekdays' => $row->by_weekday ?? [],
            'starts_time' => $row->starts_time,
            'ends_time' => $row->ends_time,
            'is_sleepover' => (bool) $row->is_sleepover,
            'is_on_call' => (bool) $row->is_on_call,
            'start_date' => optional($row->start_date)->toDateString(),
            'end_date' => optional($row->end_date)->toDateString(),
            'occurrences_total' => (int) ($row->occurrences_total ?? 0),
            'active_occurrences_count' => (int) ($row->active_occurrences_count ?? 0),
            'open_occurrences_count' => (int) ($row->open_occurrences_count ?? 0),
            'replacement_occurrences_count' => (int) ($row->replacement_occurrences_count ?? 0),
            'next_starts_at' => $row->next_starts_at ? Carbon::parse($row->next_starts_at)->toIso8601String() : null,
        ];
    }

    /**
     * Full detail payload for the series pop-up (mirrors the old Show page).
     *
     * @return array<string, mixed>
     */
    public function detail(ShiftSeries $series): array
    {
        $series->load([
            'client:id,first_name,last_name',
            'site:id,name,type',
            'staff:id,name,email',
            'serviceContext:id,name,type',
        ]);

        $today = now()->startOfDay();

        $upcomingOccurrences = Shift::query()
            ->with([
                'staff:id,name,email',
                'serviceContext:id,name,type',
                'replacementRequests' => fn ($query) => $query->active()
                    ->with([
                        'requester:id,name',
                        'currentStaff:id,name',
                        'replacementStaff:id,name',
                        'openPosition:id,replacement_request_id,status,claimed_by,expires_at',
                        'openPosition.claimer:id,name',
                    ]),
            ])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($query) => $query->where('is_completed', true),
            ])
            ->where('shift_series_id', $series->id)
            ->where('ends_at', '>=', $today)
            ->orderBy('starts_at')
            ->limit(18)
            ->get();

        $recentOccurrences = Shift::query()
            ->with(['staff:id,name,email', 'serviceContext:id,name,type'])
            ->withCount([
                'incidents as incidents_count',
                'tasks as tasks_total',
                'tasks as tasks_completed' => fn ($query) => $query->where('is_completed', true),
            ])
            ->where('shift_series_id', $series->id)
            ->where('ends_at', '<', $today)
            ->orderByDesc('starts_at')
            ->limit(8)
            ->get();

        $stats = [
            'occurrences_total' => Shift::query()->where('shift_series_id', $series->id)->count(),
            'remaining_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where('ends_at', '>=', $today)
                ->count(),
            'open_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->whereNull('user_id')
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->where('ends_at', '>=', $today)
                ->count(),
            'completed_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->where('status', 'completed')
                ->count(),
            'cancelled_occurrences' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->where('status', 'cancelled')
                ->count(),
            'active_replacements' => Shift::query()
                ->where('shift_series_id', $series->id)
                ->whereHas('replacementRequests', fn ($query) => $query->active())
                ->count(),
        ];

        $nextOccurrence = $upcomingOccurrences->first();
        $coverageAlignment = [
            'linked_rule_issues' => [],
            'orphan_series' => null,
        ];

        if ($series->site_id) {
            $alignmentWindowEnd = now()->addDays(28)->endOfDay();
            $alignment = $this->coverage->buildRecurringAlignment(
                now()->startOfDay(),
                $alignmentWindowEnd,
                $series->site_id,
            );

            $coverageAlignment['linked_rule_issues'] = collect($alignment['rule_drift'] ?? [])
                ->filter(fn (array $issue) => collect($issue['matching_series'] ?? [])
                    ->contains(fn (array $row) => (int) ($row['id'] ?? 0) === (int) $series->id))
                ->values()
                ->all();
            $coverageAlignment['orphan_series'] = collect($alignment['orphan_series'] ?? [])
                ->first(fn (array $issue) => (int) ($issue['series_id'] ?? 0) === (int) $series->id);
        }

        return [
            'id' => $series->id,
            'series' => [
                'id' => $series->id,
                'status' => $series->status,
                'shift_type' => $series->shift_type ?? 'standard',
                'client' => $series->client ? [
                    'id' => $series->client->id,
                    'name' => trim($series->client->first_name.' '.$series->client->last_name),
                ] : null,
                'site' => $series->site ? [
                    'id' => $series->site->id,
                    'name' => $series->site->name,
                    'type' => $series->site->type,
                ] : null,
                'staff' => $series->staff ? [
                    'id' => $series->staff->id,
                    'name' => $series->staff->name,
                    'email' => $series->staff->email,
                ] : null,
                'service_context' => $series->serviceContext ? [
                    'id' => $series->serviceContext->id,
                    'name' => $series->serviceContext->name,
                    'type' => $series->serviceContext->type,
                ] : null,
                'location' => $series->location,
                'notes' => $series->notes,
                'weekdays' => $series->by_weekday ?? [],
                'starts_time' => $series->starts_time,
                'ends_time' => $series->ends_time,
                'start_date' => optional($series->start_date)->toDateString(),
                'end_date' => optional($series->end_date)->toDateString(),
                'is_sleepover' => (bool) $series->is_sleepover,
                'is_on_call' => (bool) $series->is_on_call,
                'expected_break_minutes' => $series->expected_break_minutes,
            ],
            'stats' => $stats,
            'nextOccurrence' => $nextOccurrence ? $this->mapOccurrence($nextOccurrence) : null,
            'upcomingOccurrences' => $upcomingOccurrences->map(fn (Shift $shift) => $this->mapOccurrence($shift))->values(),
            'recentOccurrences' => $recentOccurrences->map(fn (Shift $shift) => $this->mapOccurrence($shift))->values(),
            'coverageAlignment' => $coverageAlignment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOccurrence(Shift $shift): array
    {
        $replacement = $shift->replacementRequests
            ->sortByDesc('requested_at')
            ->first();

        return [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toIso8601String(),
            'ends_at' => optional($shift->ends_at)->toIso8601String(),
            'status' => $shift->status,
            'user_id' => $shift->user_id,
            'staff' => $shift->staff ? [
                'id' => $shift->staff->id,
                'name' => $shift->staff->name,
                'email' => $shift->staff->email,
            ] : null,
            'service_context' => $shift->serviceContext ? [
                'id' => $shift->serviceContext->id,
                'name' => $shift->serviceContext->name,
                'type' => $shift->serviceContext->type,
            ] : null,
            'location' => $shift->location,
            'tasks_total' => (int) ($shift->tasks_total ?? 0),
            'tasks_completed' => (int) ($shift->tasks_completed ?? 0),
            'incidents_count' => (int) ($shift->incidents_count ?? 0),
            'replacement' => $replacement ? [
                'id' => $replacement->id,
                'status' => $replacement->status,
                'reason' => $replacement->reason,
                'requested_by' => $replacement->requester?->name,
                'current_staff' => $replacement->currentStaff?->name,
                'replacement_staff' => $replacement->replacementStaff?->name,
                'open_position_status' => $replacement->openPosition?->status,
                'open_position_claimed_by' => $replacement->openPosition?->claimer?->name,
                'expires_at' => optional($replacement->openPosition?->expires_at)->toIso8601String(),
            ] : null,
        ];
    }
}
