<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Http\Requests\StoreComplianceObligationRequest;
use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\ComplianceReminder;
use App\Domain\Governance\Models\NotifiableIncident;
use App\Domain\Governance\Services\ComplianceEngineService;
use App\Domain\Governance\Services\GovernanceAuditService;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    use ServesPrivateAttachments;

    private const INCIDENT_SITE_BYPASS_PERMISSIONS = ['healthSafety.viewAllSites', 'reports.viewAny'];

    private const STAFF_SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    private const FREQUENCIES = ['monthly', 'quarterly', 'annual', 'ad_hoc', 'event_driven'];

    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    private const EVIDENCE_MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,webp,csv,txt,md';

    public function __construct(
        protected ComplianceEngineService $complianceService,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * The full-page create form was retired for the shared wizard dialog on the
     * register index; old deep links open that dialog instead.
     */
    public function create()
    {
        $this->authorize('create', ComplianceObligation::class);

        return redirect()->route('governance.compliance.index', ['create' => 1]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', ComplianceObligation::class);

        $today = ComplianceObligation::nzToday();
        $query = ComplianceObligation::with(['owner:id,name']);

        if ($request->filled('framework')) {
            $query->byFramework((string) $request->framework);
        }

        // Statuses are worked out from each due date, so the list always
        // matches the header counts (which use the same scopes).
        if ($request->filled('status')) {
            $query->withCurrentStatus((string) $request->status, $today);
        }

        if ($request->filled('owner_id')) {
            $query->forOwner((int) $request->owner_id);
        }

        if ($request->filled('search')) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $request->search)).'%';
            $query->where(fn ($q) => $q->where('obligation_title', 'like', $term)
                ->orWhere('obligation_code', 'like', $term));
        }

        $obligations = $query->orderBy('due_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ComplianceObligation $obligation) => $this->presentListItem($obligation, $today));

        $canManage = $request->user()->can('create', ComplianceObligation::class);

        return Inertia::render('Governance/Compliance/Index', [
            'obligations' => $obligations,
            'summary' => $this->complianceService->getComplianceStatus($today),
            'upcomingCount' => ComplianceObligation::query()
                ->open()
                ->whereDate('due_date', '<=', Carbon::createFromFormat('!Y-m-d', $today)->addDays(90)->toDateString())
                ->count(),
            'frameworks' => ComplianceObligation::frameworkSelectOptions(),
            'filters' => $request->only(['framework', 'status', 'owner_id', 'search']),
            'canCreate' => $canManage,
            // Wizard reference data — only for users who can manage requirements.
            'formOptions' => $canManage ? fn () => $this->obligationFormOptions($request) : null,
        ]);
    }

    public function show(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('view', $obligation);

        $obligation->load([
            'owner:id,name',
            'completedBy:id,name',
            'signedOffBy:id,name',
            'evidence.uploadedBy:id,name',
            'reminders',
            'parentObligation:id,obligation_title,due_date',
            'recurrences:id,parent_obligation_id,obligation_title,due_date,status',
        ]);

        $canUpdate = $request->user()->can('update', $obligation);

        return Inertia::render('Governance/Compliance/Show', [
            'obligation' => $this->presentObligation($obligation),
            'abilities' => [
                'update' => $canUpdate,
                'complete' => $request->user()->can('complete', $obligation),
                'uploadEvidence' => $request->user()->can('uploadEvidence', $obligation),
            ],
            // Edit wizard reference data — only for users who can edit this requirement.
            'formOptions' => $canUpdate ? fn () => $this->obligationFormOptions($request) : null,
        ]);
    }

    public function store(StoreComplianceObligationRequest $request)
    {
        $this->authorize('create', ComplianceObligation::class);

        $validated = $request->validated();

        $owner = $this->resolveOwner($request, $validated['owner_id'] ?? null);
        $dueDate = ! empty($validated['due_date']) ? Carbon::parse($validated['due_date']) : null;

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
            $validated['requirements'] ?? null,
            array_key_exists('evidence_required', $validated) ? (bool) $validated['evidence_required'] : true,
        );

        // Schedule reminders
        $this->complianceService->scheduleReminders($obligation);

        $message = 'Requirement added. Its owner will be reminded before it is due on '
            .GovernanceLabels::date($obligation->due_date->toDateString()).'.';

        // Modal callers (e.g. the /compliance command centre wizard) stay on the page
        // and show a success pane — preserve their context instead of redirecting to show.
        if ($request->boolean('_modal')) {
            return back()->with('success', $message);
        }

        return redirect()->route('governance.compliance.show', $obligation)
            ->with('success', $message);
    }

    /**
     * Edit a requirement, including the fields that used to be fixed once it
     * was added. Every change is kept in the audit log with before and after.
     */
    public function update(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('update', $obligation);

        $validated = $request->validate([
            // A requirement filed under an older framework key can keep it.
            'framework' => ['sometimes', 'string', Rule::in([...array_keys(ComplianceObligation::frameworkOptions()), (string) $obligation->framework])],
            'obligation_reference' => 'sometimes|nullable|string|max:50',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'requirements' => 'sometimes|nullable|string',
            'frequency' => ['sometimes', 'string', Rule::in(self::FREQUENCIES)],
            'priority' => ['sometimes', 'string', Rule::in(self::PRIORITIES)],
            'evidence_required' => 'sometimes|boolean',
            'due_date' => 'sometimes|date',
            'owner_id' => 'sometimes|nullable|integer|min:1',
            'notes' => 'nullable|string',
        ], StoreComplianceObligationRequest::plainMessages());

        $payload = [];
        foreach (['framework', 'requirements', 'frequency', 'priority', 'description', 'due_date', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('title', $validated)) {
            $payload['obligation_title'] = $validated['title'];
        }

        if (array_key_exists('obligation_reference', $validated)) {
            $payload['obligation_code'] = $validated['obligation_reference'];
        }

        if (array_key_exists('evidence_required', $validated)) {
            $payload['evidence_required'] = (bool) $validated['evidence_required'];
        }

        if (array_key_exists('due_date', $validated)) {
            $payload['next_due_date'] = $validated['due_date'];
        }

        if (array_key_exists('owner_id', $validated) && $validated['owner_id'] !== null) {
            $payload['owner_id'] = $this->resolveOwner($request, $validated['owner_id'])->id;
        }

        $obligation->fill($payload);
        $changed = array_keys($obligation->getDirty());
        $dueDateChanged = $obligation->isDirty('due_date');
        $obligation->save();

        if ($dueDateChanged && $obligation->status !== 'complete') {
            $this->complianceService->scheduleReminders($obligation);
        }

        $changed = array_values(array_diff($changed, ['status', 'updated_at']));
        if ($changed !== []) {
            GovernanceAuditService::log('compliance.updated', 'ComplianceObligation', $obligation->id, [
                'fields' => $changed,
            ]);
        }

        return redirect()->back()->with('success', 'Requirement saved.');
    }

    public function complete(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('complete', $obligation);

        $validated = $request->validate([
            'evidence_ids' => 'nullable|array',
            'evidence_ids.*' => 'exists:compliance_evidence,id',
            'completion_notes' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'expected_version' => 'nullable|integer|min:1',
        ], [
            'evidence_ids.*.exists' => 'Some of the chosen evidence no longer exists. Refresh the page and choose again.',
            'completion_notes.max' => 'Keep the notes to 2,000 characters or fewer.',
        ]);

        $notes = $validated['completion_notes'] ?? $validated['notes'] ?? null;
        $expectedVersion = isset($validated['expected_version']) ? (int) $validated['expected_version'] : null;

        $this->complianceService->completeObligation(
            $obligation,
            auth()->user(),
            $validated['evidence_ids'] ?? null,
            $notes,
            $expectedVersion
        );

        return redirect()->back()->with('success', 'Requirement marked as done.');
    }

    public function uploadEvidence(Request $request, ComplianceObligation $obligation)
    {
        $this->authorize('uploadEvidence', $obligation);

        $validated = $request->validate([
            'evidence_type' => 'required|in:document,audit_report,certification,system_export,attestation',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'file' => 'required|file|max:10240|mimes:'.self::EVIDENCE_MIMES,
            'valid_until' => 'nullable|date|after:today',
        ], [
            'evidence_type.required' => 'Choose what kind of evidence this is.',
            'evidence_type.in' => 'Choose one of the listed kinds of evidence.',
            'title.required' => 'Give the evidence a name, such as "2026 annual return receipt".',
            'title.max' => 'Keep the name to 255 characters or fewer.',
            'file.required' => 'Choose the file to upload.',
            'file.file' => "The file didn't upload properly. Try again.",
            'file.max' => 'The file is too big. Files can be up to 10 MB.',
            'file.mimes' => 'Upload a PDF, Word, Excel, PowerPoint, image, CSV or text file.',
            'valid_until.date' => 'Enter the "valid until" date as a date.',
            'valid_until.after' => 'The "valid until" date must be after today. Leave it blank if the evidence does not expire.',
        ]);

        $this->complianceService->uploadEvidence(
            $obligation,
            $validated['evidence_type'],
            $validated['title'],
            $request->file('file'),
            auth()->user(),
            ! empty($validated['valid_until']) ? Carbon::parse($validated['valid_until']) : null,
            $validated['description'] ?? null,
        );

        return redirect()->back()->with('success', 'Evidence uploaded.');
    }

    /**
     * Open or download one piece of evidence. Only people who can view the
     * requirement get the file, and it is served from private storage with
     * the hardened attachment headers under its uploaded name.
     */
    public function downloadEvidence(Request $request, ComplianceObligation $obligation, ComplianceEvidence $evidence)
    {
        $this->authorize('view', $obligation);

        abort_unless((int) $evidence->compliance_obligation_id === (int) $obligation->id, 404);

        $disk = $evidence->storedDisk();
        abort_unless($disk !== null, 404);

        GovernanceAuditService::log('compliance.evidence_downloaded', 'ComplianceObligation', $obligation->id, [
            'evidence_id' => $evidence->id,
        ]);

        return $this->streamPrivateAttachment(
            $disk,
            (string) $evidence->file_path,
            $evidence->downloadName(),
            $evidence->mime_type,
            $request->boolean('inline') ? 'inline' : 'attachment',
        );
    }

    public function calendar()
    {
        $this->authorize('viewAny', ComplianceObligation::class);

        $today = ComplianceObligation::nzToday();
        $obligations = ComplianceObligation::query()
            ->open()
            ->whereDate('due_date', '<=', Carbon::createFromFormat('!Y-m-d', $today)->addDays(90)->toDateString())
            ->with('owner:id,name')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('Governance/Compliance/Calendar', [
            'events' => $obligations->map(fn (ComplianceObligation $o) => [
                'id' => $o->id,
                'title' => $o->obligation_title,
                'date' => $o->due_date->toDateString(),
                'framework' => $o->getFrameworkLabel(),
                'status' => $o->currentStatus($today),
                'owner' => $o->owner?->name,
            ]),
        ]);
    }

    /**
     * The full-page edit form was retired for the shared wizard dialog on the
     * requirement record; old deep links open that dialog instead.
     */
    public function edit(ComplianceObligation $obligation)
    {
        $this->authorize('update', $obligation);

        return redirect()->route('governance.compliance.show', ['obligation' => $obligation, 'edit' => 1]);
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

        $authority = match ($validated['notification_authority']) {
            'worksafe' => 'WorkSafe',
            'health_nz' => 'Health New Zealand',
            'privacy_commissioner' => 'the Privacy Commissioner',
            'charities_services' => 'Charities Services',
            default => 'the right authority',
        };
        $message = "Notifiable incident recorded. Make sure {$authority} is told in time.";

        if ($request->boolean('_modal')) {
            return back()->with('success', $message);
        }

        return redirect()->route('governance.compliance.index')->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentListItem(ComplianceObligation $obligation, string $today): array
    {
        return [
            'id' => $obligation->id,
            'framework' => $obligation->framework,
            'framework_label' => $obligation->getFrameworkLabel(),
            'obligation_code' => $obligation->obligation_code,
            'obligation_title' => $obligation->obligation_title,
            'due_date' => $obligation->due_date?->toDateString(),
            'days_until_due' => $obligation->daysUntilDue($today),
            'status' => $obligation->currentStatus($today),
            'priority' => $obligation->priority,
            'evidence_provided' => (bool) $obligation->evidence_provided,
            'evidence_required' => (bool) $obligation->evidence_required,
            'owner' => $obligation->owner ? ['id' => $obligation->owner->id, 'name' => $obligation->owner->name] : null,
        ];
    }

    /**
     * The requirement record — never storage paths.
     *
     * @return array<string, mixed>
     */
    private function presentObligation(ComplianceObligation $obligation): array
    {
        $today = ComplianceObligation::nzToday();

        return [
            'id' => $obligation->id,
            'framework' => $obligation->framework,
            'framework_label' => $obligation->getFrameworkLabel(),
            'obligation_code' => $obligation->obligation_code,
            'obligation_title' => $obligation->obligation_title,
            'description' => $obligation->description,
            'requirements' => $obligation->requirements,
            'frequency' => $obligation->frequency,
            'priority' => $obligation->priority,
            'due_date' => $obligation->due_date?->toDateString(),
            'next_due_date' => $obligation->next_due_date?->toDateString(),
            'days_until_due' => $obligation->daysUntilDue($today),
            'status' => $obligation->currentStatus($today),
            'owner_id' => $obligation->owner_id,
            'owner' => $obligation->owner ? ['id' => $obligation->owner->id, 'name' => $obligation->owner->name] : null,
            'completed_at' => $obligation->completed_at?->toIso8601String(),
            'completed_by' => $obligation->completedBy ? ['name' => $obligation->completedBy->name] : null,
            'completion_notes' => $obligation->completion_notes,
            'version_number' => (int) ($obligation->version_number ?? 1),
            'parent_obligation_id' => $obligation->parent_obligation_id,
            'parent_obligation' => $obligation->parentObligation ? [
                'id' => $obligation->parentObligation->id,
                'obligation_title' => $obligation->parentObligation->obligation_title,
                'due_date' => $obligation->parentObligation->due_date?->toDateString(),
            ] : null,
            'recurrences' => $obligation->recurrences->map(fn (ComplianceObligation $next) => [
                'id' => $next->id,
                'obligation_title' => $next->obligation_title,
                'due_date' => $next->due_date?->toDateString(),
                'status' => $next->currentStatus($today),
            ])->values()->all(),
            'evidence_required' => (bool) $obligation->evidence_required,
            'evidence_provided' => (bool) $obligation->evidence_provided,
            'sign_off_required' => (bool) $obligation->sign_off_required,
            'signed_off_at' => $obligation->signed_off_at?->toIso8601String(),
            'signed_off_by' => $obligation->signedOffBy ? ['name' => $obligation->signedOffBy->name] : null,
            'notes' => $obligation->notes,
            'evidence' => $obligation->evidence
                ->sortByDesc('uploaded_at')
                ->map(fn (ComplianceEvidence $evidence) => $this->presentEvidence($obligation, $evidence, $today))
                ->values()
                ->all(),
            'reminders' => $obligation->reminders
                ->sortBy('scheduled_at')
                ->map(fn (ComplianceReminder $reminder) => [
                    'id' => $reminder->id,
                    'days_before_due' => (int) $reminder->days_before_due,
                    'scheduled_at' => $reminder->scheduled_at?->toIso8601String(),
                    'status' => $reminder->status,
                    'sent_at' => $reminder->sent_at?->toIso8601String(),
                    'is_escalation' => (bool) ($reminder->is_escalation ?? false),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEvidence(ComplianceObligation $obligation, ComplianceEvidence $evidence, string $today): array
    {
        $hasFile = $evidence->storedDisk() !== null;
        $base = "/governance/compliance/{$obligation->id}/evidence/{$evidence->id}/download";

        return [
            'id' => $evidence->id,
            'title' => $evidence->title,
            'description' => $evidence->description,
            'evidence_type' => $evidence->evidence_type,
            'file_name' => $hasFile ? $evidence->downloadName() : null,
            'file_size' => $evidence->file_size !== null ? (int) $evidence->file_size : null,
            'valid_from' => $evidence->valid_from?->toDateString(),
            'valid_until' => $evidence->valid_until?->toDateString(),
            'expired' => $evidence->isExpired($today),
            'uploaded_by' => $evidence->uploadedBy ? ['name' => $evidence->uploadedBy->name] : null,
            'uploaded_at' => $evidence->uploaded_at?->toIso8601String(),
            'open_url' => $hasFile ? "{$base}?inline=1" : null,
            'download_url' => $hasFile ? $base : null,
        ];
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
                'owner_id' => 'Choose an owner from the list — the person you picked is not available.',
            ]);
        }

        return $resolved;
    }

    /**
     * Reference data for the requirement wizard dialog. Owners are scoped to the
     * staff the viewer may assign, matching resolveOwner() on store/update.
     *
     * @return array<string, mixed>
     */
    private function obligationFormOptions(Request $request): array
    {
        $owners = User::query()->orderBy('name')->limit(200);
        $this->siteAccess->applyStaffScope($owners, $request->user(), self::STAFF_SITE_BYPASS_PERMISSIONS);

        return [
            'frameworks' => ComplianceObligation::frameworkSelectOptions(),
            'owners' => $owners->get(['id', 'name']),
        ];
    }
}
