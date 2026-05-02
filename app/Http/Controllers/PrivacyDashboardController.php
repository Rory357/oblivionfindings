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

class PrivacyDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canDo('privacy.viewRequests'), 403);

        // Data Subject Requests stats
        $dsrStats = [
            'total' => DataSubjectRequest::count(),
            'pending' => DataSubjectRequest::whereIn('status', ['received', 'under_review', 'identity_verification', 'in_progress'])->count(),
            'overdue' => DataSubjectRequest::where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'rejected', 'withdrawn'])
                ->count(),
            'completed_this_month' => DataSubjectRequest::where('status', 'completed')
                ->whereMonth('completed_at', now()->month)
                ->whereYear('completed_at', now()->year)
                ->count(),
        ];

        // Recent requests
        $recentRequests = DataSubjectRequest::query()
            ->with(['client', 'user', 'assignedTo'])
            ->latest('received_at')
            ->limit(5)
            ->get();

        // Data Breach stats
        $breachStats = [
            'total' => DataBreachLog::count(),
            'open' => DataBreachLog::whereNotIn('status', ['resolved'])->count(),
            'requiring_notification' => DataBreachLog::where('requires_authority_notification', true)
                ->whereNull('authority_notified_at')
                ->count(),
        ];

        // Legal Holds
        $activeHolds = LegalHold::where('status', 'active')->count();

        // Retention Policies
        $retentionStats = [
            'total_policies' => DataRetentionPolicy::count(),
            'active_policies' => DataRetentionPolicy::where('active', true)->count(),
        ];

        // Privacy Impact Assessments
        $dpiaStats = [
            'total' => PrivacyImpactAssessment::count(),
            'pending_review' => PrivacyImpactAssessment::whereNull('outcome')->count(),
            'high_risk' => PrivacyImpactAssessment::whereIn('overall_risk_level', ['high', 'very_high'])->count(),
        ];

        return Inertia::render('privacy/dashboard', [
            'dsrStats' => $dsrStats,
            'recentRequests' => $recentRequests,
            'breachStats' => $breachStats,
            'activeHolds' => $activeHolds,
            'retentionStats' => $retentionStats,
            'dpiaStats' => $dpiaStats,
        ]);
    }
}
