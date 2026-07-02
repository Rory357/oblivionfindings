<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrExitInterview;
use Illuminate\Support\Facades\DB;

class ExitInterviewService
{
    /**
     * Create a new exit interview record.
     */
    public function createExitInterview(array $data): HrExitInterview
    {
        return DB::transaction(function () use ($data) {
            return HrExitInterview::create([
                'tenant_id' => $data['tenant_id'],
                'employee_profile_id' => $data['employee_profile_id'],
                'interviewer_user_id' => $data['interviewer_user_id'],
                'interview_date' => $data['interview_date'],
                'departure_reason' => $data['departure_reason'],
                'would_recommend' => $data['would_recommend'] ?? null,
                'overall_satisfaction' => $data['overall_satisfaction'] ?? null,
                'what_went_well' => $data['what_went_well'] ?? null,
                'what_could_improve' => $data['what_could_improve'] ?? null,
                'management_feedback' => $data['management_feedback'] ?? null,
                'culture_feedback' => $data['culture_feedback'] ?? null,
                'additional_comments' => $data['additional_comments'] ?? null,
                'is_confidential' => $data['is_confidential'] ?? true,
                'created_by' => $data['created_by'],
            ]);
        });
    }

    /**
     * Get aggregated exit trends for a tenant.
     *
     * Returns departure reason counts and average satisfaction scores
     * over a configurable period.
     */
    public function getExitTrends(?int $tenantId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $query = HrExitInterview::forTenant($tenantId);

        if ($fromDate) {
            $query->where('interview_date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->where('interview_date', '<=', $toDate);
        }

        // Departure reasons breakdown
        $departureReasons = (clone $query)
            ->select('departure_reason', DB::raw('COUNT(*) as count'))
            ->groupBy('departure_reason')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'reason' => $row->departure_reason,
                'count' => $row->count,
            ])
            ->toArray();

        // Average satisfaction over time (monthly)
        $satisfactionOverTime = (clone $query)
            ->whereNotNull('overall_satisfaction')
            ->select(
                DB::raw("DATE_FORMAT(interview_date, '%Y-%m') as month"),
                DB::raw('AVG(overall_satisfaction) as avg_satisfaction'),
                DB::raw('COUNT(*) as count'),
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            // Privacy floor: with fewer than 3 respondents a monthly average
            // effectively discloses individual ratings (NZ Privacy Act IPPs) —
            // suppress the value but keep the month + count for the chart axis.
            ->map(fn ($row) => [
                'month' => $row->month,
                'avg_satisfaction' => $row->count >= 3 ? round($row->avg_satisfaction, 2) : null,
                'count' => $row->count,
                'suppressed' => $row->count < 3,
            ])
            ->toArray();

        // Would recommend percentage
        $recommendStats = (clone $query)
            ->whereNotNull('would_recommend')
            ->select(
                DB::raw('SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) as would_recommend'),
                DB::raw('SUM(CASE WHEN would_recommend = 0 THEN 1 ELSE 0 END) as would_not_recommend'),
                DB::raw('COUNT(*) as total'),
            )
            ->first();

        // Overall averages
        $overallStats = (clone $query)
            ->select(
                DB::raw('AVG(overall_satisfaction) as avg_satisfaction'),
                DB::raw('COUNT(*) as total_interviews'),
            )
            ->first();

        return [
            'departure_reasons' => $departureReasons,
            'satisfaction_over_time' => $satisfactionOverTime,
            'recommend_stats' => [
                'would_recommend' => (int) ($recommendStats->would_recommend ?? 0),
                'would_not_recommend' => (int) ($recommendStats->would_not_recommend ?? 0),
                'total' => (int) ($recommendStats->total ?? 0),
            ],
            'overall' => [
                'avg_satisfaction' => round($overallStats->avg_satisfaction ?? 0, 2),
                'total_interviews' => (int) ($overallStats->total_interviews ?? 0),
            ],
        ];
    }
}
