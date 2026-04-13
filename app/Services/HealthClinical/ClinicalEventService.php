<?php

namespace App\Services\HealthClinical;

use App\Models\ClinicalEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Handles creation and querying of clinical events.
 */
class ClinicalEventService
{
    public function record(array $attributes, User $reporter): ClinicalEvent
    {
        return ClinicalEvent::create([
            'client_id' => $attributes['client_id'],
            'shift_id' => $attributes['shift_id'] ?? null,
            'event_type' => $attributes['event_type'],
            'severity' => $attributes['severity'] ?? 'low',
            'occurred_at' => $attributes['occurred_at'],
            'description' => $attributes['description'],
            'metadata' => $attributes['metadata'] ?? null,
            'follow_up_required' => $attributes['follow_up_required'] ?? false,
            'reported_by' => $reporter->id,
            'linked_observation_id' => $attributes['linked_observation_id'] ?? null,
        ]);
    }

    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return ClinicalEvent::query()
            ->with([
                'client:id,first_name,last_name',
                'reporter:id,name',
                'reviewer:id,name',
            ])
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when(!empty($filters['event_type']), fn ($q) => $q->where('event_type', $filters['event_type']))
            ->when(!empty($filters['severity']), fn ($q) => $q->where('severity', $filters['severity']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('occurred_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('occurred_at', '<=', Carbon::parse($filters['date_to'])->endOfDay()))
            ->when(isset($filters['needs_follow_up']) && $filters['needs_follow_up'], fn ($q) => $q->needsFollowUp())
            ->orderByDesc('occurred_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function dashboardStats(?Carbon $since = null): array
    {
        $since = $since ?? now()->subDays(30);

        return [
            'total_events' => ClinicalEvent::where('occurred_at', '>=', $since)->count(),
            'events_today' => ClinicalEvent::whereDate('occurred_at', today())->count(),
            'pending_follow_ups' => ClinicalEvent::needsFollowUp()->count(),
            'unreviewed' => ClinicalEvent::unreviewed()->where('occurred_at', '>=', $since)->count(),
            'by_type' => ClinicalEvent::where('occurred_at', '>=', $since)
                ->select('event_type', DB::raw('COUNT(*) as count'))
                ->groupBy('event_type')
                ->pluck('count', 'event_type')
                ->toArray(),
            'by_severity' => ClinicalEvent::where('occurred_at', '>=', $since)
                ->select('severity', DB::raw('COUNT(*) as count'))
                ->groupBy('severity')
                ->pluck('count', 'severity')
                ->toArray(),
        ];
    }
}
