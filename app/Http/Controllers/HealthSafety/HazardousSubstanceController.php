<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardousSubstanceController extends Controller
{
    /**
     * Chemical register (redesign): hs-hero-kit hero with chemical-register stat
     * clusters, a 6-tab TabStrip (All · Active · Controlled · SDS expiring ·
     * SDS missing · Inactive), Site/Form/Status/SDS filters and right-click rows.
     * The substance detail + add/edit wizard are modal-over-list.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $q = trim((string) $request->get('q', ''));
        $tab = $request->get('tab', 'all'); // all|active|controlled|sds_expiring|sds_missing|inactive
        $siteId = $request->get('site_id') ? (int) $request->get('site_id') : null;
        $physicalForm = $request->get('physical_form');
        $statusFilter = $request->get('status');
        $controlled = $this->boolFilter($request->get('controlled'));
        $sdsState = $request->get('sds_state'); // current|expiring|missing
        $period = max(7, min(365, (int) ($request->get('period') ?: 90)));

        // Shared filters (everything EXCEPT the tab) so the tab counts stay
        // mutually consistent with the active list.
        $applyFilters = function ($query) use ($q, $siteId, $physicalForm, $statusFilter, $controlled, $sdsState) {
            return $query
                ->when($q !== '', function ($query) use ($q) {
                    $term = '%'.$q.'%';
                    $query->where(fn ($sub) => $sub->where('name', 'like', $term)
                        ->orWhere('common_name', 'like', $term)
                        ->orWhere('hsno_classification', 'like', $term)
                        ->orWhere('un_number', 'like', $term));
                })
                ->when($siteId, fn ($query) => $query->whereHas('storageLocations', fn ($s) => $s->where('site_id', $siteId)))
                ->when($physicalForm, fn ($query) => $query->where('physical_form', $physicalForm))
                ->when($statusFilter, fn ($query) => $query->where('status', $statusFilter))
                ->when($controlled !== null, fn ($query) => $query->where('is_controlled_substance', $controlled))
                ->when($sdsState === 'expiring', fn ($query) => $query->sdsExpiring())
                ->when($sdsState === 'missing', fn ($query) => $query->sdsMissing())
                ->when($sdsState === 'current', fn ($query) => $query->sdsCurrent());
        };

        $applyTab = fn ($query, string $t) => match ($t) {
            'active' => $query->where('status', 'active'),
            'controlled' => $query->where('is_controlled_substance', true)->where('status', '!=', 'removed'),
            // Both SDS worklists scope to active substances so they stay symmetric and
            // align with the hero's active-based partition (active = current+expiring+missing).
            'sds_expiring' => $query->where('status', 'active')->sdsExpiring(),
            'sds_missing' => $query->where('status', 'active')->sdsMissing(),
            'inactive' => $query->whereIn('status', ['inactive', 'removed']),
            default => $query->where('status', '!=', 'removed'), // all = live register
        };

        $countFor = fn (string $t) => $applyTab($applyFilters(HazardousSubstance::query()), $t)->count();
        $tabCounts = [
            'all' => $countFor('all'),
            'active' => $countFor('active'),
            'controlled' => $countFor('controlled'),
            'sds_expiring' => $countFor('sds_expiring'),
            'sds_missing' => $countFor('sds_missing'),
            'inactive' => $countFor('inactive'),
        ];

        $canEntries = (bool) ($user?->canDo('hazards.manage') || $user?->canDo('hazards.create'));
        $canManage = (bool) $user?->canDo('hazards.manage');

        $rows = $applyTab($applyFilters(HazardousSubstance::query()), $tab)
            ->with('currentSds')
            ->withCount('storageLocations as storage_count')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (HazardousSubstance $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'common_name' => $s->common_name,
                'hsno_classification' => $s->hsno_classification,
                'hazard_classifications' => $s->hazard_classifications ?? [],
                'ghs_pictograms' => $s->ghs_pictograms ?? [],
                'physical_form' => $s->physical_form,
                'is_controlled_substance' => (bool) $s->is_controlled_substance,
                'requires_tracking' => (bool) $s->requires_tracking,
                'sds_state' => $s->sds_state,
                'storage_count' => $s->storage_count,
                'status' => $s->status,
                'can' => ['create' => $canEntries, 'manage' => $canManage],
            ]);

        // Hero clusters — a register overview scoped by the Site filter only (the
        // tabs/search refine the list below, not the hero). All tiles share one
        // site-scoped base so the partition holds: active = current+expiring+missing,
        // and every tile is internally consistent under a site filter.
        $siteScope = fn ($query) => $query->when($siteId, fn ($q) => $q->whereHas('storageLocations', fn ($s) => $s->where('site_id', $siteId)));
        $activeBase = fn () => $siteScope(HazardousSubstance::query())->where('status', 'active');

        $activeCount = $activeBase()->count();
        $controlledLive = $siteScope(HazardousSubstance::query())->where('is_controlled_substance', true)->where('status', '!=', 'removed')->count();
        $sdsExpiringHero = $activeBase()->sdsExpiring()->count();
        $sdsMissingHero = $activeBase()->sdsMissing()->count();
        $sdsCurrent = max(0, $activeCount - $sdsExpiringHero - $sdsMissingHero);
        $storageCount = SubstanceStorageLocation::query()->when($siteId, fn ($q) => $q->where('site_id', $siteId))->count();

        $periodStart = now()->subDays($period);
        $exposureBase = fn () => SubstanceExposureRecord::query()
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where('exposed_at', '>=', $periodStart);
        $exposuresPeriod = $exposureBase()->count();
        $awaitingReview = $exposureBase()->where('medical_attention_sought', true)->whereNull('medical_outcome')->count();

        $hero = [
            'live' => [
                'active' => $activeCount,
                'controlled' => $controlledLive,
                'sds_current' => $sdsCurrent,
                'storage_locations' => $storageCount,
            ],
            'attention' => [
                'sds_expiring' => $sdsExpiringHero,
                'sds_missing' => $sdsMissingHero,
                'awaiting_review' => $awaitingReview,
                'exposures' => $exposuresPeriod,
            ],
        ];

        // NZ compliance chips (counts/booleans only — the kit formats them). Site-scoped
        // to match the hero when a site is selected.
        $worksafeAwaiting = HsEvent::query()
            ->where('event_category', HsEvent::CATEGORY_EXPOSURE)
            ->where('worksafe_notifiable', true)
            ->when($siteId, fn ($q) => $q->where('site_id', $siteId))
            ->where(fn ($w) => $w->whereNull('worksafe_status')->orWhere('worksafe_status', HsEvent::WORKSAFE_PENDING))
            ->count();

        $badges = [
            'worksafe_awaiting' => $worksafeAwaiting,
            'sds_to_action' => $sdsExpiringHero + $sdsMissingHero,
            'nga_paerewa_certified' => true,
        ];

        return Inertia::render('health-safety/substances/index', [
            'filters' => [
                'q' => $q,
                'tab' => $tab,
                'site_id' => $siteId,
                'physical_form' => $physicalForm,
                'status' => $statusFilter,
                // Echo the normalised tri-state (not the raw value) so the pill stays in
                // sync with the rows even for '0'/garbage inputs.
                'controlled' => $controlled === null ? null : ($controlled ? 'controlled' : 'standard'),
                'sds_state' => $sdsState,
                'period' => $period,
            ],
            'tab' => $tab,
            'tabCounts' => $tabCounts,
            'rows' => $rows,
            'hero' => $hero,
            'badges' => $badges,
            'sites' => Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'create' => $canEntries,
                'manage' => $canManage,
            ],
            // Auto-open the add wizard when arriving from /substances/create (?new=1).
            'openWizard' => $request->boolean('new'),
            // Deep-link a detail action pane (e.g. launcher success → Add SDS), validated.
            'initialAction' => in_array($request->get('action'), ['add_sds', 'add_storage', 'record_exposure', 'deactivate'], true)
                ? $request->get('action')
                : null,
            // Detail-over-list: when ?substance= is present the dialog opens over the
            // register (Inertia partial-reloads only this prop). Null otherwise.
            'detail' => $request->filled('substance')
                ? $this->buildSubstanceDetail($request, (int) $request->get('substance'))
                : null,
        ]);
    }

    /**
     * The add flow is a modal-first wizard living over the register, so
     * /substances/create redirects to the index with ?new=1 (auto-opens it).
     * Keeps the route as a deep-link fallback.
     */
    public function create(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canDo('hazards.create') || $request->user()?->canDo('hazards.manage'), 403);

        return redirect()->route('health-safety.substances.index', ['new' => 1]);
    }

    /**
     * Store a new hazardous substance.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->substanceRules(creating: true));

        $substance = HazardousSubstance::create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        // The add flow is modal-over-register: return to it with the new id so the
        // wizard's success pane can deep-link (Add SDS / Add storage / View substance).
        return back()
            ->with('success', 'Hazardous substance registered successfully.')
            ->with('created_substance_id', $substance->id);
    }

    /**
     * Shared validation contract for create/update. Every field is model-ready
     * (`$fillable`/`$casts`); the wizard collects them across Substance + Controls.
     */
    private function substanceRules(bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'common_name' => ['nullable', 'string', 'max:255'],
            'un_number' => ['nullable', 'string', 'max:50'],
            'hsno_approval' => ['nullable', 'string', 'max:100'],
            'hsno_classification' => ['nullable', 'string', 'max:255'],
            'hazard_classifications' => ['nullable', 'array'],
            'hazard_classifications.*' => ['string', 'max:100'],
            'ghs_pictograms' => ['nullable', 'array'],
            'ghs_pictograms.*' => ['string', 'max:20'],
            'signal_word' => ['nullable', 'string', 'max:32'],
            'hazard_statements' => ['nullable', 'string', 'max:5000'],
            'precautionary_statements' => ['nullable', 'string', 'max:5000'],
            'physical_form' => [$presence, 'string', 'in:solid,liquid,gas,powder,aerosol,paste,other'],
            'is_controlled_substance' => ['boolean'],
            'requires_tracking' => ['boolean'],
            'ppe_required' => ['nullable', 'string', 'max:5000'],
            'storage_requirements' => ['nullable', 'string', 'max:5000'],
            'handling_precautions' => ['nullable', 'string', 'max:5000'],
            'first_aid_measures' => ['nullable', 'string', 'max:5000'],
            'firefighting_measures' => ['nullable', 'string', 'max:5000'],
            'spill_procedures' => ['nullable', 'string', 'max:5000'],
            'exposure_limit_type' => ['nullable', 'string', 'max:50'],
            'exposure_limit_value' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Deep-link fallback for `/substances/{id}` — the detail now lives in a modal
     * over the register, so resolve the substance and open it there (the "Open
     * full page" affordance and any bookmarked URL land on the same record).
     */
    public function show(HazardousSubstance $substance): RedirectResponse
    {
        return redirect()->route('health-safety.substances.index', ['substance' => $substance->id]);
    }

    /**
     * Update a hazardous substance.
     */
    public function update(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate(array_merge($this->substanceRules(creating: false), [
            'status' => ['sometimes', 'string', 'in:active,inactive,removed'],
        ]));

        $substance->update($validated);

        return back()
            ->with('success', 'Substance updated successfully.')
            ->with('created_substance_id', $substance->id);
    }

    /**
     * Lifecycle transition (active / inactive / removed). A reason is required to
     * deactivate or remove; the audit log records who/when, this records why.
     */
    public function updateStatus(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive,removed'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reason = trim((string) ($validated['reason'] ?? ''));

        if (in_array($validated['status'], ['inactive', 'removed'], true) && $reason === '') {
            return back()->with('error', 'A reason is required to mark a substance inactive or removed.');
        }

        $substance->update([
            'status' => $validated['status'],
            'status_reason' => $validated['status'] === 'active' ? null : $reason,
        ]);

        $label = match ($validated['status']) {
            'inactive' => 'marked inactive',
            'removed' => 'removed from the register',
            default => 'reactivated',
        };

        return back()->with('success', "Substance {$label}.");
    }

    /**
     * Upload a Safety Data Sheet (SDS) for a substance.
     */
    public function storeSds(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'issue_date' => ['required', 'date'],
            'review_date' => ['nullable', 'date', 'after:issue_date'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_contact' => ['nullable', 'string', 'max:255'],
        ]);

        // Supersede the prior current SDS so only one stays authoritative.
        $substance->safetyDataSheets()
            ->where('status', 'current')
            ->update(['status' => 'superseded']);

        $path = $request->file('file')->store("health-safety/sds/{$substance->id}", 'private');

        $substance->safetyDataSheets()->create([
            'version' => $validated['version'],
            'issue_date' => $validated['issue_date'],
            'review_date' => $validated['review_date'] ?? null,
            'document_path' => $path,
            'supplier_name' => $validated['supplier_name'] ?? null,
            'supplier_contact' => $validated['supplier_contact'] ?? null,
            'status' => 'current',
            'uploaded_by' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Safety Data Sheet uploaded successfully.');
    }

    /**
     * Download a Safety Data Sheet document.
     */
    public function downloadSds(HazardousSubstance $substance, SafetyDataSheet $sds): StreamedResponse
    {
        abort_unless($sds->hazardous_substance_id === $substance->id, 404);
        abort_unless($sds->document_path && Storage::disk('private')->exists($sds->document_path), 404);

        return Storage::disk('private')->download($sds->document_path, basename($sds->document_path));
    }

    /**
     * Add a storage location for a substance.
     */
    public function storeStorageLocation(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'location_description' => ['required', 'string', 'max:500'],
            'current_quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:50'],
            'maximum_quantity' => ['nullable', 'numeric', 'min:0'],
            'container_type' => ['nullable', 'string', 'max:100'],
            'properly_labelled' => ['boolean'],
            'segregation_compliant' => ['boolean'],
            'last_audit_date' => ['nullable', 'date'],
            'storage_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $substance->storageLocations()->create([
            'site_id' => $validated['site_id'],
            'location_description' => $validated['location_description'],
            'current_quantity' => $validated['current_quantity'] ?? null,
            'quantity_unit' => $validated['quantity_unit'] ?? null,
            'maximum_quantity' => $validated['maximum_quantity'] ?? null,
            'container_type' => $validated['container_type'] ?? null,
            'properly_labelled' => $request->boolean('properly_labelled'),
            'segregation_compliant' => $request->boolean('segregation_compliant'),
            'last_audit_date' => $validated['last_audit_date'] ?? null,
            'storage_notes' => $validated['storage_notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Storage location added successfully.');
    }

    /**
     * Record an exposure incident for a substance.
     */
    public function storeExposureRecord(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['nullable', 'exists:sites,id'],
            'exposed_at' => ['required', 'date'],
            'exposure_type' => ['required', 'string', 'in:inhalation,skin_contact,eye_contact,ingestion,injection,other'],
            'exposure_duration' => ['nullable', 'string', 'max:255'],
            'circumstances' => ['nullable', 'string', 'max:5000'],
            'symptoms' => ['nullable', 'string', 'max:2000'],
            'first_aid_given' => ['nullable', 'string', 'max:2000'],
            'medical_treatment' => ['nullable', 'string', 'in:none,first_aid,medical,hospitalisation,death'],
            'medical_attention_sought' => ['boolean'],
            'medical_outcome' => ['nullable', 'string', 'max:2000'],
            'incident_reported' => ['boolean'],
            'related_incident_id' => ['nullable', 'exists:client_incidents,id'],
        ]);

        $treatment = $validated['medical_treatment'] ?? null;
        // Medical attention is sought for medical-and-above harm; derive it from the
        // structured treatment level when supplied, else honour the explicit flag.
        $medicalSought = $treatment !== null
            ? in_array($treatment, ['medical', 'hospitalisation', 'death'], true)
            : $request->boolean('medical_attention_sought');

        $substance->exposureRecords()->create([
            'user_id' => $validated['user_id'],
            'site_id' => $validated['site_id'] ?? null,
            'exposed_at' => $validated['exposed_at'],
            'exposure_type' => $validated['exposure_type'],
            'exposure_duration' => $validated['exposure_duration'] ?? null,
            'circumstances' => $validated['circumstances'] ?? null,
            'symptoms' => $validated['symptoms'] ?? null,
            'first_aid_given' => $validated['first_aid_given'] ?? null,
            'medical_treatment' => $treatment,
            'medical_attention_sought' => $medicalSought,
            'medical_outcome' => $validated['medical_outcome'] ?? null,
            'incident_reported' => $request->boolean('incident_reported'),
            'related_incident_id' => $validated['related_incident_id'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Exposure record added successfully.');
    }

    /**
     * Full read-only detail payload behind the SubstanceDetailDialog — shared by
     * the modal-over-list (index `?substance=`) and the `/substances/{id}` shell.
     * Returns null when missing or unauthorised.
     */
    private function buildSubstanceDetail(Request $request, int $substanceId): ?array
    {
        $user = $request->user();

        if (! $user?->canDo('hazards.view')) {
            return null;
        }

        $substance = HazardousSubstance::query()
            ->with([
                'creator:id,name',
                'safetyDataSheets' => fn ($q) => $q->with('uploader:id,name')->orderByDesc('issue_date')->orderByDesc('id'),
                'storageLocations' => fn ($q) => $q->with('site:id,name')->orderBy('site_id'),
                'exposureRecords' => fn ($q) => $q->with('user:id,name')->orderByDesc('exposed_at'),
            ])
            ->find($substanceId);

        if (! $substance) {
            return null;
        }

        $canEntries = (bool) ($user->canDo('hazards.manage') || $user->canDo('hazards.create'));
        $canManage = (bool) $user->canDo('hazards.manage');

        $current = $substance->safetyDataSheets->firstWhere('status', 'current');

        return [
            'id' => $substance->id,
            'name' => $substance->name,
            'common_name' => $substance->common_name,
            'un_number' => $substance->un_number,
            'hsno_approval' => $substance->hsno_approval,
            'hsno_classification' => $substance->hsno_classification,
            'hazard_classifications' => $substance->hazard_classifications ?? [],
            'ghs_pictograms' => $substance->ghs_pictograms ?? [],
            'signal_word' => $substance->signal_word,
            'hazard_statements' => $substance->hazard_statements,
            'precautionary_statements' => $substance->precautionary_statements,
            'physical_form' => $substance->physical_form,
            'is_controlled_substance' => (bool) $substance->is_controlled_substance,
            'requires_tracking' => (bool) $substance->requires_tracking,
            'status' => $substance->status,
            'status_reason' => $substance->status_reason,
            'sds_state' => $current?->state ?? 'missing',
            // Controls
            'ppe_required' => $substance->ppe_required,
            'storage_requirements' => $substance->storage_requirements,
            'handling_precautions' => $substance->handling_precautions,
            'first_aid_measures' => $substance->first_aid_measures,
            'firefighting_measures' => $substance->firefighting_measures,
            'spill_procedures' => $substance->spill_procedures,
            'exposure_limit_type' => $substance->exposure_limit_type,
            'exposure_limit_value' => $substance->exposure_limit_value,
            // Children
            'sds_records' => $substance->safetyDataSheets->map(fn (SafetyDataSheet $sheet) => [
                'id' => $sheet->id,
                'version' => $sheet->version,
                'issue_date' => optional($sheet->issue_date)->toDateString(),
                'review_date' => optional($sheet->review_date)->toDateString(),
                'supplier_name' => $sheet->supplier_name,
                'supplier_contact' => $sheet->supplier_contact,
                'status' => $sheet->status,
                'state' => $sheet->state,
                'file_name' => $sheet->document_path ? basename($sheet->document_path) : null,
                'uploaded_by' => $sheet->uploader?->name,
                'created_at' => $sheet->created_at,
                'download_url' => $sheet->document_path
                    ? "/health-safety/substances/{$substance->id}/sds/{$sheet->id}/download"
                    : null,
            ])->values(),
            'storage_locations' => $substance->storageLocations->map(fn (SubstanceStorageLocation $loc) => [
                'id' => $loc->id,
                'site' => $loc->site ? ['id' => $loc->site->id, 'name' => $loc->site->name] : null,
                'location_description' => $loc->location_description,
                'current_quantity' => $loc->current_quantity !== null ? (float) $loc->current_quantity : null,
                'quantity_unit' => $loc->quantity_unit,
                'maximum_quantity' => $loc->maximum_quantity !== null ? (float) $loc->maximum_quantity : null,
                'container_type' => $loc->container_type,
                'properly_labelled' => (bool) $loc->properly_labelled,
                'segregation_compliant' => (bool) $loc->segregation_compliant,
                'last_audit_date' => optional($loc->last_audit_date)->toDateString(),
            ])->values(),
            'exposure_records' => $substance->exposureRecords->map(fn (SubstanceExposureRecord $rec) => [
                'id' => $rec->id,
                'user' => $rec->user ? ['id' => $rec->user->id, 'name' => $rec->user->name] : null,
                'exposed_at' => $rec->exposed_at,
                'exposure_type' => $rec->exposure_type,
                'exposure_duration' => $rec->exposure_duration,
                'circumstances' => $rec->circumstances,
                'symptoms' => $rec->symptoms,
                'first_aid_given' => $rec->first_aid_given,
                'medical_attention_sought' => (bool) $rec->medical_attention_sought,
                'medical_treatment' => $rec->medical_treatment,
                'medical_outcome' => $rec->medical_outcome,
                'incident_reported' => (bool) $rec->incident_reported,
                'related_incident_id' => $rec->related_incident_id,
            ])->values(),
            'counts' => [
                'sds' => $substance->safetyDataSheets->count(),
                'storage' => $substance->storageLocations->count(),
                'exposures' => $substance->exposureRecords->count(),
            ],
            'created_by' => $substance->creator?->name,
            'created_at' => $substance->created_at,
            'updated_at' => $substance->updated_at,
            'can' => ['create' => $canEntries, 'manage' => $canManage],
            // Staff options for the Record-exposure pane.
            'staff' => User::staff()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * Normalise a tri-state controlled/standard filter to true|false|null (any).
     */
    private function boolFilter(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalised = strtolower((string) $value);

        if (in_array($normalised, ['1', 'true', 'yes', 'controlled'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no', 'standard'], true)) {
            return false;
        }

        return null;
    }
}
