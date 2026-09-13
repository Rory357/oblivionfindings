<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\Concerns\ResolvesAllowedSiteTypes;
use App\Models\CredentialType;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteVendor;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteVendorController extends Controller
{
    use ResolvesAllowedSiteTypes;

    public function globalIndex(Request $request, UserSiteAccessService $siteAccess)
    {
        $user = $request->user();
        $canVendors = (bool) ($user?->canDo('vendors.view') ?? false);
        $vault = app(\App\Services\Sites\SiteCredentialAccess::class);
        $canCredentials = $user->canDo('credentials.view') || $vault->query($user)->exists();
        $commercial = app(\App\Services\Sites\VendorCommercialAccess::class)->capable($user);
        if (! $canVendors && ! $canCredentials && $commercial) return redirect('/vendors/renewals');

        abort_unless($canVendors || $canCredentials, 403);

        // Site-scoped writes (reveal/edit/delete/rotate/…) additionally require
        // sites.viewAny via SitePolicy::view.
        $canSiteWrite = (bool) ($user?->canDo('sites.viewAny') ?? false);

        $allowedSiteTypes = $this->allowedSiteTypes($request);
        // Match SitePolicy's explicit exception. No current approved Sites
        // means no records; an empty assignment never grants global access.
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($user, ['sites.viewAll']);
        $scopeBySite = fn ($q, string $column = 'site_id') => $q->whereIn($column, $accessibleSiteIds);

        // Vendor data — only loaded and serialised when the user can see it.
        $vendors = $canVendors
            ? app(\App\Services\SiteVendorAccessService::class)->query($user)
                ->with('site:id,name,type')
                ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
                ->when($request->service_type, fn ($q) => $q->where('service_type', $request->service_type))
                ->when($request->vendor_status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->vendor_status === 'inactive', fn ($q) => $q->where('is_active', false))
                ->when($request->preferred === 'yes', fn ($q) => $q->where('is_preferred', true))
                ->orderBy('service_type')
                ->orderBy('company_name')
                ->limit(1000)
                ->get()
                ->map(fn (SiteVendor $vendor) => $this->vendorPayload($vendor, withSite: true))
                ->values()
            : collect();

        // Credential data — same gate. Credentials carry no plaintext value
        // here (the list view never includes the secret), but the metadata
        // itself is still restricted to credentials.view holders.
        $credentials = $canCredentials ? $vault->presentMany($user, $vault->query($user)
            ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
            ->when($request->credential_type, fn ($q) => $q->where('credential_type', $request->credential_type))
            ->orderBy('label')->limit(1000)->get()) : collect();

        $sites = $scopeBySite(Site::query()
            ->active()
            ->whereIn('type', $allowedSiteTypes), 'id')
            ->select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        $serviceTypes = $canVendors ? app(\App\Services\SiteVendorAccessService::class)->query($user)->select('service_type')->distinct()->orderBy('service_type')->pluck('service_type') : collect();
        $credentialTypes = $canCredentials ? $vault->query($user)->select('credential_type')->distinct()->orderBy('credential_type')->pluck('credential_type') : collect();
        $selectedCredential = $request->filled('credential_id')
            ? $vault->query($user)->find((int) $request->credential_id) : null;
        $returnTo = (string) $request->query('return_to', '');
        $returnTo = preg_match('#^/it/(knowledge(?:/[0-9]+)?|tickets/[0-9]+)(?:\?[a-zA-Z0-9_%=&.+-]*)?$#', $returnTo) ? $returnTo : null;

        return inertia('sites/vendors-credentials/global', [
            'vendors' => $vendors,
            'selectedCredential' => $selectedCredential ? $vault->presentation($user, $selectedCredential) : null,
            'returnTo' => $returnTo,
            'credentials' => $credentials,
            'sites' => $sites,
            'serviceTypes' => $serviceTypes,
            'credentialTypes' => $credentialTypes,
            'credentialTypeOptions' => $canCredentials
                ? CredentialType::pickerOptions()
                : collect(),
            // 'tab' is a UI deep-link hint (e.g. from the Site Calendar
            // credential/vendor reminders); the page whitelists it by permission.
            'filters' => $request->only(['site_id', 'service_type', 'vendor_status', 'preferred', 'credential_type', 'requires_reauth', 'tab']),
            'can' => [
                'vendors' => $canVendors,
                'credentials' => $canCredentials,
                // Writes target the site-scoped routes, which also require
                // sites.viewAny (SitePolicy::view). Fold it in so the manage
                // affordances hide instead of dead-ending in a 403.
                'vendorsManage' => $canSiteWrite && (bool) ($user?->canDo('vendors.manage') ?? false),
                'credentialsManage' => $canSiteWrite && (bool) ($user?->canDo('credentials.manage') ?? false),
                'credentialsReveal' => $credentials->contains(fn ($c) => $c['can_reveal']),
                'contracts' => $commercial,
                'credentialsAudit' => (bool) ($user?->canDo('credentials.audit') ?? false),
                // Type catalogue is application-wide configuration.
                'manageCredentialTypes' => (bool) ($user?->canDo('credentials.manage') ?? false),
            ],
        ]);
    }

    /**
     * Toggle a vendor's preferred / active flags from the global directory
     * (the right-click quick actions). Reuses the vendors.manage gate.
     */
    public function toggleVendorFlags(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('vendors.manage') || abort(403);
        abort_unless($vendor->site_id === $site->id, 404);

        $validated = $request->validate([
            'is_preferred' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validated === []) {
            return back(303);
        }

        DB::transaction(function () use ($site, $validated, $vendor): void {
            $locked = $this->lockedVendor($site, (int) $vendor->id);
            $locked->update($validated);
            if (\Illuminate\Support\Facades\Schema::hasTable('vendor_agreements')) {
                $locked->increment('lock_version');
                app(\App\Services\Sites\VendorAgreements::class)->vendorChanged($locked);
            }
        }, attempts: 1);

        return back(303)->with('success', 'Vendor updated.');
    }

    /**
     * Cross-site reveal & audit log feed for the Vendors & Credentials page.
     * Scoped to the credentials the viewer is allowed to see; gated on
     * credentials.audit so audit access never implies secret disclosure.
     */
    public function globalAudit(Request $request, UserSiteAccessService $siteAccess)
    {
        $user = $request->user();
        abort_unless((bool) ($user?->canDo('credentials.audit') ?? false), 403);

        $allowedSiteTypes = $this->allowedSiteTypes($request);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($user, ['sites.viewAll']);

        $logs = SiteCredentialAuditLog::query()
            ->with(['user:id,name', 'site:id,name,type', 'credential:id,label,credential_type,site_id'])
            ->whereHas('site', fn ($q) => $q->active()->notArchived()->whereNull('archived_at')->whereIn('type', $allowedSiteTypes))
            // Per-user assignment scoping, matching globalIndex / the per-site flow.
            ->whereIn('site_id', $accessibleSiteIds)
            ->when($request->site_id, fn ($q) => $q->where('site_id', (int) $request->site_id))
            // Routine page-load views are not a "reveal/copy/rotation/change" —
            // excluding them keeps high-signal security events from being
            // evicted from the recent window.
            ->where('action', '!=', 'view_list')
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
                    'target' => $log->credential_label ?? $log->credential?->label ?? 'Deleted credential',
                    'target_type' => $log->credential_type ?? $log->credential?->credential_type ?? 'credential',
                    'site_name' => $log->site?->name ?? '—',
                    'ip' => $log->ip_address ?? '—',
                    'result' => match ($log->action) {
                        'reauth_failed', 'denied' => 'denied',
                        'copy_reported_failed' => 'failed',
                        'copy_intent' => 'intent',
                        default => 'ok',
                    },
                ];
            })
            ->values();

        return response()->json(['logs' => $logs]);
    }

    public function index(Request $request, Site $site)
    {
        $this->concealSite($request, $site);

        // The per-site vendors index has been retired in favour of the unified
        // Vendor Directory & Access Vault (sites.vendors.global). The vendor
        // CRUD/flags endpoints below stay live — the new page posts to them.
        return redirect()->route('sites.vendors.global', [
            'site_id' => $site->id,
            'tab' => 'vendors',
        ], 301);
    }

    public function store(Request $request, Site $site)
    {
        $this->concealSite($request, $site);
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
            ...$this->vendorComplianceRules(),
        ]);

        $validated['is_preferred'] = $validated['is_preferred'] ?? false;
        $validated = $this->prepareVendorComplianceData($validated, $request);

        DB::transaction(function () use ($site, $validated): void {
            SiteVendor::query()->create([
                ...$validated,
                'site_id' => $site->id,
                'is_active' => true,
            ]);
        }, attempts: 1);

        return back(303)->with('success', 'Vendor added successfully.');
    }

    public function update(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->concealSite($request, $site);
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
            ...$this->vendorComplianceRules(),
        ]);

        DB::transaction(function () use ($request, $site, $validated, $vendor): void {
            $locked = $this->lockedVendor($site, (int) $vendor->id);
            $locked->update($this->prepareVendorComplianceData($validated, $request, $locked));
            if (\Illuminate\Support\Facades\Schema::hasTable('vendor_agreements')) {
                $locked->increment('lock_version');
                app(\App\Services\Sites\VendorAgreements::class)->vendorChanged($locked);
            }
        }, attempts: 1);

        return back(303)->with('success', 'Vendor updated successfully.');
    }

    public function destroy(Request $request, Site $site, SiteVendor $vendor)
    {
        $this->concealSite($request, $site);
        $request->user()->canDo('vendors.manage') || abort(403);
        abort_unless($vendor->site_id === $site->id, 404);

        $deleted = DB::transaction(function () use ($site, $vendor): bool {
            $locked = $this->lockedVendor($site, (int) $vendor->id);
            if ($locked->credentials()->exists() || (\Illuminate\Support\Facades\Schema::hasTable('vendor_agreements') && $locked->agreements()->exists())) {
                return false;
            }

            return (bool) $locked->delete();
        }, attempts: 1);

        if (! $deleted) {
            return back(303)->with(
                'error',
                'This vendor has retained credentials or agreements. Retire the vendor to preserve its linked records and cancel future renewal follow-ups.',
            );
        }

        return back(303)->with('success', 'Vendor deleted successfully.');
    }

    public function vendorPayload(SiteVendor $vendor, bool $withSite = false): array
    {
        return [
            'id' => $vendor->id,
            'lock_version' => $vendor->lock_version ?? 1,
            'visibility' => $vendor->visibility ?? 'site',
            'site_id' => $vendor->site_id,
            'site_name' => $withSite ? $vendor->site?->name : null,
            'site_type' => $withSite ? $vendor->site?->type : null,
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
            'hs_induction_completed' => (bool) $vendor->hs_induction_completed,
            'hs_induction_date' => $vendor->hs_induction_date?->toDateString(),
            'qualifications_verified' => (bool) $vendor->qualifications_verified,
            'qualifications_notes' => $vendor->qualifications_notes,
            'insurance_verified' => (bool) $vendor->insurance_verified,
            'insurance_expiry' => $vendor->insurance_expiry?->toDateString(),
            'insurance_provider' => $vendor->insurance_provider,
            'insurance_policy_number' => $vendor->insurance_policy_number,
            'site_specific_hs_plan' => $vendor->site_specific_hs_plan,
            'hs_performance_rating' => $vendor->hs_performance_rating,
            'hs_last_reviewed_at' => $vendor->hs_last_reviewed_at?->toDateString(),
        ];
    }

    private function vendorComplianceRules(): array
    {
        return [
            'hs_induction_completed' => 'boolean',
            'hs_induction_date' => 'nullable|date',
            'qualifications_verified' => 'boolean',
            'qualifications_notes' => 'nullable|string',
            'insurance_verified' => 'boolean',
            'insurance_expiry' => 'nullable|date',
            'insurance_provider' => 'nullable|string|max:255',
            'insurance_policy_number' => 'nullable|string|max:255',
            'site_specific_hs_plan' => 'nullable|string',
            'hs_performance_rating' => [
                'nullable',
                'string',
                'max:50',
                Rule::in(['excellent', 'good', 'watch', 'concern']),
            ],
            'hs_last_reviewed_at' => 'nullable|date',
        ];
    }

    private function prepareVendorComplianceData(array $validated, Request $request, ?SiteVendor $vendor = null): array
    {
        if ($vendor === null) {
            $validated['hs_induction_completed'] = $validated['hs_induction_completed'] ?? false;
            $validated['qualifications_verified'] = $validated['qualifications_verified'] ?? false;
            $validated['insurance_verified'] = $validated['insurance_verified'] ?? false;
        }

        if (array_key_exists('hs_induction_completed', $validated)) {
            if ((bool) $validated['hs_induction_completed']) {
                $validated['hs_induction_completed_by'] = $vendor?->hs_induction_completed_by
                    ?? $request->user()->id;
            } else {
                $validated['hs_induction_completed_by'] = null;
            }
        }

        return $validated;
    }

    private function lockedVendor(Site $site, int $vendorId): SiteVendor
    {
        return SiteVendor::query()
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->findOrFail($vendorId);
    }

    private function concealSite(Request $request, Site $site): void
    {
        abort_unless($request->user()?->can('view', $site) === true, 404);
    }
}
