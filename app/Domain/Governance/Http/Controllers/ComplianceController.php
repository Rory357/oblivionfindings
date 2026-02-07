<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function __construct(
        protected ComplianceEngineService $complianceService
    ) {}

    public function create()
    {
        return Inertia::render('Governance/Compliance/Create', [
            'frameworks' => $this->getFrameworks(),
        ]);
    }

    public function index(Request $request)
    {
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
        $obligation->load(['owner', 'evidence.uploadedBy', 'reminders']);

        return Inertia::render('Governance/Compliance/Show', [
            'obligation' => $obligation,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'framework' => 'required|string',
            'obligation_reference' => 'nullable|string|max:50',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'requirements' => 'nullable|string',
            'due_date' => 'nullable|date',
            'owner_id' => 'nullable|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ]);

        $owner = $validated['owner_id'] ? \App\Models\User::find($validated['owner_id']) : null;
        $dueDate = $validated['due_date'] ? Carbon::parse($validated['due_date']) : null;
        
        $obligation = $this->complianceService->createObligation(
            $validated['framework'],
            $validated['title'],
            $validated['description'],
            'annual', // default frequency
            $owner ?? auth()->user(),
            $dueDate,
            $validated['obligation_reference'] ?? null,
            $validated['reminder_days'] ?? null
        );

        // Schedule reminders
        $this->complianceService->scheduleReminders($obligation);

        return redirect()->route('governance.compliance.show', $obligation)
            ->with('success', 'Compliance obligation created.');
    }

    public function update(Request $request, ComplianceObligation $obligation)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'due_date' => 'sometimes|date',
            'owner_id' => 'sometimes|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        $obligation->update($validated);

        return redirect()->back()->with('success', 'Obligation updated.');
    }

    public function complete(Request $request, ComplianceObligation $obligation)
    {
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
        $obligations = ComplianceObligation::where('due_date', '<=', now()->addDays(90))
            ->where('status', '!=', 'complete')
            ->with('owner')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('Governance/Compliance/Calendar', [
            'events' => $obligations->map(fn($o) => [
                'id' => $o->id,
                'title' => $o->obligation_title,
                'date' => $o->due_date->toDateString(),
                'framework' => $o->getFrameworkLabel(),
                'status' => $o->status,
                'owner' => $o->owner?->name,
            ]),
        ]);
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
