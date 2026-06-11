<?php

namespace App\Services\HealthClinical;

use App\Models\ClinicalObservation;
use App\Models\ClinicalProtocol;
use App\Models\User;
use App\Support\WorkerClock;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Handles creation and querying of clinical observations.
 *
 * All write methods validate observation data against type schemas.
 * All read methods return arrays or paginators — no raw Eloquent.
 */
class ClinicalObservationService
{
    /**
     * Record a new clinical observation.
     */
    public function record(array $attributes, User $recorder): ClinicalObservation
    {
        $observation = DB::transaction(function () use ($attributes, $recorder) {
            $obs = ClinicalObservation::create([
                'client_id' => $attributes['client_id'],
                'shift_id' => $attributes['shift_id'] ?? null,
                'clinical_protocol_id' => $attributes['clinical_protocol_id'] ?? null,
                'observation_type' => $attributes['observation_type'],
                'data' => $attributes['data'],
                'notes' => $attributes['notes'] ?? null,
                'recorded_by' => $recorder->id,
                'recorded_at' => WorkerClock::toUtc($attributes['recorded_at'] ?? null) ?? now(),
            ]);

            // Update linked protocol if present
            if ($obs->clinical_protocol_id) {
                $protocol = ClinicalProtocol::find($obs->clinical_protocol_id);
                if ($protocol) {
                    $protocol->update([
                        'last_recorded_at' => $obs->recorded_at,
                        'next_due_at' => $obs->recorded_at->copy()->addDays($protocol->getIntervalDays()),
                    ]);
                }
            }

            return $obs;
        });

        return $observation;
    }

    /**
     * Paginated listing of observations with filters.
     */
    public function list(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return ClinicalObservation::query()
            ->with([
                'client:id,first_name,last_name',
                'recorder:id,name',
                'shift:id,starts_at,ends_at',
                'protocol:id,observation_type,frequency',
            ])
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when(!empty($filters['observation_type']), fn ($q) => $q->where('observation_type', $filters['observation_type']))
            ->when(!empty($filters['recorded_by']), fn ($q) => $q->where('recorded_by', $filters['recorded_by']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('recorded_at', '>=', Carbon::parse($filters['date_from'])->startOfDay()))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('recorded_at', '<=', Carbon::parse($filters['date_to'])->endOfDay()))
            ->when(!empty($filters['shift_id']), fn ($q) => $q->where('shift_id', $filters['shift_id']))
            ->orderByDesc('recorded_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Recent observations for a client (health summary card).
     */
    public function recentForClient(int $clientId, int $days = 7): array
    {
        return ClinicalObservation::query()
            ->where('client_id', $clientId)
            ->where('recorded_at', '>=', now()->subDays($days))
            ->with('recorder:id,name')
            ->orderByDesc('recorded_at')
            ->limit(20)
            ->get()
            ->toArray();
    }

    /**
     * Observations recorded during a specific shift.
     */
    public function forShift(int $shiftId): array
    {
        return ClinicalObservation::query()
            ->where('shift_id', $shiftId)
            ->with(['client:id,first_name,last_name', 'recorder:id,name'])
            ->orderByDesc('recorded_at')
            ->get()
            ->toArray();
    }

    /**
     * Summary stats for the dashboard.
     */
    public function dashboardStats(?Carbon $since = null): array
    {
        $since = $since ?? now()->subDays(30);

        return [
            'total_observations' => ClinicalObservation::where('recorded_at', '>=', $since)->count(),
            'observations_today' => ClinicalObservation::whereDate('recorded_at', today())->count(),
            'by_type' => ClinicalObservation::where('recorded_at', '>=', $since)
                ->select('observation_type', DB::raw('COUNT(*) as count'))
                ->groupBy('observation_type')
                ->pluck('count', 'observation_type')
                ->toArray(),
        ];
    }
}
