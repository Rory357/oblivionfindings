<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreComplianceObligationRequest;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    private const INCIDENT_SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites', 'reports.viewAny'];

    private const STAFF_SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        protected ComplianceEngineService $complianceService,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function create()
    {
        $this->authorize('create', ComplianceObligation::class);

        return Inertia::render('Governance/Compliance/Create', [
            'frameworks' => $this->getFrameworks(),
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', ComplianceObligation::class);

        $query = ComplianceObligation::with(['owner', 'evidence']);

        if ($request->has('framework')) {
            $query->byFramework($request->framework);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('owner_id')) {
            $query->forOwner($request->owner_id);
        }

        $obligations = $query->orderBy('due_date')->paginate(20);

        return Inertia::render('Governance/Compliance/Index', [
            'obligations' => $obligations,
            'summary' => $this->complianceService->getComplianceStatus(),
            'frameworks' => $this->getFrameworks(),
            'filters' => $request->only(['framework', 'status', 'owner_id']),
        ]);
    }

    public function show(ComplianceObligation $obligation)
    {
        $this->authorize('view', $obligation);

        $obligation->load(['owner', 'evidence.uploadedBy', 'reminders']);

        return Inertia::render('Governance/Compliance/Show', [
            'obligation' => $obligation,
        ]);
    }

    public function store(StoreComplianceObligationRequest $request)
    {
        $this->authorize('create', ComplianceObligation::class);

        $validated = $request->validated();

        $owner = $this->resolveOwner($request, $validated['owner_id'] ?? null);
        $dueDate = $validated['due_date'] ? Carbon::parse($validated['due_date']) : null;

        $obligation = $this->complianceService->createObligation(
            $validated['framework'],
            $validated['title'],
            $validated['description'],
            $validated['frequency'] ?? 'annual',
            $owner ?? auth()->user(),
            $dueDate,
            $validated['obligation_reference'] ?? null,
            $validated['reminder_days'] ?? null,
            $validated['priority'] ?? 'medium',
            $validated['requirements'] ?? null
        );

        // Schedule reminders
        $this->complianceService->scheduleReminders($obligation);

        // Modal callers (e.g. the /compliance command centre wizard) stay on the page
        // and show a success pane — preserve their context instead of redirecting to show.
        if ($request->boolean('_modal')) {
            return back()->with('success', 'Compliance obligation created.');
        }

        return redirect()->route('governance.compliance.show', $obligation)
            ->with('success', 'Compliance obligation created.');
    }

    public function update(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('update', $obligation);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'due_date' => 'sometimes|date',
            'owner_id' => 'sometimes|nullable|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if (array_key_exists('title', $validated)) {
            $validated['obligation_title'] = $validated['title'];
            unset($validated['title']);
        }

        if (array_key_exists('owner_id', $validated) && $validated['owner_id'] !== null) {
            $validated['owner_id'] = $this->resolveOwner($request, $validated['owner_id'])->id;
        }

        $obligation->update($validated);

        return redirect()->back()->with('success', 'Obligation updated.');
    }

    public function complete(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('complete', $obligation);

        $validated = $request->validate([
            'evidence_ids' => 'nullable|array',
            'evidence_ids.*' => 'exists:compliance_evidence,id',
        ]);

        $this->complianceService->completeObligation(
            $obligation,
            auth()->user(),
            $validated['evidence_ids'] ?? null
        );

        return redirect()->back()->with('success', 'Obligation marked complete.');
    }

    public function uploadEvidence(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('uploadEvidence', $obligation);

        $validated = $request->validate([
            'evidence_type' => 'required|in:document,audit_report,certification,system_export,attestation',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:10240', // 10MB max
            'valid_until' => 'nullable|date|after:today',
        ]);

        $this->complianceService->uploadEvidence(
            $obligation,
            $validated['evidence_type'],
            $validated['title'],
            $request->file('file'),
            auth()->user(),
            $validated['valid_until'] ? Carbon::parse($validated['valid_until']) : null
        );

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    public function calendar()
    {
        $this->authorize('viewAny', ComplianceObligation::class);

        $obligations = ComplianceObligation::where('due_date', '<=', now()->addDays(90))
            ->where('status', '!=', 'complete')
            ->with('owner')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('Governance/Compliance/Calendar', [
            'events' => $obligations->map(fn ($o) => [
                'id' => $o->id,
                'title' => $o->obligation_title,
                'date' => $o->due_date->toDateString(),
                'framework' => $o->getFrameworkLabel(),
                'status' => $o->status,
                'owner' => $o->owner?->name,
            ]),
        ]);
    }

    public function edit(ComplianceObligation $obligation)
    {
        $this->authorize('update', $obligation);

        return Inertia::render('Governance/Compliance/Edit', [
            'obligation' => $obligation,
        ]);
    }

    public function storeNotifiableIncident(Request $request)
    {
        $this->authorize('notifyIncident', ComplianceObligation::class);

        $validated = $request->validate([
            'incident_type' => 'required|in:death,serious_harm,serious_injury,health_safety,privacy_breach',
            'notification_authority' => 'required|in:worksafe,health_nz,privacy_commissioner,charities_services',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'severity' => 'required|in:critical,high,medium',
            'occurred_at' => 'required|date',
            'discovered_at' => 'nullable|date',
            'related_incident_id' => 'nullable|integer|min:1',
        ]);

        if (filled($validated['related_incident_id'] ?? null)) {
            $relatedIncident = ClientIncident::query()->whereKey((int) $validated['related_incident_id']);
            $this->siteAccess->applyClientIncidentScope(
                $relatedIncident,
                $request->user(),
                self::INCIDENT_SITE_BYPASS_PERMISSIONS,
            );

            if (! $relatedIncident->exists()) {
                throw ValidationException::withMessages([
                    'related_incident_id' => 'The selected incident is not available.',
                ]);
            }
        }

        $incident = NotifiableIncident::create([
            ...$validated,
            'status' => 'pending',
            'submitted_by' => auth()->id(),
        ]);

        $message = 'Notifiable incident recorded. Ensure timely notification to '.$validated['notification_authority'].'.';

        if ($request->boolean('_modal')) {
            return back()->with('success', $message);
        }

        return redirect()->route('governance.compliance.index')->with('success', $message);
    }

    private function resolveOwner(Request $request, mixed $ownerId): ?User
    {
        if (blank($ownerId)) {
            return null;
        }

        $owner = User::query()->whereKey((int) $ownerId);
        $this->siteAccess->applyStaffScope(
            $owner,
            $request->user(),
            self::STAFF_SITE_BYPASS_PERMISSIONS,
        );

        $resolved = $owner->first();
        if (! $resolved) {
            throw ValidationException::withMessages([
                'owner_id' => 'The selected owner is not available.',
            ]);
        }

        return $resolved;
    }

    protected function getFrameworks(): array
    {
        return [
            ['value' => 'charities', 'label' => 'Charities Services'],
            ['value' => 'nga_paerewa', 'label' => 'Ngā Paerewa NZS 8134:2021'],
            ['value' => 'hdsa_safety', 'label' => 'H&D Services (Safety) Act'],
            ['value' => 'privacy_act', 'label' => 'Privacy Act 2020'],
            ['value' => 'hip_code', 'label' => 'Health Information Privacy Code'],
            ['value' => 'hswa', 'label' => 'Health and Safety at Work Act'],
            ['value' => 'employment', 'label' => 'Employment Relations'],
            ['value' => 'funding_moh', 'label' => 'MoH/Health NZ Funding'],
            ['value' => 'funding_msd', 'label' => 'MSD Funding'],
            ['value' => 'funding_acc', 'label' => 'ACC Funding'],
        ];
    }
}
