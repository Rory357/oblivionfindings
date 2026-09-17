<?php

namespace App\Http\Controllers\Sites;

use App\Domain\Finance\Models\FinVendor;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\SiteVendor;
use App\Models\User;
use App\Services\Sites\VendorCommercialAccess;
use App\Services\SiteVendorAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VendorWorkspaceController extends Controller
{
    public function show(Request $request, SiteVendor $vendor, VendorCommercialAccess $commercial)
    {
        $actor = $request->user();
        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']);
        $directory = app(SiteVendorAccessService::class)->query($actor)->whereKey($vendor->id)->exists();
        $contracts = $commercial->vendors($actor)->whereKey($vendor->id)->exists();
        abort_unless($directory || $contracts, 404);
        $ready = Schema::hasTable('vendor_agreements');
        $vendor->load('site:id,name', 'owner:id,name');
        $owners = $ready && (($directory && $actor->canDo('vendors.manage')) || $commercial->capable($actor, 'manage')) ? User::whereNotNull('approved_at')->orderBy('name')->get()->filter(fn ($u) => in_array((int) $vendor->site_id, app(UserSiteAccessService::class)->accessibleSiteIds($u, ['sites.viewAll']), true)) : collect();
        return inertia('sites/vendors/show', [
            'vendor' => app(SiteVendorController::class)->vendorPayload($vendor, true) + $vendor->only(['owner_user_id', 'supplied_services']) + ['owner_name' => $vendor->owner?->name],
            'relatedRecords' => $directory ? app(\App\Domain\It\Services\ItKnowledgeRelationships::class)->resolve($actor, $vendor->related_records ?? []) : [],
            'documentationHref' => $directory && ($actor->canDo('it.view') || app(\App\Domain\It\Services\ItKbAccessService::class)->hasKnowledgeCapability($actor) || $actor->canDo('it.manage')) ? '/it/knowledge?related_type=vendor&related_id='.$vendor->id : null,
            'selectedAgreementId' => $request->integer('agreement') ?: null,
            'financeLink' => $contracts && $ready && $vendor->finance_vendor_id ? FinVendor::whereKey($vendor->finance_vendor_id)->first(['id', 'name']) : null,
            'financeOptions' => $contracts && $commercial->capable($actor, 'manage') ? FinVendor::active()->orderBy('name')->get(['id', 'name']) : [],
            'agreements' => $contracts && $ready ? $commercial->query($actor)->where('vendor_id', $vendor->id)->get()->map(fn ($a) => app(VendorAgreementController::class)->payload($a, $actor)) : [],
            'owners' => $owners->map->only(['id', 'name'])->values(),
            'commercialOwners' => $contracts ? $owners->filter(fn ($u) => $commercial->capable($u))->map->only(['id', 'name'])->values() : [],
            'assets' => $contracts ? app(\App\Domain\SecurityDevices\Services\SecurityDevicesAccessService::class)->accessibleAssets($actor)->where('site_id', $vendor->site_id)->get(['id', 'name']) : [],
            'ready' => $ready,
            'can' => ['manage' => $directory && $actor->canDo('vendors.manage') && in_array((int) $vendor->site_id, $siteIds, true), 'contracts' => $contracts, 'contractsManage' => $contracts && $commercial->vendors($actor, 'manage')->whereKey($vendor->id)->exists()],
        ]);
    }

    public function update(Request $request, SiteVendor $vendor)
    {
        $data = $request->validate(['visibility' => ['sometimes', 'required', Rule::in(['site', 'all_approved_sites'])], 'owner_user_id' => 'sometimes|required|integer|exists:users,id',
            'supplied_services' => 'nullable|string|max:5000', 'related_records' => 'sometimes|array|max:30', 'finance_vendor_id' => 'sometimes|nullable|integer|exists:fin_vendors,id', 'lock_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $vendor, $data) {
            $actor = User::findOrFail($request->user()->id);
            $vendor = SiteVendor::lockForUpdate()->findOrFail($vendor->id);
            abort_unless($actor->canDo('vendors.manage') && app(SiteVendorAccessService::class)->query($actor)->whereKey($vendor->id)->exists(), 404);
            app(UserSiteAccessService::class)->assertCanAccessSiteId($actor, $vendor->site_id, ['sites.viewAll']);
            if ((int) $vendor->lock_version !== $data['lock_version']) throw ValidationException::withMessages(['lock_version' => 'This vendor changed. Refresh before reapplying your changes.']);
            if (isset($data['owner_user_id'])) {
                $owner = User::findOrFail($data['owner_user_id']);
                if (! $owner->approved_at || ! in_array((int) $vendor->site_id, app(UserSiteAccessService::class)->accessibleSiteIds($owner, ['sites.viewAll']), true)) throw ValidationException::withMessages(['owner_user_id' => 'Choose a current owner with access to this site.']);
            }
            if (array_key_exists('finance_vendor_id', $data)) abort_unless(app(VendorCommercialAccess::class)->capable($actor, 'manage'), 403);
            if (array_key_exists('related_records', $data)) {
                $links = app(\App\Domain\It\Services\ItKnowledgeRelationships::class);
                foreach ($data['related_records'] as $ref) {
                    abort_unless(is_array($ref) && in_array($ref['type'] ?? null, ['service', 'asset', 'article'], true), 422);
                }
                $data['related_records'] = $links->normalise($actor, $data['related_records']);
                // An editor cannot silently remove references they could not see.
                $visible = collect($links->resolve($actor, $vendor->related_records ?? []))->map(fn ($ref) => $ref['type'].':'.$ref['id']);
                $hidden = collect($vendor->related_records ?? [])->reject(fn ($ref) => $visible->contains($ref['type'].':'.$ref['id']))->values()->all();
                $data['related_records'] = [...$data['related_records'], ...$hidden];
            }
            $vendor->fill($data);
            $vendor->lock_version++;
            $vendor->save();
            app(\App\Services\Sites\VendorAgreements::class)->vendorChanged($vendor);
        });
        return response()->json(['ok' => true]);
    }

    public function recordOptions(Request $request, SiteVendor $vendor)
    {
        $actor = $request->user();
        abort_unless($actor->canDo('vendors.manage') && app(SiteVendorAccessService::class)->query($actor)->whereKey($vendor->id)->exists(), 404);
        app(UserSiteAccessService::class)->assertCanAccessSiteId($actor, $vendor->site_id, ['sites.viewAll']);
        $data = $request->validate(['type' => ['required', Rule::in(['service', 'asset', 'article'])], 'q' => 'nullable|string|max:200', 'page' => 'nullable|integer|min:1|max:10000']);
        return response()->json(app(\App\Domain\It\Services\ItKnowledgeRelationships::class)->options($actor, $data['type'], $data['q'] ?? '', $data['page'] ?? 1))->header('Cache-Control', 'no-store, private');
    }

    public function financeLink(Request $request, SiteVendor $vendor, VendorCommercialAccess $commercial)
    {
        $data = $request->validate(['finance_vendor_id' => 'nullable|integer|exists:fin_vendors,id', 'lock_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $vendor, $commercial, $data) {
            $actor = User::findOrFail($request->user()->id);
            $locked = SiteVendor::lockForUpdate()->findOrFail($vendor->id);
            abort_unless($commercial->vendors($actor, 'manage')->whereKey($locked->id)->exists(), 404);
            if ($locked->lock_version !== $data['lock_version']) throw ValidationException::withMessages(['lock_version' => 'This vendor changed. Refresh before updating its Finance supplier link.']);
            $locked->fill(['finance_vendor_id' => $data['finance_vendor_id'] ?? null, 'lock_version' => $locked->lock_version + 1])->save();
        });
        return response()->json(['ok' => true]);
    }

}
