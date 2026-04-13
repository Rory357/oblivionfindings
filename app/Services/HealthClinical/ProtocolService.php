<?php

namespace App\Services\HealthClinical;

use App\Models\ClinicalProtocol;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages clinical protocol lifecycle and adherence calculations.
 */
class ProtocolService
{
    public function create(array $attributes, User $creator): ClinicalProtocol
    {
        $protocol = ClinicalProtocol::create([
            'client_id' => $attributes['client_id'],
            'observation_type' => $attributes['observation_type'],
            'frequency' => $attributes['frequency'],
            'custom_interval_days' => $attributes['custom_interval_days'] ?? null,
            'next_due_at' => $attributes['next_due_at'] ?? now(),
            'status' => ClinicalProtocol::STATUS_ACTIVE,
            'notes' => $attributes['notes'] ?? null,
            'created_by' => $creator->id,
        ]);

        // Create schedules if provided
        if (!empty($attributes['schedules'])) {
            foreach ($attributes['schedules'] as $schedule) {
                $protocol->schedules()->create($schedule);
            }
        }

        return $protocol;
    }

    /**
     * Active protocols for a client with adherence status.
     */
    public function forClient(int $clientId): array
    {
        return ClinicalProtocol::query()
            ->where('client_id', $clientId)
            ->where('status', ClinicalProtocol::STATUS_ACTIVE)
            ->with('creator:id,name')
            ->orderBy('observation_type')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'observation_type' => $p->observation_type,
                'frequency' => $p->frequency,
                'next_due_at' => $p->next_due_at?->toIso8601String(),
                'last_recorded_at' => $p->last_recorded_at?->toIso8601String(),
                'is_overdue' => $p->isOverdue(),
                'notes' => $p->notes,
                'created_by' => $p->creator?->name,
            ])
            ->toArray();
    }

    /**
     * Protocols due during a shift window.
     */
    public function dueForShift(int $clientId, Carbon $shiftStart, Carbon $shiftEnd): array
    {
        return ClinicalProtocol::query()
            ->where('client_id', $clientId)
            ->where('status', ClinicalProtocol::STATUS_ACTIVE)
            ->where(function ($q) use ($shiftStart, $shiftEnd) {
                $q->whereBetween('next_due_at', [$shiftStart, $shiftEnd])
                    ->orWhere(fn ($q2) => $q2->where('next_due_at', '<', $shiftStart));
            })
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'observation_type' => $p->observation_type,
                'frequency' => $p->frequency,
                'next_due_at' => $p->next_due_at?->toIso8601String(),
                'is_overdue' => $p->isOverdue(),
            ])
            ->toArray();
    }

    /**
     * Dashboard stats for protocols.
     */
    public function dashboardStats(): array
    {
        return [
            'active_protocols' => ClinicalProtocol::active()->count(),
            'overdue_protocols' => ClinicalProtocol::overdue()->count(),
            'by_type' => ClinicalProtocol::active()
                ->select('observation_type', DB::raw('COUNT(*) as count'))
                ->groupBy('observation_type')
                ->pluck('count', 'observation_type')
                ->toArray(),
        ];
    }
}
