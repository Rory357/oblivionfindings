<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class HazardousSubstanceController extends Controller
{
    /**
     * List hazardous substances with search and filter.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $tenantId = $user->tenant_id;
        $filters = $request->only(['search', 'status', 'physical_form', 'is_controlled', 'site_id']);

        $query = \DB::table('hs_hazardous_substances')
            ->where('tenant_id', $tenantId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('hsno_classification', 'like', "%{$search}%")
                  ->orWhere('common_name', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['physical_form'])) {
            $query->where('physical_form', $filters['physical_form']);
        }

        if (isset($filters['is_controlled']) && $filters['is_controlled'] !== '') {
            $query->where('is_controlled', (bool) $filters['is_controlled']);
        }

        $substances = $query->orderBy('name')->paginate(25)->withQueryString();

        // Stats
        $totalActive = \DB::table('hs_hazardous_substances')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->count();

        $controlledCount = \DB::table('hs_hazardous_substances')
            ->where('tenant_id', $tenantId)
            ->where('is_controlled', true)
            ->where('status', 'active')
            ->count();

        $expiredSds = \DB::table('hs_substance_sds')
            ->join('hs_hazardous_substances', 'hs_substance_sds.substance_id', '=', 'hs_hazardous_substances.id')
            ->where('hs_hazardous_substances.tenant_id', $tenantId)
            ->where('hs_substance_sds.expiry_date', '<', now())
            ->where('hs_substance_sds.is_current', true)
            ->count();

        $storageLocations = \DB::table('hs_substance_storage_locations')
            ->join('hs_hazardous_substances', 'hs_substance_storage_locations.substance_id', '=', 'hs_hazardous_substances.id')
            ->where('hs_hazardous_substances.tenant_id', $tenantId)
            ->count();

        return Inertia::render('health-safety/substances/index', [
            'substances' => $substances,
            'stats' => [
                'total_active' => $totalActive,
                'controlled_substances' => $controlledCount,
                'expired_sds' => $expiredSds,
                'storage_locations' => $storageLocations,
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Show the create form.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $sites = \DB::table('sites')
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/substances/create', [
            'sites' => $sites,
        ]);
    }

    /**
     * Store a new hazardous substance.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'common_name' => ['nullable', 'string', 'max:255'],
            'hsno_classification' => ['nullable', 'string', 'max:255'],
            'hazard_classifications' => ['nullable', 'array'],
            'hazard_classifications.*' => ['string', 'max:100'],
            'physical_form' => ['required', 'string', 'in:solid,liquid,gas,powder,aerosol,other'],
            'is_controlled' => ['boolean'],
            'un_number' => ['nullable', 'string', 'max:50'],
            'cas_number' => ['nullable', 'string', 'max:50'],
            'maximum_quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:50'],
            'emergency_procedures' => ['nullable', 'string', 'max:5000'],
            'ppe_requirements' => ['nullable', 'array'],
            'ppe_requirements.*' => ['string', 'max:100'],
            'first_aid_instructions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['hazard_classifications'])) {
            $validated['hazard_classifications'] = json_encode($validated['hazard_classifications']);
        }
        if (isset($validated['ppe_requirements'])) {
            $validated['ppe_requirements'] = json_encode($validated['ppe_requirements']);
        }

        \DB::table('hs_hazardous_substances')->insert(array_merge($validated, [
            'tenant_id' => $user->tenant_id,
            'status' => 'active',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->route('health-safety.substances.index')
            ->with('success', 'Hazardous substance registered successfully.');
    }

    /**
     * Show a hazardous substance with SDS, storage locations, and exposure records.
     */
    public function show(Request $request, int $substance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.view'), 403);

        $record = \DB::table('hs_hazardous_substances')
            ->where('id', $substance)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $sdsDocuments = \DB::table('hs_substance_sds')
            ->where('substance_id', $substance)
            ->orderByDesc('issue_date')
            ->get();

        $storageLocations = \DB::table('hs_substance_storage_locations')
            ->leftJoin('sites', 'hs_substance_storage_locations.site_id', '=', 'sites.id')
            ->where('hs_substance_storage_locations.substance_id', $substance)
            ->select('hs_substance_storage_locations.*', 'sites.name as site_name')
            ->get();

        $exposureRecords = \DB::table('hs_substance_exposure_records')
            ->leftJoin('users', 'hs_substance_exposure_records.user_id', '=', 'users.id')
            ->where('hs_substance_exposure_records.substance_id', $substance)
            ->select('hs_substance_exposure_records.*', 'users.name as user_name')
            ->orderByDesc('hs_substance_exposure_records.exposed_at')
            ->get();

        $sites = \DB::table('sites')
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staff = \DB::table('users')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-safety/substances/show', [
            'substance' => $record,
            'sdsDocuments' => $sdsDocuments,
            'storageLocations' => $storageLocations,
            'exposureRecords' => $exposureRecords,
            'sites' => $sites,
            'staff' => $staff,
        ]);
    }

    /**
     * Update a hazardous substance.
     */
    public function update(Request $request, int $substance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_hazardous_substances')
            ->where('id', $substance)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'common_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hsno_classification' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hazard_classifications' => ['sometimes', 'nullable', 'array'],
            'hazard_classifications.*' => ['string', 'max:100'],
            'physical_form' => ['sometimes', 'string', 'in:solid,liquid,gas,powder,aerosol,other'],
            'is_controlled' => ['sometimes', 'boolean'],
            'un_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'cas_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'maximum_quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_procedures' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'ppe_requirements' => ['sometimes', 'nullable', 'array'],
            'ppe_requirements.*' => ['string', 'max:100'],
            'first_aid_instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'string', 'in:active,inactive,removed'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['hazard_classifications'])) {
            $validated['hazard_classifications'] = json_encode($validated['hazard_classifications']);
        }
        if (isset($validated['ppe_requirements'])) {
            $validated['ppe_requirements'] = json_encode($validated['ppe_requirements']);
        }

        \DB::table('hs_hazardous_substances')
            ->where('id', $substance)
            ->update(array_merge($validated, [
                'updated_at' => now(),
            ]));

        return redirect()->back()->with('success', 'Substance updated successfully.');
    }

    /**
     * Upload a Safety Data Sheet (SDS) for a substance.
     */
    public function storeSds(Request $request, int $substance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_hazardous_substances')
            ->where('id', $substance)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:50'],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after:issue_date'],
            'document' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Mark previous SDS as not current
        \DB::table('hs_substance_sds')
            ->where('substance_id', $substance)
            ->where('is_current', true)
            ->update(['is_current' => false, 'updated_at' => now()]);

        $path = $request->file('document')->store(
            "health-safety/sds/{$substance}",
            'private'
        );

        \DB::table('hs_substance_sds')->insert([
            'substance_id' => $substance,
            'version' => $validated['version'],
            'issue_date' => $validated['issue_date'],
            'expiry_date' => $validated['expiry_date'] ?? null,
            'file_path' => $path,
            'file_name' => $request->file('document')->getClientOriginalName(),
            'is_current' => true,
            'notes' => $validated['notes'] ?? null,
            'uploaded_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Safety Data Sheet uploaded successfully.');
    }

    /**
     * Add a storage location for a substance.
     */
    public function storeStorageLocation(Request $request, int $substance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_hazardous_substances')
            ->where('id', $substance)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'site_id' => ['required', 'exists:sites,id'],
            'location_description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'quantity_unit' => ['required', 'string', 'max:50'],
            'storage_requirements' => ['nullable', 'string', 'max:2000'],
            'signage_in_place' => ['boolean'],
        ]);

        \DB::table('hs_substance_storage_locations')->insert(array_merge($validated, [
            'substance_id' => $substance,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Storage location added successfully.');
    }

    /**
     * Record an exposure incident for a substance.
     */
    public function storeExposureRecord(Request $request, int $substance)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('health-safety.manage'), 403);

        $record = \DB::table('hs_hazardous_substances')
            ->where('id', $substance)
            ->where('tenant_id', $user->tenant_id)
            ->firstOrFail();

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'exposed_at' => ['required', 'date'],
            'exposure_type' => ['required', 'string', 'in:inhalation,skin_contact,eye_contact,ingestion,injection,other'],
            'circumstances' => ['required', 'string', 'max:5000'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'symptoms' => ['nullable', 'string', 'max:2000'],
            'treatment_provided' => ['nullable', 'string', 'max:2000'],
            'medical_attention_required' => ['boolean'],
            'reported_to_worksafe' => ['boolean'],
        ]);

        \DB::table('hs_substance_exposure_records')->insert(array_merge($validated, [
            'substance_id' => $substance,
            'recorded_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return redirect()->back()->with('success', 'Exposure record added successfully.');
    }
}
