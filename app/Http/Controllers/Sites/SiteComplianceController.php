<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCertification;
use App\Models\SiteComplianceCheck;
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
}
