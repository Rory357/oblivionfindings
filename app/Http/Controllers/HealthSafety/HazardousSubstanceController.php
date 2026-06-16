<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardousSubstanceController extends Controller
{
    /**
     * List hazardous substances with search and filter.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['q', 'status', 'physical_form', 'is_controlled', 'site_id']);

        $query = HazardousSubstance::withCount(['safetyDataSheets as sds_count', 'storageLocations as storage_locations_count'])
            ->when(!empty($filters['q']), function ($q) use ($filters) {
                $search = $filters['q'];
                $q->where(function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                       ->orWhere('hsno_classification', 'like', "%{$search}%")
                       ->orWhere('common_name', 'like', "%{$search}%");
                });
            })
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['physical_form']), fn ($q) => $q->where('physical_form', $filters['physical_form']))
            ->when(isset($filters['is_controlled']) && $filters['is_controlled'] !== '', function ($q) use ($filters) {
                $q->where('is_controlled_substance', $this->parseControlledFilter($filters['is_controlled']));
            });

        $substances = $query->orderBy('name')->paginate(25)->withQueryString();

        // Stats
        $totalSubstances = HazardousSubstance::where('status', 'active')->count();
        $controlledCount = HazardousSubstance::where('is_controlled_substance', true)->where('status', 'active')->count();
        $activeSds = SafetyDataSheet::where('status', 'current')->count();
        $storageLocations = SubstanceStorageLocation::count();

        return Inertia::render('health-safety/substances/index', [
            'substances' => $substances,
            'stats' => [
                'total_substances' => $totalSubstances,
                'controlled_substances' => $controlledCount,
                'active_sds' => $activeSds,
                'storage_locations' => $storageLocations,
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(): \Inertia\Response
    {
        return Inertia::render('health-safety/substances/create', [
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a new hazardous substance.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'common_name' => ['nullable', 'string', 'max:255'],
            'hsno_classification' => ['nullable', 'string', 'max:255'],
            'hazard_classifications' => ['nullable', 'array'],
            'hazard_classifications.*' => ['string', 'max:100'],
            'physical_form' => ['required', 'string', 'in:solid,liquid,gas,powder,aerosol,paste,other'],
            'is_controlled_substance' => ['boolean'],
            'un_number' => ['nullable', 'string', 'max:50'],
            'ppe_required' => ['nullable', 'string', 'max:5000'],
            'first_aid_measures' => ['nullable', 'string', 'max:5000'],
            'spill_procedures' => ['nullable', 'string', 'max:5000'],
            'storage_requirements' => ['nullable', 'string', 'max:5000'],
            'handling_precautions' => ['nullable', 'string', 'max:5000'],
        ]);

        HazardousSubstance::create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        if ($request->boolean('stay')) {
            return back()->with('success', 'Hazardous substance registered successfully.');
        }

        return redirect()->route('health-safety.substances.index')
            ->with('success', 'Hazardous substance registered successfully.');
    }

    /**
     * Show a hazardous substance with SDS, storage locations, and exposure records.
     */
    public function show(Request $request, HazardousSubstance $substance): \Inertia\Response
    {
        $substance->load(['creator:id,name']);

        $sdsRecords = $substance->safetyDataSheets()
            ->with('uploader:id,name')
            ->orderByDesc('issue_date')
            ->get()
            ->map(fn (SafetyDataSheet $sheet) => [
                'id' => $sheet->id,
                'version' => $sheet->version,
                'issue_date' => optional($sheet->issue_date)->toDateString(),
                'supplier' => $sheet->supplier_name,
                'status' => $sheet->status,
                'file_name' => $sheet->document_path ? basename($sheet->document_path) : null,
            ])
            ->values();

        $storageLocations = $substance->storageLocations()
            ->with('site:id,name')
            ->get()
            ->map(fn (SubstanceStorageLocation $location) => [
                'id' => $location->id,
                'site' => $location->site ? [
                    'id' => $location->site->id,
                    'name' => $location->site->name,
                ] : null,
                'location_description' => $location->location_description,
                'current_quantity' => $location->current_quantity,
                'max_quantity' => $location->maximum_quantity,
                'is_labelled' => $location->properly_labelled,
                'segregation_compliant' => $location->segregation_compliant,
            ])
            ->values();

        $exposureRecords = $substance->exposureRecords()
            ->with('user:id,name')
            ->orderByDesc('exposed_at')
            ->get()
            ->map(fn (SubstanceExposureRecord $record) => [
                'id' => $record->id,
                'user' => $record->user ? [
                    'id' => $record->user->id,
                    'name' => $record->user->name,
                ] : null,
                'exposure_date' => optional($record->exposed_at)->toDateString(),
                'exposure_type' => $record->exposure_type,
                'symptoms' => $record->symptoms,
                'medical_attention' => $record->medical_attention_sought,
            ])
            ->values();

        $substanceData = $substance->toArray();
        $substanceData['sds_records'] = $sdsRecords;
        $substanceData['storage_locations'] = $storageLocations;
        $substanceData['exposure_records'] = $exposureRecords;
        $substanceData['can_manage_entries'] = $this->canManageEntries($request);

        return Inertia::render('health-safety/substances/show', [
            'substance' => $substanceData,
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update a hazardous substance.
     */
    public function update(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'common_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hsno_classification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hazard_classifications' => ['sometimes', 'nullable', 'array'],
            'hazard_classifications.*' => ['string', 'max:100'],
            'physical_form' => ['sometimes', 'string', 'in:solid,liquid,gas,powder,aerosol,paste,other'],
            'is_controlled_substance' => ['sometimes', 'boolean'],
            'un_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ppe_required' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'first_aid_measures' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'spill_procedures' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'storage_requirements' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'handling_precautions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:active,inactive,removed'],
        ]);

        $substance->update($validated);

        return redirect()->back()->with('success', 'Substance updated successfully.');
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
            'document' => ['required_without:file', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'file' => ['required_without:document', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
        ]);

        $document = $request->file('document') ?? $request->file('file');
        $supplierName = $validated['supplier_name'] ?? $validated['supplier'] ?? null;

        // Mark previous SDS as not current
        $substance->safetyDataSheets()
            ->where('status', 'current')
            ->update(['status' => 'superseded']);

        $path = $document->store(
            "health-safety/sds/{$substance->id}",
            'private'
        );

        $substance->safetyDataSheets()->create([
            'version' => $validated['version'],
            'issue_date' => $validated['issue_date'],
            'review_date' => $validated['review_date'] ?? null,
            'document_path' => $path,
            'supplier_name' => $supplierName,
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
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:50'],
            'maximum_quantity' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'numeric', 'min:0'],
            'storage_notes' => ['nullable', 'string', 'max:2000'],
            'storage_requirements' => ['nullable', 'string', 'max:2000'],
            'properly_labelled' => ['boolean'],
            'is_labelled' => ['boolean'],
            'segregation_compliant' => ['boolean'],
        ]);

        $currentQuantity = $validated['current_quantity'] ?? $validated['quantity'] ?? null;
        $maximumQuantity = $validated['maximum_quantity'] ?? $validated['max_quantity'] ?? null;

        $substance->storageLocations()->create([
            'site_id' => $validated['site_id'],
            'location_description' => $validated['location_description'],
            'current_quantity' => $currentQuantity,
            'quantity_unit' => $validated['quantity_unit'] ?? null,
            'maximum_quantity' => $maximumQuantity,
            'properly_labelled' => $validated['properly_labelled'] ?? $validated['is_labelled'] ?? true,
            'segregation_compliant' => $validated['segregation_compliant'] ?? true,
            'storage_notes' => $validated['storage_notes'] ?? $validated['storage_requirements'] ?? null,
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
            'exposure_date' => ['required_without:exposed_at', 'date'],
            'exposed_at' => ['required_without:exposure_date', 'date'],
            'exposure_type' => ['required', 'string', 'in:inhalation,skin_contact,eye_contact,ingestion,injection,other'],
            'circumstances' => ['nullable', 'string', 'max:5000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'exposure_duration' => ['nullable', 'string', 'max:255'],
            'symptoms' => ['nullable', 'string', 'max:2000'],
            'first_aid_given' => ['nullable', 'string', 'max:2000'],
            'treatment_provided' => ['nullable', 'string', 'max:2000'],
            'medical_attention_required' => ['boolean'],
            'medical_attention' => ['boolean'],
            'medical_attention_sought' => ['boolean'],
            'reported_to_worksafe' => ['boolean'],
            'incident_reported' => ['boolean'],
        ]);

        $exposedAt = $validated['exposed_at'] ?? Carbon::parse($validated['exposure_date'])->toDateTimeString();
        $exposureDuration = $validated['exposure_duration'] ?? (
            isset($validated['duration_minutes']) ? $validated['duration_minutes'] . ' minutes' : null
        );
        $medicalAttentionSought = $validated['medical_attention_sought']
            ?? $validated['medical_attention_required']
            ?? $validated['medical_attention']
            ?? false;
        $incidentReported = $validated['incident_reported'] ?? $validated['reported_to_worksafe'] ?? false;
        $firstAidGiven = $validated['first_aid_given'] ?? $validated['treatment_provided'] ?? null;

        $substance->exposureRecords()->create([
            'user_id' => $validated['user_id'],
            'site_id' => $validated['site_id'] ?? null,
            'exposed_at' => $exposedAt,
            'exposure_type' => $validated['exposure_type'],
            'exposure_duration' => $exposureDuration,
            'circumstances' => $validated['circumstances'] ?? null,
            'symptoms' => $validated['symptoms'] ?? null,
            'first_aid_given' => $firstAidGiven,
            'medical_attention_sought' => $medicalAttentionSought,
            'incident_reported' => $incidentReported,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Exposure record added successfully.');
    }

    private function canManageEntries(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('hazards.manage') || $user?->canDo('hazards.create'));
    }

    private function parseControlledFilter(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }
}
