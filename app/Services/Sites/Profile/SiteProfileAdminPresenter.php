<?php

namespace App\Services\Sites\Profile;

use App\Models\CredentialType;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteDocument;
use App\Models\SiteDocumentFolder;
use App\Models\SiteVendor;
use App\Models\User;
use App\Services\Sites\HouseLedgerPresenter;
use App\Services\Sites\HouseLedgerService;
use App\Support\SiteRecommendedDocuments;

class SiteProfileAdminPresenter
{
    public function __construct(
        private readonly HouseLedgerService $houseLedger,
    ) {}

    /** @return array<string, mixed> */
    public function documents(User $user, Site $site): array
    {
        $items = SiteDocument::query()
            ->where('site_id', $site->id)
            ->with('uploadedBy:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SiteDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category,
                'folder' => $document->folder,
                'version' => $document->version,
                'effective_date' => $document->effective_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'notes' => $document->notes,
                'original_name' => $document->original_name,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'uploaded_by' => $document->uploadedBy ? [
                    'id' => $document->uploadedBy->id,
                    'name' => $document->uploadedBy->name,
                    'email' => $document->uploadedBy->email,
                ] : null,
                'created_at' => $document->created_at?->toISOString(),
            ])->values();
        $folderRecords = SiteDocumentFolder::query()
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->get(['id', 'name']);
        $folderNames = $folderRecords->pluck('name')
            ->merge($items->pluck('folder')->filter())
            ->map(fn ($folder) => trim((string) $folder))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return [
            'locked' => false,
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'display_type' => $site->display_type,
            ],
            'can_edit' => ! $site->archived && $user->canDo('sites.update') && $user->can('update', $site),
            'folders' => $folderNames->map(fn ($name) => [
                'id' => $folderRecords->firstWhere('name', $name)?->id,
                'name' => $name,
            ])->values(),
            'documents' => $items,
            'recommendedDocuments' => SiteRecommendedDocuments::forType($site->type),
            'href' => route('sites.documents.index', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function financials(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('finance.dashboard');
        $canViewHouseLedger = in_array($site->type, ['house', 'residential'], true)
            && $user->canDo('sites.ledger.view');

        $ledgerData = null;
        if ($canViewHouseLedger) {
            $ledger = $this->houseLedger->getOrCreateLedger($site);
            $entries = $ledger->entries()
                ->with(['recordedBy:id,name', 'approvedBy:id,name'])
                ->orderByDesc('entry_date')
                ->orderByDesc('id')
                ->paginate(10);
            $payload = HouseLedgerPresenter::payload($site, $ledger, $entries, $user);
            unset($payload['site']);
            $ledgerData = $payload;
        }

        return [
            'locked' => ! $canView && ! $canViewHouseLedger,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'href' => $canView ? route('finance.sites.financial-dashboard', $site) : null,
            'house_ledger' => $ledgerData,
        ];
    }

    /**
     * Credential secrets never leave their canonical reveal endpoints.
     *
     * @return array<string, mixed>
     */
    public function vendorsCredentials(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canViewVendors = $user->canDo('vendors.view');
        $canViewCredentials = $user->canDo('credentials.view');

        $vendors = $canViewVendors
            ? SiteVendor::query()->where('site_id', $site->id)->orderBy('service_type')->orderBy('company_name')->get()
                ->map(fn (SiteVendor $vendor) => [
                    'id' => $vendor->id,
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'site_type' => $site->type,
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
                ])->values()
            : collect();
        $credentials = $canViewCredentials
            ? SiteCredential::query()->where('site_id', $site->id)->with('vendor:id,company_name,service_type')->orderBy('label')->get()
                ->map(fn (SiteCredential $credential) => [
                    'id' => $credential->id,
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'site_type' => $site->type,
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
                ])->values()
            : collect();

        return [
            'locked' => ! $canViewVendors && ! $canViewCredentials,
            'site' => ['id' => $site->id, 'name' => $site->name, 'type' => $site->type],
            'vendors' => $vendors,
            'credentials' => $credentials,
            'credentialTypeOptions' => $canViewCredentials ? CredentialType::pickerOptionsForTenant($user->organization_id) : collect(),
            'can' => [
                'vendors' => $canViewVendors,
                'credentials' => $canViewCredentials,
                'vendorsManage' => ! $site->archived && $user->can('update', $site) && $user->canDo('vendors.manage'),
                'credentialsManage' => ! $site->archived && $user->can('update', $site) && $user->canDo('credentials.manage'),
                'credentialsReveal' => $user->canDo('credentials.reveal'),
            ],
            'href' => $canViewVendors || $canViewCredentials ? route('sites.vendors.global', ['site_id' => $site->id]) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function services(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $query = $site->serviceContexts();
        $items = (clone $query)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'description', 'is_active'])
            ->map(fn (ServiceContext $context) => [
                'id' => $context->id,
                'name' => $context->name,
                'type' => $context->type?->value,
                'description' => $context->description,
                'status' => $context->is_active ? 'active' : 'inactive',
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'can_manage' => $user->canDo('settings.service_contexts.manage'),
            'href' => $user->canDo('settings.service_contexts.manage')
                ? route('settings.service_contexts')
                : null,
        ];
    }

    private function primePermissions(User $user): void
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
    }
}
