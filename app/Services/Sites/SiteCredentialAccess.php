<?php

namespace App\Services\Sites;

use App\Models\SiteCredential;
use App\Models\User;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class SiteCredentialAccess
{
    public function ready(): bool { return Schema::hasTable('site_credential_versions') && Schema::hasTable('site_credential_step_up_uses'); }

    public function query(User $actor, string $action = 'view'): Builder
    {
        $query = SiteCredential::query();
        if ($actor->approved_at === null || ! in_array($action, ['view', 'reveal', 'copy', 'manage', 'audit'], true)) return $query->whereRaw('1 = 0');
        $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']);
        $assigned = app(UserSiteAccessService::class)->accessibleSiteIds($actor);
        $ready = $this->ready();
        $explicitlyDenied = $actor->permissionOverrides()->where('key', 'credentials.'.$action)->wherePivot('allowed', false)->exists();
        $houseAllowed = $ready && ! $explicitlyDenied && ! $actor->hasRole('client', 'next_of_kin') && $assigned !== [] && in_array($action, ['view', 'reveal', 'copy'], true);
        return $query->whereHas('site', fn ($s) => $s->active()->notArchived()->whereNull('archived_at')->whereIn('type', app(SiteTypeAccessService::class)->allowedTypes($actor)))
            ->where(function ($q) use ($actor, $action, $ids, $assigned, $ready, $houseAllowed) {
                $q->where(function ($q) use ($actor, $action, $ids, $ready) {
                    if (! $actor->canDo('credentials.'.$action)) { $q->whereRaw('1 = 0'); return; }
                    $q->whereIn('site_id', $ids);
                    if ($ready && $ids !== [] && in_array($action, ['view', 'reveal', 'copy'], true)) $q->orWhere('visibility', 'all_approved_sites');
                });
                if ($houseAllowed) $q->orWhere(fn ($q) => $q->where('house_staff_access', true)->whereIn('site_id', $assigned)->whereHas('site', fn ($s) => $s->where('type', 'house')));
            });
    }

    public function authorize(User $actor, SiteCredential $credential, string $action, bool $allowRetired = false): void
    {
        abort_unless($this->query($actor, $action)->whereKey($credential->id)->exists(), 404);
        abort_if(! $allowRetired && $credential->retired_at, 409, 'This credential is retired.');
    }

    public function presentMany(User $actor, \Illuminate\Database\Eloquent\Collection $credentials): \Illuminate\Support\Collection
    {
        $ids = $credentials->modelKeys();
        $allowed = $this->query($actor)->whereKey($ids)->pluck('id')->all();
        $credentials = $credentials->whereIn('id', $allowed);
        $credentials->loadMissing('site:id,name,type', 'lastRotatedBy:id,name');
        $grants = [];
        foreach (['reveal', 'copy', 'manage', 'audit'] as $action) {
            $grants[$action] = array_fill_keys($this->query($actor, $action)->whereKey($ids)->pluck('id')->all(), true);
        }
        $vendors = app(\App\Services\SiteVendorAccessService::class)->query($actor)->whereKey($credentials->pluck('vendor_id')->filter())
            ->get(['id', 'company_name', 'service_type'])->keyBy('id');
        return $credentials->map(fn ($credential) => $this->project($credential, $vendors->get($credential->vendor_id),
            array_map(fn ($permitted) => isset($permitted[$credential->id]), $grants)))->values();
    }

    public function presentation(User $actor, SiteCredential $credential): array
    {
        $this->authorize($actor, $credential, 'view', true);
        $credential->loadMissing('site:id,name,type', 'lastRotatedBy:id,name');
        $vendor = $credential->vendor_id ? app(\App\Services\SiteVendorAccessService::class)->query($actor)->whereKey($credential->vendor_id)->first(['id', 'company_name', 'service_type']) : null;
        $grants = [];
        foreach (['reveal', 'copy', 'manage', 'audit'] as $action) $grants[$action] = $this->query($actor, $action)->whereKey($credential->id)->exists();
        return $this->project($credential, $vendor, $grants);
    }

    private function project(SiteCredential $credential, ?\App\Models\SiteVendor $vendor, array $grants): array
    {
        return $credential->only(['id', 'site_id', 'label', 'credential_type', 'username', 'url', 'notes', 'is_shareable', 'password_strength', 'visibility', 'house_staff_access', 'lock_version', 'rotation_kind']) + [
            'site_name' => $credential->site?->name, 'site_type' => $credential->site?->type,
            'vendor_id' => $vendor?->id, 'vendor_name' => $vendor?->company_name, 'vendor_service_type' => $vendor?->service_type,
            'requires_reauth' => true, 'has_totp' => $credential->hasTotp(), 'value_preview' => '********',
            'last_rotated_at' => $credential->last_rotated_at?->toIso8601String(), 'retired_at' => $credential->retired_at?->toIso8601String(),
            'rotation_verified_by' => $credential->lastRotatedBy?->name,
            'can_reveal' => ! $credential->retired_at && $grants['reveal'],
            'can_copy' => ! $credential->retired_at && $grants['copy'],
            'can_manage' => $grants['manage'],
            'can_audit' => $grants['audit'],
        ];
    }
}
