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
use Inertia\Inertia;

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
            ->when(isset($filters['is_controlled']) && $filters['is_controlled'] !== '', fn ($q) => $q->where('is_controlled_substance', (bool) $filters['is_controlled']));

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
            'physical_form' => ['required', 'string', 'in:solid,liquid,gas,powder,aerosol,other'],
            'is_controlled_substance' => ['boolean'],
            'un_number' => ['nullable', 'string', 'max:50'],
            'ppe_required' => ['nullable', 'array'],
            'ppe_required.*' => ['string', 'max:100'],
            'first_aid_measures' => ['nullable', 'string', 'max:5000'],
            'spill_procedures' => ['nullable', 'string', 'max:5000'],
            'storage_requirements' => ['nullable', 'string', 'max:5000'],
            'handling_precautions' => ['nullable', 'string', 'max:5000'],
        ]);

        HazardousSubstance::create(array_merge($validated, [
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('health-safety.substances.index')
            ->with('success', 'Hazardous substance registered successfully.');
    }

    /**
     * Show a hazardous substance with SDS, storage locations, and exposure records.
     */
    public function show(HazardousSubstance $substance): \Inertia\Response
    {
        $substance->load(['creator:id,name']);

        $sdsRecords = $substance->safetyDataSheets()
            ->with('uploader:id,name')
            ->orderByDesc('issue_date')
            ->get();

        $storageLocations = $substance->storageLocations()
            ->with('site:id,name')
            ->get();

        $exposureRecords = $substance->exposureRecords()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();

        $substanceData = $substance->toArray();
        $substanceData['sds_records'] = $sdsRecords;
        $substanceData['storage_locations'] = $storageLocations;
        $substanceData['exposure_records'] = $exposureRecords;

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
            'physical_form' => ['sometimes', 'string', 'in:solid,liquid,gas,powder,aerosol,other'],
            'is_controlled_substance' => ['sometimes', 'boolean'],
            'un_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'ppe_required' => ['sometimes', 'nullable', 'array'],
            'ppe_required.*' => ['string', 'max:100'],
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
            'document' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Mark previous SDS as not current
        $substance->safetyDataSheets()
            ->where('status', 'current')
            ->update(['status' => 'superseded']);

        $path = $request->file('document')->store(
            "health-safety/sds/{$substance->id}",
            'private'
        );

        $substance->safetyDataSheets()->create([
            'version' => $validated['version'],
            'issue_date' => $validated['issue_date'],
            'review_date' => $validated['review_date'] ?? null,
            'document_path' => $path,
            'supplier_name' => $validated['supplier_name'] ?? null,
            'status' => 'current',
            'uploaded_by' => $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Safety Data Sheet uploaded successfully.');
    }

    /**
     * Add a storage location for a substance.
     */
    public function storeStorageLocation(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'location_description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'quantity_unit' => ['required', 'string', 'max:50'],
            'storage_requirements' => ['nullable', 'string', 'max:2000'],
            'signage_in_place' => ['boolean'],
        ]);

        $substance->storageLocations()->create(array_merge($validated, [
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Storage location added successfully.');
    }

    /**
     * Record an exposure incident for a substance.
     */
    public function storeExposureRecord(Request $request, HazardousSubstance $substance): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'exposure_type' => ['required', 'string', 'in:inhalation,skin_contact,eye_contact,ingestion,injection,other'],
            'circumstances' => ['required', 'string', 'max:5000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'symptoms' => ['nullable', 'string', 'max:2000'],
            'treatment_provided' => ['nullable', 'string', 'max:2000'],
            'medical_attention_required' => ['boolean'],
            'reported_to_worksafe' => ['boolean'],
        ]);

        $substance->exposureRecords()->create(array_merge($validated, [
            'created_by' => $request->user()->id,
        ]));

        return redirect()->back()->with('success', 'Exposure record added successfully.');
    }
}
