<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteVendor;
use Illuminate\Http\Request;

class SiteVendorController extends Controller
{
    use ResolvesAllowedSiteTypes;
    public function globalIndex(Request $request)
    {
        $user = $request->user();
        $canVendors = (bool) ($user?->canDo('vendors.view') ?? false);
        $canCredentials = (bool) ($user?->canDo('credentials.view') ?? false);

        abort_unless($canVendors || $canCredentials, 403);

        $allowedSiteTypes = $this->allowedSiteTypes($request);

        // Vendor data — only loaded and serialised when the user can see it.
        $vendors = $canVendors
            ? SiteVendor::query()
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
                    'account_number' => $vendor->account_number,
                    'notes' => $vendor->notes,
                    'preferred_contact_method' => $vendor->preferred_contact_method,
                    'is_preferred' => (bool) $vendor->is_preferred,
                    'is_active' => (bool) $vendor->is_active,
                ])
                ->values()
            : collect();

        // Credential data — same gate. Credentials carry no plaintext value
        // here (the list view never includes the secret), but the metadata
        // itself is still restricted to credentials.view holders.
        $credentials = $canCredentials
            ? SiteCredential::query()
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
                    'username' => $credential->username,
                    'url' => $credential->url,
                    'notes' => $credential->notes,
                    'vendor_id' => $credential->vendor_id,
                    'vendor_name' => $credential->vendor?->company_name,
                    'vendor_service_type' => $credential->vendor?->service_type,
                    'requires_reauth' => (bool) $credential->requires_reauth,
                    'is_shareable' => (bool) $credential->is_shareable,
                    'password_strength' => $credential->password_strength,
                    'has_totp' => $credential->hasTotp(),
                    'last_rotated_at' => $credential->last_rotated_at?->toDateTimeString(),
                    'value_preview' => '********',
                ])
                ->values()
            : collect();

        $sites = Site::query()
            ->active()
            ->whereIn('type', $allowedSiteTypes)
            ->select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        $serviceTypes = $canVendors
            ? SiteVendor::query()
                ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
                ->select('service_type')
                ->distinct()
                ->orderBy('service_type')
                ->pluck('service_type')
                ->values()
            : collect();

        $credentialTypes = $canCredentials
            ? SiteCredential::query()
                ->whereHas('site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
                ->select('credential_type')
                ->distinct()
                ->orderBy('credential_type')
                ->pluck('credential_type')
                ->values()
            : collect();

        return inertia('sites/vendors-credentials/global', [
            'vendors' => $vendors,
            'credentials' => $credentials,
            'sites' => $sites,
            'serviceTypes' => $serviceTypes,
            'credentialTypes' => $credentialTypes,
            'filters' => $request->only(['site_id', 'service_type', 'vendor_status', 'preferred', 'credential_type', 'requires_reauth']),
            'can' => [
                'vendors' => $canVendors,
                'credentials' => $canCredentials,
                'vendorsManage' => (bool) ($user?->canDo('vendors.manage') ?? false),
                'credentialsManage' => (bool) ($user?->canDo('credentials.manage') ?? false),
                'credentialsReveal' => (bool) ($user?->canDo('credentials.reveal') ?? false),
            ],
        ]);
    }

    /**
     * Toggle a vendor's preferred / active flags from the global directory
     * (the right-click quick actions). Reuses the vendors.manage gate.
     */
    public function toggleVendorFlags(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('vendors.manage') || abort(403);
        abort_unless($vendor->site_id === $site->id, 404);

        $validated = $request->validate([
            'is_preferred' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validated === []) {
            return back(303);
        }

        $vendor->update($validated);

        return back(303)->with('success', 'Vendor updated.');
    }

    /**
     * Cross-site reveal & audit log feed for the Vendors & Credentials page.
     * Scoped to the credentials the viewer is allowed to see; gated on
     * credentials.view so vendor-only viewers never reach the trail.
     */
    public function globalAudit(Request $request)
    {
        $user = $request->user();
        abort_unless((bool) ($user?->canDo('credentials.view') ?? false), 403);

        $allowedSiteTypes = $this->allowedSiteTypes($request);

        $logs = SiteCredentialAuditLog::query()
            ->with(['user:id,name', 'credential:id,label,credential_type,site_id', 'credential.site:id,name,type'])
            ->whereHas('credential.site', fn ($q) => $q->whereIn('type', $allowedSiteTypes))
            ->when($request->site_id, fn ($q) => $q->whereHas('credential', fn ($c) => $c->where('site_id', (int) $request->site_id)))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(function (SiteCredentialAuditLog $log) {
                $name = $log->user?->name ?? 'Unknown user';
                $initials = collect(preg_split('/\s+/', trim($name)))
                    ->filter()
                    ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                    ->take(2)
                    ->implode('');

                return [
                    'id' => $log->id,
                    'at' => $log->created_at?->toIso8601String(),
                    'action' => $log->action,
                    'actor' => [
                        'name' => $name,
                        'initials' => $initials !== '' ? $initials : '?',
                    ],
                    'target' => $log->credential?->label ?? 'Deleted credential',
                    'target_type' => $log->credential?->credential_type ?? 'credential',
                    'site_name' => $log->credential?->site?->name ?? '—',
                    'ip' => $log->ip_address ?? '—',
                    'result' => in_array($log->action, ['reauth_failed', 'denied'], true) ? 'denied' : 'ok',
                ];
            })
            ->values();

        return response()->json(['logs' => $logs]);
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
            'canManage' => $request->user()->canDo('vendors.manage'),
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

        return back(303)->with('success', 'Vendor added successfully.');
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

        return back(303)->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->authorize('view', $site);
        $request->user()->canDo('vendors.manage') || abort(403);
        abort_unless($vendor->site_id === $site->id, 404);

        // Check if vendor has credentials
        if ($vendor->credentials()->count() > 0) {
            return back(303)->with(
                'error',
                'Cannot delete vendor with associated credentials. Please delete credentials first.',
            );
        }

        $vendor->delete();

        return back(303)->with('success', 'Vendor deleted successfully.');
    }

}
