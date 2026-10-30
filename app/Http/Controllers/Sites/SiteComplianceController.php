<?php

namespace App\Http\Controllers\Sites;

use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Enums\AssuranceStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteCertification;
use App\Models\SiteComplianceCheck;
use App\Models\SiteCoverageRequirement;
use App\Models\SiteFeedback;
use App\Models\SiteStaffRequirement;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Assurance\NzsAssuranceResolver;
use App\Services\Assurance\SiteCertificationService;
use App\Services\Eligibility\WorkforceEligibilitySources;
use App\Services\Eligibility\WorkforceRequirementMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiteComplianceController extends Controller
{
    public function __construct(
        private readonly SiteCertificationService $certifications,
        private readonly NzsAssuranceResolver $assurance,
    ) {}

    public function dashboard(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $certificationsQuery = SiteCertification::where('site_id', $site->id);
        $checksQuery = SiteComplianceCheck::where('site_id', $site->id);

        // Certifications grouped by status
        $certifications = (clone $certificationsQuery)
            ->with(['reviewedBy:id,name', 'createdBy:id,name'])
            ->orderBy('expiry_date')
            ->get();

        $certsByStatus = $certifications->groupBy('status');
        $nzsStatus = $this->assurance->certificationForSite($site->id);
        $substantiated = fn (SiteCertification $certification): bool => $certification->certification_type !== NzsAssuranceResolver::CERTIFICATION_TYPE
            || $nzsStatus === AssuranceStatus::CERTIFIED;

        // Upcoming compliance checks (next 30 days)
        $upcomingChecks = (clone $checksQuery)
            ->where('status', 'scheduled')
            ->where('scheduled_date', '>=', now()->toDateString())
            ->where('scheduled_date', '<=', now()->addDays(30)->toDateString())
            ->with(['completedBy:id,name', 'createdBy:id,name'])
            ->orderBy('scheduled_date')
            ->get();

        $complianceChecks = (clone $checksQuery)
            ->with(['completedBy:id,name', 'createdBy:id,name'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'missed' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END")
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
            'current' => $certifications->where('status', 'current')->filter($substantiated)->count(),
            'expiring' => $certifications->filter(function ($cert) {
                return $cert->status === 'current'
                    && $cert->expiry_date
                    && $cert->expiry_date->lte(now()->addDays(30))
                    && $cert->expiry_date->gte(now());
            })->filter($substantiated)->count(),
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
            'certifications' => $certifications->map(fn (SiteCertification $certification): array => $this->serializeCertification($certification)),
            'compliance_checks' => $complianceChecks->map(fn (SiteComplianceCheck $check): array => $this->serializeComplianceCheck($check)),
            'certsByStatus' => $certsByStatus->map(
                fn ($group) => $group->map(fn (SiteCertification $certification): array => $this->serializeCertification($certification)),
            ),
            'upcomingChecks' => $upcomingChecks->map(fn (SiteComplianceCheck $check): array => $this->serializeComplianceCheck($check)),
            'overdueCertifications' => $overdueCertifications->map(fn (SiteCertification $certification): array => $this->serializeCertification($certification)),
            'overdueChecks' => $overdueChecks->map(fn (SiteComplianceCheck $check): array => $this->serializeComplianceCheck($check)),
            'stats' => $stats,
            'can' => [
                'manage_compliance' => (bool) ($request->user()?->canDo('sites.update')
                    && $request->user()?->can('update', $site)),
            ],
        ]);
    }

    public function storeCertification(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'certification_type' => 'required|string|in:healthcert_certification,hswa_compliance,fire_safety,building_wof,food_safety,first_aid,civil_defence,infection_control,medication_management,restraint_minimisation,cultural_safety,other',
            'name' => 'required|string|max:255',
            'issuing_body' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'status' => 'sometimes|string|in:current,expiring,expired,pending,not_applicable',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'document_path' => 'nullable|string|max:500',
        ]);

        $this->certifications->create($site, $request->user(), [
            ...$validated,
            'status' => $validated['status'] ?? 'current',
        ]);

        return redirect()->back()->with('success', 'Certification added successfully.');
    }

    public function updateCertification(Request $request, Site $site, SiteCertification $certification)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'certification_type' => 'sometimes|required|string|in:healthcert_certification,hswa_compliance,fire_safety,building_wof,food_safety,first_aid,civil_defence,infection_control,medication_management,restraint_minimisation,cultural_safety,other',
            'name' => 'sometimes|required|string|max:255',
            'issuing_body' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'status' => 'sometimes|required|string|in:current,expiring,expired,pending,not_applicable',
            'issued_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'document_path' => 'nullable|string|max:500',
            'reviewed_by' => 'nullable|integer',
            'reviewed_at' => 'nullable|date|required_with:reviewed_by',
        ]);

        $this->certifications->update($site, $certification->id, $request->user(), $validated);

        return redirect()->back()->with('success', 'Certification updated successfully.');
    }

    public function destroyCertification(Request $request, Site $site, SiteCertification $certification)
    {
        $this->authorize('update', $site);

        $this->certifications->revoke($site, $certification->id, $request->user());

        return redirect()->back()->with('success', 'Certification removed successfully.');
    }

    public function storeCheck(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'check_type' => 'required|string|in:fire_drill,evacuation_drill,health_safety_walkthrough,medication_audit,infection_control_audit,restraint_review,cultural_review,environmental_check,food_safety_check,vehicle_check,other',
            'scheduled_date' => 'required|date',
            'status' => 'sometimes|string|in:scheduled',
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'risk_rating' => 'nullable|string|in:low,medium,high,critical',
            'notes' => 'nullable|string|max:5000',
            'follow_up_date' => 'nullable|date',
            'follow_up_notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($site, $validated, $request): void {
            $site = $this->lockedSite($site);
            SiteComplianceCheck::create([
                ...$validated,
                'site_id' => $site->id,
                'status' => $validated['status'] ?? 'scheduled',
                'created_by' => $request->user()?->id,
            ]);
        });

        return redirect()->back()->with('success', 'Compliance check scheduled successfully.');
    }

    public function completeCheck(Request $request, Site $site, SiteComplianceCheck $check)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'risk_rating' => 'nullable|string|in:low,medium,high,critical',
            'follow_up_date' => 'nullable|date',
            'follow_up_notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($site, $check, $validated, $request): void {
            $site = $this->lockedSite($site);
            $check = $this->lockedSiteRecord(SiteComplianceCheck::class, $site, $check->id);
            abort_unless(
                in_array($check->status, ['scheduled', 'overdue', 'missed'], true),
                409,
                'Only an outstanding compliance check can be completed.',
            );
            $check->update([
                ...$validated,
                'status' => 'completed',
                'completed_date' => now()->toDateString(),
                'completed_by' => $request->user()?->id,
            ]);
        });

        return redirect()->back()->with('success', 'Compliance check completed successfully.');
    }

    public function updateCheck(Request $request, Site $site, SiteComplianceCheck $check)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'check_type' => 'sometimes|required|string|in:fire_drill,evacuation_drill,health_safety_walkthrough,medication_audit,infection_control_audit,restraint_review,cultural_review,environmental_check,food_safety_check,vehicle_check,other',
            'scheduled_date' => 'sometimes|required|date',
            'status' => 'sometimes|string|in:scheduled,overdue,missed,cancelled',
            'findings' => 'nullable|string',
            'corrective_actions' => 'nullable|string',
            'risk_rating' => 'nullable|string|in:low,medium,high,critical',
            'notes' => 'nullable|string|max:5000',
            'follow_up_date' => 'nullable|date',
            'follow_up_notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($site, $check, $validated): void {
            $site = $this->lockedSite($site);
            $check = $this->lockedSiteRecord(SiteComplianceCheck::class, $site, $check->id);
            abort_if($check->status === 'completed', 409, 'Completed compliance checks cannot be rescheduled.');
            $check->update($validated);
        });

        return redirect()->back()->with('success', 'Compliance check updated successfully.');
    }

    // Staff Requirements
    public function storeStaffRequirement(Request $request, Site $site)
    {
        $rootEntry = $this->beginStaffRequirementReceipt($request);
        $actorId = (int) $request->user()?->id;
        $this->authorize('update', $site);

        $validated = $request->validate([
            'request_id' => ['nullable', 'uuid'],
            'requirement_name' => 'required|string|max:255',
            'category' => 'required|string|in:mandatory,recommended,specialist',
            'description' => 'nullable|string',
            'certification_required' => 'boolean',
            'expiry_period_months' => 'nullable|integer|min:1',
            'hr_compliance_requirement_id' => app(WorkforceRequirementMapping::class)->validationRules(),
            'applicability_mode' => ['nullable', 'in:all_workers,minimum_staff'],
            'minimum_qualified_staff' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
        ]);

        $result = DB::transaction(function () use ($site, $validated): array {
            $site = $this->lockedSite($site);
            $validated = [...$validated, ...SiteStaffRequirement::applicabilityValues(
                $validated, app(HrEligibilityRuleSettings::class)->values()['house_qualification_approach'],
            )];
            $this->assertStaffRequirementIdentityAvailable($site, trim($validated['requirement_name']));
            unset($validated['request_id']);

            return $this->saveStaffRequirement($site, $validated);
        });

        return $this->withStaffRequirementReceipt($request,
            redirect()->back()->with('success', 'Staff requirement added successfully.'), $rootEntry, $actorId, $result);
    }

    public function updateStaffRequirement(Request $request, Site $site, SiteStaffRequirement $requirement)
    {
        $rootEntry = $this->beginStaffRequirementReceipt($request);
        $actorId = (int) $request->user()?->id;
        $this->authorize('update', $site);

        $validated = $request->validate([
            'request_id' => ['nullable', 'uuid'],
            'requirement_name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|in:mandatory,recommended,specialist',
            'description' => 'nullable|string',
            'certification_required' => 'boolean',
            'expiry_period_months' => 'nullable|integer|min:1',
            'hr_compliance_requirement_id' => app(WorkforceRequirementMapping::class)->validationRules(),
            'applicability_mode' => ['nullable', 'in:all_workers,minimum_staff'],
            'minimum_qualified_staff' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'is_active' => 'boolean',
        ]);

        $result = DB::transaction(function () use ($site, $requirement, $validated): array {
            $site = $this->lockedSite($site);
            $requirement = $this->lockedSiteRecord(SiteStaffRequirement::class, $site, $requirement->id);
            $validated = [...$validated, ...SiteStaffRequirement::applicabilityValues(
                $validated, app(HrEligibilityRuleSettings::class)->values()['house_qualification_approach'], $requirement,
            )];
            if (array_key_exists('requirement_name', $validated)) {
                $validated['requirement_name'] = trim($validated['requirement_name']);
                $this->assertStaffRequirementIdentityAvailable($site, $validated['requirement_name'], $requirement->id);
            }
            unset($validated['request_id']);

            return $this->saveStaffRequirement($site, $validated, $requirement);
        });

        return $this->withStaffRequirementReceipt($request,
            redirect()->back()->with('success', 'Staff requirement updated successfully.'), $rootEntry, $actorId, $result);
    }

    public function destroyStaffRequirement(Request $request, Site $site, SiteStaffRequirement $requirement)
    {
        $rootEntry = $this->beginStaffRequirementReceipt($request);
        $actorId = (int) $request->user()?->id;
        $this->authorize('update', $site);
        $request->validate(['request_id' => ['nullable', 'uuid']]);

        $result = DB::transaction(function () use ($site, $requirement): array {
            $site = $this->lockedSite($site);
            $row = $this->lockedSiteRecord(SiteStaffRequirement::class, $site, $requirement->id);
            $id = (int) $row->id;
            $values = $this->staffRequirementValues($row);
            $prior = $this->staffRequirementIntent($id);
            abort_unless($row->delete() === true && (int) $row->id === $id
                && $this->staffRequirementValues($row) === $values
                && ! SiteStaffRequirement::query()->whereKey($id)->lockForUpdate()->exists(), 409);
            $refresh = $this->confirmStaffRequirementIntent($row, $prior, true);

            return ['action' => 'deleted', 'site_id' => (int) $site->id, 'requirement_id' => $id,
                'outcome' => 'deleted', 'changed' => true, 'values' => null, 'refresh' => $refresh];
        });

        return $this->withStaffRequirementReceipt($request,
            redirect()->back()->with('success', 'Staff requirement removed successfully.'), $rootEntry, $actorId, $result);
    }

    public function storeCoverageRequirement(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coverage_type' => 'required|string|in:day,evening,overnight,custom',
            'day_of_week' => 'required|string|in:mon,tue,wed,thu,fri,sat,sun',
            'starts_time' => 'required|date_format:H:i',
            'ends_time' => 'required|date_format:H:i',
            'minimum_staff' => 'required|integer|min:1|max:12',
            'service_context_id' => 'nullable|integer',
            'preferred_client_id' => 'nullable|integer',
            'role_requirements' => 'nullable|array',
            'role_requirements.*.key' => 'required_with:role_requirements|string|distinct|in:caregiver,driver,med_competent',
            'role_requirements.*.minimum' => 'required_with:role_requirements|integer|min:1|max:12',
            'allow_overstaffing' => 'nullable|boolean',
            'shift_type' => 'nullable|string|in:standard,sleepover,on_call,split,travel',
            'notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($site, $validated): void {
            $site = $this->lockedSite($site);
            $validated['name'] = trim($validated['name']);
            $this->assertCoverageReferences($site, $validated);
            $this->assertCoverageIdentityAvailable($site, $validated);
            SiteCoverageRequirement::create([
                ...$validated,
                'site_id' => $site->id,
                'allow_overstaffing' => (bool) ($validated['allow_overstaffing'] ?? true),
                'is_active' => true,
            ]);
        });

        return redirect()->back()->with('success', 'Coverage requirement added successfully.');
    }

    public function updateCoverageRequirement(Request $request, Site $site, SiteCoverageRequirement $requirement)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'coverage_type' => 'sometimes|required|string|in:day,evening,overnight,custom',
            'day_of_week' => 'sometimes|required|string|in:mon,tue,wed,thu,fri,sat,sun',
            'starts_time' => 'sometimes|required|date_format:H:i',
            'ends_time' => 'sometimes|required|date_format:H:i',
            'minimum_staff' => 'sometimes|required|integer|min:1|max:12',
            'service_context_id' => 'nullable|integer',
            'preferred_client_id' => 'nullable|integer',
            'role_requirements' => 'nullable|array',
            'role_requirements.*.key' => 'required_with:role_requirements|string|distinct|in:caregiver,driver,med_competent',
            'role_requirements.*.minimum' => 'required_with:role_requirements|integer|min:1|max:12',
            'allow_overstaffing' => 'nullable|boolean',
            'shift_type' => 'nullable|string|in:standard,sleepover,on_call,split,travel',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($site, $requirement, $validated): void {
            $site = $this->lockedSite($site);
            $requirement = $this->lockedSiteRecord(SiteCoverageRequirement::class, $site, $requirement->id);
            $candidate = array_merge($requirement->only([
                'name',
                'day_of_week',
                'starts_time',
                'ends_time',
                'service_context_id',
                'preferred_client_id',
            ]), $validated);
            $candidate['name'] = trim($candidate['name']);
            $this->assertCoverageReferences($site, $candidate);
            $this->assertCoverageIdentityAvailable($site, $candidate, $requirement->id);
            $validated['name'] = $candidate['name'];
            $requirement->update($validated);
        });

        return redirect()->back()->with('success', 'Coverage requirement updated successfully.');
    }

    public function destroyCoverageRequirement(Request $request, Site $site, SiteCoverageRequirement $requirement)
    {
        $this->authorize('update', $site);

        DB::transaction(function () use ($site, $requirement): void {
            $site = $this->lockedSite($site);
            $this->lockedSiteRecord(SiteCoverageRequirement::class, $site, $requirement->id)->delete();
        });

        return redirect()->back()->with('success', 'Coverage requirement removed successfully.');
    }

    // Feedback
    public function feedback(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $filters = $request->validate([
            'type' => 'nullable|string|in:whanau,client,staff,external,complaint,compliment',
            'status' => 'nullable|string|in:new,acknowledged,in_progress,resolved,closed',
            'rating' => 'nullable|integer|min:1|max:5',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = SiteFeedback::where('site_id', $site->id);

        // Filters
        if (filled($filters['type'] ?? null)) {
            $query->where('feedback_type', $filters['type']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['rating'] ?? null)) {
            $query->where('rating', $filters['rating']);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $allFeedback = SiteFeedback::where('site_id', $site->id);

        $totalCount = (clone $allFeedback)->count();
        $avgRating = (clone $allFeedback)->whereNotNull('rating')->avg('rating');
        $openCount = (clone $allFeedback)->whereIn('status', ['new', 'acknowledged', 'in_progress'])->count();
        $respondedCount = (clone $allFeedback)->whereNotNull('response')->count();
        $responseRate = $totalCount > 0 ? round(($respondedCount / $totalCount) * 100) : 0;

        $feedback = $query->with(['respondedBy:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (SiteFeedback $item): array => $this->serializeFeedback($item))
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
            'filters' => $filters,
            'can' => [
                'manage_feedback' => (bool) ($request->user()?->canDo('sites.update')
                    && $request->user()?->can('update', $site)),
            ],
        ]);
    }

    public function storeFeedback(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'feedback_type' => 'required|string|in:whanau,client,staff,external,complaint,compliment',
            'submitted_by_name' => 'nullable|string|max:255',
            'submitted_by_relationship' => 'nullable|string|in:whanau,parent,sibling,advocate,staff,other',
            'content' => 'required|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'category' => 'nullable|string|in:care_quality,communication,environment,staff,food,activities,safety,other',
            'is_anonymous' => 'boolean',
        ]);

        DB::transaction(function () use ($site, $validated): void {
            $site = $this->lockedSite($site);
            $anonymous = (bool) ($validated['is_anonymous'] ?? false);
            SiteFeedback::create([
                ...$validated,
                'site_id' => $site->id,
                'submitted_by_name' => $anonymous ? null : ($validated['submitted_by_name'] ?? null),
                'submitted_by_relationship' => $anonymous ? null : ($validated['submitted_by_relationship'] ?? null),
                'status' => 'new',
            ]);
        });

        return redirect()->back()->with('success', 'Feedback submitted successfully.');
    }

    public function respondFeedback(Request $request, Site $site, SiteFeedback $feedback)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'response' => 'required|string',
        ]);

        DB::transaction(function () use ($site, $feedback, $validated, $request): void {
            $site = $this->lockedSite($site);
            $feedback = $this->lockedSiteRecord(SiteFeedback::class, $site, $feedback->id);
            abort_if($feedback->status === 'closed', 409, 'Closed feedback cannot be changed.');
            $feedback->update([
                'response' => $validated['response'],
                'responded_by' => $request->user()?->id,
                'responded_at' => now(),
                'status' => $feedback->status === 'new' ? 'acknowledged' : $feedback->status,
            ]);
        });

        return redirect()->back()->with('success', 'Response recorded successfully.');
    }

    public function updateFeedbackStatus(Request $request, Site $site, SiteFeedback $feedback)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'status' => 'required|string|in:new,acknowledged,in_progress,resolved,closed',
        ]);

        DB::transaction(function () use ($site, $feedback, $validated): void {
            $site = $this->lockedSite($site);
            $feedback = $this->lockedSiteRecord(SiteFeedback::class, $site, $feedback->id);
            abort_if($feedback->status === 'closed', 409, 'Closed feedback cannot be reopened.');
            $feedback->update($validated);
        });

        return redirect()->back()->with('success', 'Feedback status updated successfully.');
    }

    private function saveStaffRequirement(Site $site, array $data, ?SiteStaffRequirement $row = null): array
    {
        $creating = $row === null;
        $row ??= new SiteStaffRequirement([
            'description' => null, 'certification_required' => false, 'expiry_period_months' => null,
            'hr_compliance_requirement_id' => null, 'applicability_mode' => null,
            'minimum_qualified_staff' => null, 'is_active' => true,
        ]);
        $id = $creating ? null : (int) $row->id;
        $before = $creating ? null : $this->staffRequirementValues($row);
        $prior = $id ? $this->staffRequirementIntent($id) : null;
        if (isset($data['hr_compliance_requirement_id'])) {
            $data['hr_compliance_requirement_id'] = (int) $data['hr_compliance_requirement_id'];
        }
        $row->fill($data)->forceFill(['site_id' => (int) $site->id]);
        if ($creating) {
            $row->is_active = true;
            $row->requirement_name = trim($row->requirement_name);
        }
        $intended = $this->staffRequirementValues($row);
        $sourceFields = WorkforceEligibilitySources::definitions()[SiteStaffRequirement::class]['fields'];
        $structural = $creating || $row->isDirty($sourceFields);
        abort_unless($row->save() === true && $row->id > 0 && ($creating || (int) $row->id === $id), 409);
        $stored = SiteStaffRequirement::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
        abort_unless($this->staffRequirementValues($stored) === $intended, 409);
        $refresh = $structural ? $this->confirmStaffRequirementIntent($stored, $prior)
            : $this->unchangedStaffRequirementIntent((int) $stored->id, $prior);
        $changed = $creating || $before !== $intended;

        return ['action' => $creating ? 'created' : 'updated', 'site_id' => (int) $site->id,
            'requirement_id' => (int) $stored->id, 'outcome' => $changed ? 'saved' : 'unchanged',
            'changed' => $changed, 'values' => $intended, 'refresh' => $refresh];
    }

    /** Full normalized stored control values; omitted edit fields retain their exact value. */
    private function staffRequirementValues(SiteStaffRequirement $row): array
    {
        return ['site_id' => (int) $row->site_id, 'requirement_name' => $row->requirement_name,
            'category' => $row->category, 'description' => $row->description,
            'certification_required' => (bool) $row->certification_required,
            'expiry_period_months' => $row->expiry_period_months === null ? null : (int) $row->expiry_period_months,
            'hr_compliance_requirement_id' => $row->hr_compliance_requirement_id === null ? null : (int) $row->hr_compliance_requirement_id,
            'applicability_mode' => $row->applicability_mode,
            'minimum_qualified_staff' => $row->minimum_qualified_staff === null ? null : (int) $row->minimum_qualified_staff,
            'is_active' => (bool) $row->is_active];
    }

    private function staffRequirementIntent(int $id): ?array
    {
        $intent = WorkforceEligibilityRecheck::query()->where('source_type', 'site_staff_requirements')
            ->where('source_id', $id)->lockForUpdate()->first();

        if (! $intent) {
            return null;
        }

        return [...$intent->only(['source_type', 'source_id', 'source_fingerprint', 'source_version',
            'user_ids', 'site_ids', 'client_ids', 'shift_ids', 'all_assigned', 'status']),
            'source_id' => (int) $intent->source_id];
    }

    private function confirmStaffRequirementIntent(SiteStaffRequirement $row, ?array $prior, bool $deleted = false): array
    {
        $description = app(WorkforceEligibilitySources::class)->describe($row, $deleted);
        abort_unless($description !== null, 409);
        $current = $this->staffRequirementIntent((int) $row->id);
        $deduplicated = $prior && (int) $prior['source_version'] > 0
            && $prior['source_fingerprint'] === $description['fingerprint'];
        if ($deduplicated) {
            $expected = $prior;
        } else {
            $empty = ['user_ids' => [], 'site_ids' => [], 'client_ids' => [], 'shift_ids' => [], 'all_assigned' => false];
            $previous = $prior && in_array($prior['status'], ['pending', 'processing', 'failed'], true) ? $prior : $empty;
            $expected = ['source_type' => $row->getTable(), 'source_id' => (int) $row->id,
                'source_fingerprint' => $description['fingerprint'], 'source_version' => (int) ($prior['source_version'] ?? 0) + 1];
            foreach (['user_ids', 'site_ids', 'client_ids', 'shift_ids'] as $field) {
                $expected[$field] = collect([...($previous[$field] ?? []), ...($description['scope'][$field] ?? [])])
                    ->map(fn ($value): int => (int) $value)->filter(fn (int $value): bool => $value > 0)->unique()->sort()->values()->all();
            }
            $expected['all_assigned'] = (bool) (($previous['all_assigned'] ?? false) || ($description['scope']['all_assigned'] ?? false));
            $expected['status'] = 'pending';
        }
        abort_unless($current === $expected && in_array((int) $row->site_id, $current['site_ids'], true), 409);

        return $this->staffRequirementRefreshProjection($row, $current);
    }

    private function unchangedStaffRequirementIntent(int $id, ?array $prior): ?array
    {
        abort_unless($this->staffRequirementIntent($id) === $prior, 409);

        return null;
    }

    private function staffRequirementRefreshProjection(SiteStaffRequirement $row, array $intent): array
    {
        return ['source_type' => $row->getTable(), 'source_id' => (int) $row->id,
            'source_fingerprint' => $intent['source_fingerprint'], 'source_version' => (int) $intent['source_version'],
            'site_id' => (int) $row->site_id];
    }

    private function beginStaffRequirementReceipt(Request $request): bool
    {
        $request->session()->forget('house_qualification_result');

        return $this->staffRequirementReceiptIsRoot(true);
    }

    private function staffRequirementReceiptIsRoot(bool $rootEntry): bool
    {
        if (! $rootEntry) {
            return false;
        }
        try {
            return DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    private function withStaffRequirementReceipt(Request $request, RedirectResponse $response, bool $rootEntry, int $actorId, array $result): RedirectResponse
    {
        if (! $this->staffRequirementReceiptIsRoot($rootEntry) || $actorId < 1 || $request->user()?->id !== $actorId) {
            return $response;
        }
        try {
            $result = $this->projectStaffRequirementResult(['version' => 1, 'scope' => 'house_requirement',
                'request_id' => $request->input('request_id'), 'actor_id' => $actorId, ...$result,
                'committed_at' => now()->utc()->format('Y-m-d\TH:i:s').'.000Z']);
            if ($this->staffRequirementReceiptIsRoot($rootEntry) && $request->user()?->id === $actorId) {
                $response->with('house_qualification_result', $result);
            }
        } catch (Throwable) {
            try {
                $request->session()->forget('house_qualification_result');
            } catch (Throwable) {
            }
        }

        return $response;
    }

    /** Separate only the post-commit wire projection from mandatory persistence. */
    protected function projectStaffRequirementResult(array $result): array
    {
        return $result;
    }

    private function lockedSite(Site $site): Site
    {
        return Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
    }

    /** @param class-string<Model> $modelClass */
    private function lockedSiteRecord(string $modelClass, Site $site, int $recordId)
    {
        return $modelClass::query()
            ->where('site_id', $site->id)
            ->whereKey($recordId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertStaffRequirementIdentityAvailable(
        Site $site,
        string $name,
        ?int $ignoreId = null,
    ): void {
        $exists = SiteStaffRequirement::query()
            ->where('site_id', $site->id)
            ->where('requirement_name', $name)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'requirement_name' => 'A staff requirement with this name already exists at the Site.',
            ]);
        }
    }

    private function assertCoverageReferences(Site $site, array $data): void
    {
        $serviceContextId = $data['service_context_id'] ?? null;
        if ($serviceContextId !== null && ! ServiceContext::query()
            ->whereKey($serviceContextId)
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first()) {
            throw ValidationException::withMessages([
                'service_context_id' => 'Choose an active service context belonging to this Site.',
            ]);
        }

        $clientId = $data['preferred_client_id'] ?? null;
        if ($clientId !== null && ! Client::query()
            ->whereKey($clientId)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->first()) {
            throw ValidationException::withMessages([
                'preferred_client_id' => 'Choose a planning client belonging to this Site.',
            ]);
        }
    }

    private function assertCoverageIdentityAvailable(
        Site $site,
        array $data,
        ?int $ignoreId = null,
    ): void {
        $exists = SiteCoverageRequirement::query()
            ->where('site_id', $site->id)
            ->where('name', $data['name'])
            ->where('day_of_week', $data['day_of_week'])
            ->where('starts_time', $data['starts_time'])
            ->where('ends_time', $data['ends_time'])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'This coverage requirement already exists for the Site, day, and time.',
            ]);
        }
    }

    private function serializeFeedback(SiteFeedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'feedback_type' => $feedback->feedback_type,
            'submitted_by_name' => $feedback->is_anonymous ? null : $feedback->submitted_by_name,
            'submitted_by_relationship' => $feedback->is_anonymous ? null : $feedback->submitted_by_relationship,
            'content' => $feedback->content,
            'rating' => $feedback->rating,
            'category' => $feedback->category,
            'status' => $feedback->status,
            'response' => $feedback->response,
            'responded_by' => $feedback->respondedBy ? [
                'id' => $feedback->respondedBy->id,
                'name' => $feedback->respondedBy->name,
            ] : null,
            'responded_at' => $feedback->responded_at?->toISOString(),
            'is_anonymous' => (bool) $feedback->is_anonymous,
            'created_at' => $feedback->created_at?->toISOString(),
        ];
    }

    private function serializeCertification(SiteCertification $certification): array
    {
        return [
            'id' => $certification->id,
            'certification_type' => $certification->certification_type,
            'name' => $certification->name,
            'issuing_body' => $certification->issuing_body,
            'reference_number' => $certification->reference_number,
            'status' => $certification->status,
            'issued_date' => $certification->issued_date?->toDateString(),
            'expiry_date' => $certification->expiry_date?->toDateString(),
            'next_review_date' => $certification->next_review_date?->toDateString(),
            'notes' => $certification->notes,
            'reviewed_by' => $certification->reviewedBy ? [
                'id' => $certification->reviewedBy->id,
                'name' => $certification->reviewedBy->name,
            ] : null,
            'reviewed_at' => $certification->reviewed_at?->toISOString(),
        ];
    }

    private function serializeComplianceCheck(SiteComplianceCheck $check): array
    {
        return [
            'id' => $check->id,
            'check_type' => $check->check_type,
            'scheduled_date' => $check->scheduled_date?->toDateString(),
            'completed_date' => $check->completed_date?->toDateString(),
            'completed_by' => $check->completedBy ? [
                'id' => $check->completedBy->id,
                'name' => $check->completedBy->name,
            ] : null,
            'status' => $check->status,
            'findings' => $check->findings,
            'corrective_actions' => $check->corrective_actions,
            'risk_rating' => $check->risk_rating,
            'notes' => $check->notes,
            'follow_up_date' => $check->follow_up_date?->toDateString(),
            'follow_up_notes' => $check->follow_up_notes,
        ];
    }
}
