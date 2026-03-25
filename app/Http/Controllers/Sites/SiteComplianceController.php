<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCertification;
use App\Models\SiteComplianceCheck;
use App\Models\SiteFeedback;
use App\Models\SiteStaffRequirement;
use Illuminate\Http\Request;

class SiteComplianceController extends Controller
{
    public function dashboard(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $orgId = $request->user()?->organization_id;

        $certificationsQuery = SiteCertification::where('site_id', $site->id)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId));

        $checksQuery = SiteComplianceCheck::where('site_id', $site->id)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId));

        // Certifications grouped by status
        $certifications = (clone $certificationsQuery)
            ->with(['reviewedBy:id,name', 'createdBy:id,name'])
            ->orderBy('expiry_date')
            ->get();

        $certsByStatus = $certifications->groupBy('status');

        // Upcoming compliance checks (next 30 days)
        $upcomingChecks = (clone $checksQuery)
            ->where('status', 'scheduled')
            ->where('scheduled_date', '>=', now()->toDateString())
            ->where('scheduled_date', '<=', now()->addDays(30)->toDateString())
            ->with(['completedBy:id,name', 'createdBy:id,name'])
            ->orderBy('scheduled_date')
            ->get();

        // Overdue items
        $overdueCertifications = (clone $certificationsQuery)
            ->expired()
            ->get();

        $overdueChecks = (clone $checksQuery)
            ->overdue()
            ->with(['createdBy:id,name'])
            ->get();

        // Stats
        $stats = [
            'total_certs' => $certifications->count(),
            'current' => $certifications->where('status', 'current')->count(),
            'expiring' => $certifications->filter(function ($cert) {
                return $cert->status === 'current'
                    && $cert->expiry_date
                    && $cert->expiry_date->lte(now()->addDays(30))
                    && $cert->expiry_date->gte(now());
            })->count(),
            'expired' => $overdueCertifications->count(),
            'checks_scheduled' => (clone $checksQuery)->where('status', 'scheduled')->count(),
            'checks_overdue' => $overdueChecks->count(),
        ];

        return inertia('sites/compliance/Index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'certifications' => $certifications,
            'certsByStatus' => $certsByStatus,
            'upcomingChecks' => $upcomingChecks,
            'overdueCertifications' => $overdueCertifications,
            'overdueChecks' => $overdueChecks,
            'stats' => $stats,
        ]);
    }

    public function storeCertification(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'certification_type' => 'required|string|in:dss_certification,hswa_compliance,fire_safety,building_wof,food_safety,first_aid,civil_defence,infection_control,medication_management,restraint_minimisation,cultural_safety,other',
            'name' => 'required|string|max:255',
            'issuing_body' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'status' => 'required|string|in:current,expiring,expired,pending,not_applicable',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'document_path' => 'nullable|string|max:500',
        ]);

        $certification = SiteCertification::create([
            ...$validated,
            'site_id' => $site->id,
            'organization_id' => $request->user()?->organization_id,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->back()->with('success', 'Certification added successfully.');
    }

    public function updateCertification(Request $request, Site $site, SiteCertification $certification)
    {
        $this->authorize('update', $site);

        abort_if($certification->site_id !== $site->id, 404);

        $validated = $request->validate([
            'certification_type' => 'sometimes|required|string|in:dss_certification,hswa_compliance,fire_safety,building_wof,food_safety,first_aid,civil_defence,infection_control,medication_management,restraint_minimisation,cultural_safety,other',
            'name' => 'sometimes|required|string|max:255',
            'issuing_body' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'status' => 'sometimes|required|string|in:current,expiring,expired,pending,not_applicable',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'document_path' => 'nullable|string|max:500',
            'reviewed_by' => 'nullable|integer|exists:users,id',
            'reviewed_at' => 'nullable|date',
        ]);

        $certification->update($validated);

        return redirect()->back()->with('success', 'Certification updated successfully.');
    }

    public function destroyCertification(Request $request, Site $site, SiteCertification $certification)
    {
        $this->authorize('update', $site);

        abort_if($certification->site_id !== $site->id, 404);

        $certification->delete();

        return redirect()->back()->with('success', 'Certification removed successfully.');
    }

    public function storeCheck(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'check_type' => 'required|string|in:fire_drill,evacuation_drill,health_safety_walkthrough,medication_audit,infection_control_audit,restraint_review,cultural_review,environmental_check,food_safety_check,vehicle_check,other',
            'scheduled_date' => 'required|date',
            'status' => 'sometimes|string|in:scheduled,completed,overdue,missed,cancelled',
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'risk_rating' => 'nullable|string|in:low,medium,high,critical',
            'follow_up_date' => 'nullable|date',
            'follow_up_notes' => 'nullable|string',
        ]);

        $check = SiteComplianceCheck::create([
            ...$validated,
            'site_id' => $site->id,
            'organization_id' => $request->user()?->organization_id,
            'created_by' => $request->user()?->id,
        ]);

        return redirect()->back()->with('success', 'Compliance check scheduled successfully.');
    }

    public function completeCheck(Request $request, Site $site, SiteComplianceCheck $check)
    {
        $this->authorize('update', $site);

        abort_if($check->site_id !== $site->id, 404);

        $validated = $request->validate([
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'risk_rating' => 'nullable|string|in:low,medium,high,critical',
            'follow_up_date' => 'nullable|date',
            'follow_up_notes' => 'nullable|string',
        ]);

        $check->update([
            ...$validated,
            'status' => 'completed',
            'completed_date' => now()->toDateString(),
            'completed_by' => $request->user()?->id,
        ]);

        return redirect()->back()->with('success', 'Compliance check completed successfully.');
    }

    public function updateCheck(Request $request, Site $site, SiteComplianceCheck $check)
    {
        $this->authorize('update', $site);

        abort_if($check->site_id !== $site->id, 404);

        $validated = $request->validate([
            'check_type' => 'sometimes|required|string|in:fire_drill,evacuation_drill,health_safety_walkthrough,medication_audit,infection_control_audit,restraint_review,cultural_review,environmental_check,food_safety_check,vehicle_check,other',
            'scheduled_date' => 'sometimes|required|date',
            'status' => 'sometimes|string|in:scheduled,completed,overdue,missed,cancelled',
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'risk_rating' => 'nullable|string|in:low,medium,high,critical',
            'follow_up_date' => 'nullable|date',
            'follow_up_notes' => 'nullable|string',
        ]);

        $check->update($validated);

        return redirect()->back()->with('success', 'Compliance check updated successfully.');
    }

    // Staff Requirements
    public function storeStaffRequirement(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'requirement_name' => 'required|string|max:255',
            'category' => 'required|string|in:mandatory,recommended,specialist',
            'description' => 'nullable|string',
            'certification_required' => 'boolean',
            'expiry_period_months' => 'nullable|integer|min:1',
        ]);

        SiteStaffRequirement::create([
            ...$validated,
            'site_id' => $site->id,
            'organization_id' => $request->user()?->organization_id,
        ]);

        return redirect()->back()->with('success', 'Staff requirement added successfully.');
    }

    public function updateStaffRequirement(Request $request, Site $site, SiteStaffRequirement $requirement)
    {
        $this->authorize('update', $site);

        abort_if($requirement->site_id !== $site->id, 404);

        $validated = $request->validate([
            'requirement_name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|in:mandatory,recommended,specialist',
            'description' => 'nullable|string',
            'certification_required' => 'boolean',
            'expiry_period_months' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $requirement->update($validated);

        return redirect()->back()->with('success', 'Staff requirement updated successfully.');
    }

    public function destroyStaffRequirement(Request $request, Site $site, SiteStaffRequirement $requirement)
    {
        $this->authorize('update', $site);

        abort_if($requirement->site_id !== $site->id, 404);

        $requirement->delete();

        return redirect()->back()->with('success', 'Staff requirement removed successfully.');
    }

    // Feedback
    public function feedback(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $orgId = $request->user()?->organization_id;

        $query = SiteFeedback::where('site_id', $site->id)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId));

        // Filters
        if ($request->filled('type')) {
            $query->where('feedback_type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('rating')) {
            $query->where('rating', $request->input('rating'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $allFeedback = SiteFeedback::where('site_id', $site->id)
            ->when($orgId, fn($q) => $q->where('organization_id', $orgId));

        $totalCount = (clone $allFeedback)->count();
        $avgRating = (clone $allFeedback)->whereNotNull('rating')->avg('rating');
        $openCount = (clone $allFeedback)->whereIn('status', ['new', 'acknowledged', 'in_progress'])->count();
        $respondedCount = (clone $allFeedback)->whereNotNull('response')->count();
        $responseRate = $totalCount > 0 ? round(($respondedCount / $totalCount) * 100) : 0;

        $feedback = $query->with(['respondedBy:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('sites/feedback/Index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'feedback' => $feedback,
            'stats' => [
                'total' => $totalCount,
                'average_rating' => $avgRating ? round($avgRating, 1) : null,
                'open' => $openCount,
                'response_rate' => $responseRate,
            ],
            'filters' => $request->only(['type', 'status', 'rating', 'from', 'to']),
        ]);
    }

    public function storeFeedback(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $validated = $request->validate([
            'feedback_type' => 'required|string|in:whanau,client,staff,external,complaint,compliment',
            'submitted_by_name' => 'nullable|string|max:255',
            'submitted_by_relationship' => 'nullable|string|in:whanau,parent,sibling,advocate,staff,other',
            'content' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'category' => 'nullable|string|in:care_quality,communication,environment,staff,food,activities,safety,other',
            'is_anonymous' => 'boolean',
        ]);

        SiteFeedback::create([
            ...$validated,
            'site_id' => $site->id,
            'organization_id' => $request->user()?->organization_id,
            'status' => 'new',
        ]);

        return redirect()->back()->with('success', 'Feedback submitted successfully.');
    }

    public function respondFeedback(Request $request, Site $site, SiteFeedback $feedback)
    {
        $this->authorize('update', $site);

        abort_if($feedback->site_id !== $site->id, 404);

        $validated = $request->validate([
            'response' => 'required|string',
        ]);

        $feedback->update([
            'response' => $validated['response'],
            'responded_by' => $request->user()?->id,
            'responded_at' => now(),
            'status' => $feedback->status === 'new' ? 'acknowledged' : $feedback->status,
        ]);

        return redirect()->back()->with('success', 'Response recorded successfully.');
    }

    public function updateFeedbackStatus(Request $request, Site $site, SiteFeedback $feedback)
    {
        $this->authorize('update', $site);

        abort_if($feedback->site_id !== $site->id, 404);

        $validated = $request->validate([
            'status' => 'required|string|in:new,acknowledged,in_progress,resolved,closed',
        ]);

        $feedback->update($validated);

        return redirect()->back()->with('success', 'Feedback status updated successfully.');
    }
}
