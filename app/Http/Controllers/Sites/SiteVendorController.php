<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteVendor;
use Illuminate\Http\Request;

class SiteVendorController extends Controller
{
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
        $this->authorize('update', $site);

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
            'is_active' => true,
        ]);

        return redirect()
            ->route('sites.vendors.index', $site)
            ->with('success', 'Vendor added successfully.');
    }

    public function update(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->authorize('update', $site);

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
        $this->authorize('update', $site);

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
}
