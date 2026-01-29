<?php

namespace App\Http\Controllers;

use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\PrivacyImpactAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyReportController extends Controller
{
    /**
     * Display compliance report.
     */
    public function compliance(Request $request): Response
    {
        $period = $request->get('period', 'year');
        $startDate = match ($period) {
            'month' => now()->startOfMonth(),
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            default => now()->startOfYear(),
        };

        return Inertia::render('privacy/reports/compliance', [
            'period' => $period,
            'dsrStats' => [
                'total' => DataSubjectRequest::where('created_at', '>=', $startDate)->count(),
                'completed' => DataSubjectRequest::where('status', 'completed')
                    ->where('completed_at', '>=', $startDate)
                    ->count(),
                'average_response_days' => $this->calculateAverageResponseDays($startDate),
                'by_type' => DataSubjectRequest::where('created_at', '>=', $startDate)
                    ->selectRaw('request_type, count(*) as count')
                    ->groupBy('request_type')
                    ->pluck('count', 'request_type'),
            ],
            'breachStats' => [
                'total' => DataBreachLog::where('created_at', '>=', $startDate)->count(),
                'resolved' => DataBreachLog::where('status', 'resolved')
                    ->where('resolved_at', '>=', $startDate)
                    ->count(),
                'ico_notifications' => DataBreachLog::whereNotNull('authority_notified_at')
                    ->where('authority_notified_at', '>=', $startDate)
                    ->count(),
            ],
            'dpiaStats' => [
                'total' => PrivacyImpactAssessment::where('created_at', '>=', $startDate)->count(),
                'approved' => PrivacyImpactAssessment::where('outcome', 'approved')
                    ->where('approved_at', '>=', $startDate)
                    ->count(),
                'high_risk' => PrivacyImpactAssessment::where('overall_risk_level', 'high')
                    ->where('created_at', '>=', $startDate)
                    ->count(),
            ],
            'retentionStats' => [
                'total_policies' => DataRetentionPolicy::count(),
                'active_policies' => DataRetentionPolicy::where('active', true)->count(),
            ],
            'legalHoldStats' => [
                'total' => LegalHold::where('created_at', '>=', $startDate)->count(),
                'active' => LegalHold::active()->count(),
            ],
        ]);
    }

    /**
     * Export compliance data.
     */
    public function export(Request $request)
    {
        // TODO: Implement export functionality
        return back()->with('info', 'Export functionality coming soon.');
    }

    /**
     * Calculate average response days for DSRs.
     */
    private function calculateAverageResponseDays($startDate): float
    {
        $completedRequests = DataSubjectRequest::where('status', 'completed')
            ->where('completed_at', '>=', $startDate)
            ->whereNotNull('received_at')
            ->whereNotNull('completed_at')
            ->get();

        if ($completedRequests->isEmpty()) {
            return 0;
        }

        $totalDays = $completedRequests->sum(function ($request) {
            return $request->received_at->diffInDays($request->completed_at);
        });

        return round($totalDays / $completedRequests->count(), 1);
    }
}
