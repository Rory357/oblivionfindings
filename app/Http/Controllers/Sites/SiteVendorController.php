<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteVendor;
use Illuminate\Http\Request;

class SiteVendorController extends Controller
{
    public function globalIndex(Request $request)
    {
        abort_unless(
            ($request->user()?->canDo('vendors.view') ?? false) || ($request->user()?->canDo('credentials.view') ?? false),
            403
        );

        $allowedSiteTypes = $this->allowedSiteTypes($request);

        $vendors = SiteVendor::query()
            ->with('site:id,name,type')
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
            ->when($request->service_type, fn ($q) => $q->where('service_type', $request->service_type))
            ->when($request->vendor_status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->vendor_status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->preferred === 'yes', fn ($q) => $q->where('is_preferred', true))
            ->orderBy('service_type')
            ->orderBy('company_name')
            ->limit(1000)
            ->get()
            ->map(fn (SiteVendor $vendor) => [
                'id' => $vendor->id,
                'site_id' => $vendor->site_id,
                'site_name' => $vendor->site?->name,
                'site_type' => $vendor->site?->type,
                'service_type' => $vendor->service_type,
                'company_name' => $vendor->company_name,
                'contact_name' => $vendor->contact_name,
                'phone' => $vendor->phone,
                'after_hours_phone' => $vendor->after_hours_phone,
                'email' => $vendor->email,
                'preferred_contact_method' => $vendor->preferred_contact_method,
                'is_preferred' => (bool) $vendor->is_preferred,
                'is_active' => (bool) $vendor->is_active,
            ])
            ->values();

        $credentials = SiteCredential::query()
            ->with(['site:id,name,type', 'vendor:id,company_name,service_type'])
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
            ->when($request->credential_type, fn ($q) => $q->where('credential_type', $request->credential_type))
            ->when($request->requires_reauth === 'yes', fn ($q) => $q->where('requires_reauth', true))
            ->when($request->requires_reauth === 'no', fn ($q) => $q->where('requires_reauth', false))
            ->orderBy('label')
            ->limit(1000)
            ->get()
            ->map(fn (SiteCredential $credential) => [
                'id' => $credential->id,
                'site_id' => $credential->site_id,
                'site_name' => $credential->site?->name,
                'site_type' => $credential->site?->type,
                'label' => $credential->label,
                'credential_type' => $credential->credential_type,
                'vendor_name' => $credential->vendor?->company_name,
                'vendor_service_type' => $credential->vendor?->service_type,
                'requires_reauth' => (bool) $credential->requires_reauth,
                'last_rotated_at' => $credential->last_rotated_at?->toDateTimeString(),
                'value_preview' => '********',
            ])
            ->values();

        $sites = Site::query()
            ->active()
            ->whereIn('type', $allowedSiteTypes)
            ->select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        $serviceTypes = SiteVendor::query()
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->select('service_type')
            ->distinct()
            ->orderBy('service_type')
            ->pluck('service_type')
            ->values();

        $credentialTypes = SiteCredential::query()
            ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->select('credential_type')
            ->distinct()
            ->orderBy('credential_type')
            ->pluck('credential_type')
            ->values();

        return inertia('sites/vendors-credentials/global', [
            'vendors' => $vendors,
            'credentials' => $credentials,
            'sites' => $sites,
            'serviceTypes' => $serviceTypes,
            'credentialTypes' => $credentialTypes,
            'filters' => $request->only(['site_id', 'service_type', 'vendor_status', 'preferred', 'credential_type', 'requires_reauth']),
        ]);
    }

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $vendors = SiteVendor::where('site_id', $site->id)
            ->when($request->service_type, fn($q) => $q->where('service_type', $request->service_type))
            ->when($request->status === 'active', fn($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn($q) => $q->where('is_active', false))
            ->orderBy('service_type')
            ->orderBy('company_name')
            ->get();

        $serviceTypes = SiteVendor::where('site_id', $site->id)
            ->distinct()
            ->pluck('service_type')
            ->values();

        return inertia('sites/vendors/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'vendors' => $vendors,
            'serviceTypes' => $serviceTypes,
            'filters' => $request->only(['service_type', 'status']),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('vendors.manage') || abort(403);

        $validated = $request->validate([
            'service_type' => 'required|string|max:50',
            'company_name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'after_hours_phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'account_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'preferred_contact_method' => 'required|in:phone,after_hours,email',
            'is_preferred' => 'boolean',
        ]);

        $vendor = SiteVendor::create([
            ...$validated,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'is_active' => true,
        ]);

        return redirect()
            ->route('sites.vendors.index', $site)
            ->with('success', 'Vendor added successfully.');
    }

    public function update(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('vendors.manage') || abort(403);
        abort_unless($vendor->site_id === $site->id, 404);

        $validated = $request->validate([
            'service_type' => 'required|string|max:50',
            'company_name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'after_hours_phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'account_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'preferred_contact_method' => 'required|in:phone,after_hours,email',
            'is_preferred' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $vendor->update($validated);

        return redirect()
            ->route('sites.vendors.index', $site)
            ->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('vendors.manage') || abort(403);
        abort_unless($vendor->site_id === $site->id, 404);

        // Check if vendor has credentials
        if ($vendor->credentials()->count() > 0) {
            return redirect()
                ->route('sites.vendors.index', $site)
                ->with('error', 'Cannot delete vendor with associated credentials. Please delete credentials first.');
        }

        $vendor->delete();

        return redirect()
            ->route('sites.vendors.index', $site)
            ->with('success', 'Vendor deleted successfully.');
    }

    private function allowedSiteTypes(Request $request): array
    {
        $user = $request->user();
        $map = [
            'head_office' => 'sites.type.head_office.view',
            'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view',
            'residential' => 'sites.type.house.view',
        ];

        $allowed = collect($map)
            ->filter(fn (string $permission) => $user?->canDo($permission))
            ->keys()
            ->values()
            ->all();

        return $allowed !== [] ? $allowed : array_keys($map);
    }
}
