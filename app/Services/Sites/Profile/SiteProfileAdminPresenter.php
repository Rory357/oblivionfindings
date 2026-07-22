<?php

namespace App\Services\Sites\Profile;

use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteDocument;
use App\Models\SiteVendor;
use App\Models\User;

class SiteProfileAdminPresenter
{
    /** @return array<string, mixed> */
    public function documents(User $user, Site $site): array
    {
        $query = SiteDocument::query()->where('site_id', $site->id);
        $today = now()->toDateString();
        $counts = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_soon', [$today, now()->addDays(60)->toDateString()])
            ->selectRaw('SUM(CASE WHEN expiry_date < ? THEN 1 ELSE 0 END) as expired', [$today])
            ->first();
        $items = (clone $query)
            ->with('uploadedBy:id,name')
            ->orderByDesc('created_at')
            ->limit(SiteDocument::PROFILE_LIMIT)
            ->get()
            ->map(fn (SiteDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category,
                'folder' => $document->folder,
                'version' => $document->version,
                'effective_date' => $document->effective_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'uploaded_by' => $document->uploadedBy?->name,
                'created_at' => $document->created_at?->toISOString(),
                'href' => route('sites.documents.download', [$site, $document]),
            ])->values();

        return [
            'locked' => false,
            'items' => $items,
            'summary' => [
                'total' => (int) ($counts?->total ?? 0),
                'shown' => $items->count(),
                'expiring_soon' => (int) ($counts?->expiring_soon ?? 0),
                'expired' => (int) ($counts?->expired ?? 0),
            ],
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

        return [
            'locked' => ! $canView,
            'href' => $canView ? route('finance.sites.financial-dashboard', $site) : null,
            'house_ledger' => $canViewHouseLedger ? [
                'href' => route('sites.ledger.index', $site),
                'label' => 'House ledger',
            ] : null,
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

        return [
            'locked' => ! $canViewVendors && ! $canViewCredentials,
            'summary' => $canViewVendors || $canViewCredentials ? [
                'vendors' => $canViewVendors
                    ? SiteVendor::query()->where('site_id', $site->id)->where('is_active', true)->count()
                    : null,
                'credentials' => $canViewCredentials
                    ? SiteCredential::query()->where('site_id', $site->id)->count()
                    : null,
            ] : null,
            'href' => $canViewVendors || $canViewCredentials
                ? route('sites.vendors.global', ['site_id' => $site->id])
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function services(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $query = $site->serviceContexts();
        $counts = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->first();
        $items = (clone $query)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(ServiceContext::PROFILE_LIMIT)
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
            'summary' => [
                'total' => (int) ($counts?->total ?? 0),
                'active' => (int) ($counts?->active ?? 0),
                'shown' => $items->count(),
            ],
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
