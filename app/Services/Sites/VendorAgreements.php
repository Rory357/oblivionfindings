<?php

namespace App\Services\Sites;

use App\Models\SiteVendor;
use App\Models\User;
use App\Models\VendorAgreement;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VendorAgreements
{
    public function save(User $actor, SiteVendor $vendor, array $input, ?VendorAgreement $agreement = null): VendorAgreement
    {
        return DB::transaction(function () use ($actor, $vendor, $input, $agreement) {
            $actor = User::findOrFail($actor->id);
            $vendor = SiteVendor::lockForUpdate()->findOrFail($vendor->id);
            abort_unless(app(VendorCommercialAccess::class)->capable($actor, 'manage'), 404);
            $ids = app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']);
            abort_unless(app(VendorCommercialAccess::class)->vendors($actor, 'manage')->whereKey($vendor->id)->exists(), 404);
            abort_unless($vendor->is_active, 409);
            $data = validator($input, [
                'creation_key' => $agreement ? 'prohibited' : 'nullable|uuid',
                'title' => 'required|string|max:255', 'kind' => ['required', Rule::in(['licence', 'warranty', 'support', 'contract', 'domain', 'certificate'])],
                'reference' => 'nullable|string|max:255', 'starts_on' => 'nullable|date_format:Y-m-d',
                'renews_on' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['starts_on']) ? ['after_or_equal:starts_on'] : [])], 'notice_days' => 'required|integer|min:0|max:730',
                'owner_user_id' => 'required|integer|exists:users,id', 'asset_id' => 'nullable|integer|exists:assets,id',
                'amount' => 'nullable|numeric|min:0|max:999999999999', 'currency' => ['required', Rule::in(['NZD', 'AUD', 'USD', 'GBP', 'EUR'])],
                'terms' => 'nullable|string|max:20000', 'evidence' => 'nullable|string|max:5000',
                'visibility' => ['required', Rule::in(['site', 'all_approved_sites'])],
                'lock_version' => $agreement ? 'required|integer|min:1' : 'nullable|integer',
            ])->validate();
            ksort($data);
            $digest = hash_hmac('sha256', json_encode([$actor->id, $vendor->id, $data], JSON_THROW_ON_ERROR), config('app.key'));
            if (! $agreement && ! empty($data['creation_key']) && $existing = VendorAgreement::where('creation_key', $data['creation_key'])->first()) {
                abort_unless(hash_equals($existing->creation_digest ?? '', $digest), 409);
                app(VendorCommercialAccess::class)->authorize($actor, $existing, 'manage');
                return $existing;
            }
            if ($data['visibility'] === 'all_approved_sites' && $vendor->visibility !== 'all_approved_sites') throw ValidationException::withMessages(['visibility' => 'Share the vendor across approved sites before sharing its agreement.']);
            $owner = User::findOrFail($data['owner_user_id']);
            $ownerSites = app(UserSiteAccessService::class)->accessibleSiteIds($owner, ['sites.viewAll']);
            if (! app(VendorCommercialAccess::class)->capable($owner) || ! in_array((int) $vendor->site_id, $ownerSites, true)) {
                throw ValidationException::withMessages(['owner_user_id' => 'Choose a current owner with commercial access at this site.']);
            }
            if (! empty($data['asset_id']) && ! app(\App\Domain\SecurityDevices\Services\SecurityDevicesAccessService::class)->accessibleAssets($actor)->whereKey($data['asset_id'])->where('site_id', $vendor->site_id)->exists()) {
                throw ValidationException::withMessages(['asset_id' => 'Choose a permitted asset at this site.']);
            }
            if ($agreement) {
                $agreement = VendorAgreement::lockForUpdate()->findOrFail($agreement->id);
                app(VendorCommercialAccess::class)->authorize($actor, $agreement, 'manage');
                abort_unless($agreement->vendor_id === $vendor->id, 404);
                $this->checkVersion($agreement, $data['lock_version']);
                if ($agreement->status === 'retired') throw ValidationException::withMessages(['status' => 'Restore this agreement before editing it.']);
                // Hidden links are not editable through a commercial-only projection.
                if ($agreement->asset_id && ! app(\App\Domain\SecurityDevices\Services\SecurityDevicesAccessService::class)->accessibleAssets($actor)->whereKey($agreement->asset_id)->exists()) unset($data['asset_id']);
            }
            unset($data['lock_version']);
            if (! $agreement && ! empty($data['creation_key'])) $data['creation_digest'] = $digest;
            $saved = $agreement ?? new VendorAgreement(['vendor_id' => $vendor->id, 'site_id' => $vendor->site_id, 'status' => 'active', 'lock_version' => 0]);
            $saved->fill($data);
            $saved->lock_version++;
            $saved->save();
            $this->event($saved, $actor, $agreement ? 'edited' : 'created', $data['evidence'] ?? null);
            $this->reconcile($saved);
            return $saved;
        });
    }

    public function transition(User $actor, VendorAgreement $agreement, array $input): VendorAgreement
    {
        $data = validator($input, [
            'action' => ['required', Rule::in(['review', 'renew', 'retire', 'restore'])],
            'lock_version' => 'required|integer|min:1', 'evidence' => 'required|string|min:10|max:5000',
            'renews_on' => 'required_if:action,renew|nullable|date_format:Y-m-d|after:'.now(config('app.worker_timezone'))->toDateString(),
        ])->validate();
        return DB::transaction(function () use ($actor, $agreement, $data) {
            $actor = User::findOrFail($actor->id);
            $locked = VendorAgreement::lockForUpdate()->findOrFail($agreement->id);
            app(VendorCommercialAccess::class)->authorize($actor, $locked, 'manage');
            $this->checkVersion($locked, $data['lock_version']);
            abort_if(in_array($data['action'], ['review', 'renew', 'restore'], true) && ! $locked->vendor->is_active, 409);
            if (($locked->status === 'retired') !== ($data['action'] === 'restore')) throw ValidationException::withMessages(['action' => 'This action is unavailable in the current state. Refresh the agreement.']);
            $locked->status = match ($data['action']) { 'retire' => 'retired', 'renew' => 'renewed', 'restore' => 'active', default => $locked->status };
            if ($data['action'] === 'renew') $locked->renews_on = $data['renews_on'];
            $locked->lock_version++;
            $locked->save();
            $this->event($locked, $actor, $data['action'], $data['evidence']);
            $this->reconcile($locked);
            if ($data['action'] === 'review') DB::table('vendor_renewal_followups')->where('agreement_id', $locked->id)->whereNotIn('status', ['cancelled', 'owner_unavailable'])->update(['reviewed_at' => now(), 'status' => 'reviewed', 'updated_at' => now()]);
            return $locked;
        });
    }

    public function checkVersion(VendorAgreement $agreement, int $version): void
    {
        if ($agreement->lock_version !== $version) throw ValidationException::withMessages(['lock_version' => 'This agreement changed. Your input is retained; refresh and review the latest version before reapplying.']);
    }

    public function event(VendorAgreement $agreement, User $actor, string $action, ?string $evidence = null): void
    {
        DB::table('vendor_agreement_events')->insert(['agreement_id' => $agreement->id, 'user_id' => $actor->id, 'action' => $action,
            'snapshot' => json_encode($agreement->getAttributes(), JSON_THROW_ON_ERROR), 'evidence' => $evidence, 'created_at' => now()]);
    }

    public function reconcile(VendorAgreement $agreement): void
    {
        $due = $agreement->renews_on?->copy()->subDays($agreement->notice_days)->toDateString();
        // A single durable owner task is rescheduled, never appended on a poll.
        $existing = DB::table('vendor_renewal_followups')->where('agreement_id', $agreement->id)->first();
        $cancelled = $this->cancelled($agreement);
        $status = $cancelled ? 'cancelled' : ($due ? ($due <= now(config('app.worker_timezone'))->toDateString() ? 'due' : 'scheduled') : 'unscheduled');
        $owner = User::find($agreement->owner_user_id);
        $ownerAllowed = $owner && app(VendorCommercialAccess::class)->query($owner)->whereKey($agreement->id)->exists();
        if (! $cancelled && ! $ownerAllowed) $status = 'owner_unavailable';
        $unchanged = $existing && $existing->due_on === $due && (int) $existing->owner_user_id === (int) $agreement->owner_user_id;
        if ($unchanged && $existing->reviewed_at && ! in_array($status, ['cancelled', 'owner_unavailable'], true)) $status = 'reviewed';
        DB::table('vendor_renewal_followups')->updateOrInsert(['agreement_id' => $agreement->id], [
            'owner_user_id' => $agreement->owner_user_id, 'due_on' => $due, 'status' => $status,
            'reviewed_at' => $unchanged ? $existing->reviewed_at : null, 'updated_at' => now(), 'created_at' => $existing?->created_at ?? now(),
        ]);
    }

    public function cancelled(VendorAgreement $agreement): bool
    {
        $vendor = $agreement->vendor()->with('site')->firstOrFail();
        $serviceIds = collect($vendor->related_records ?? [])->where('type', 'service')->pluck('id');
        $inactiveService = $serviceIds->isNotEmpty() && \App\Models\ItService::whereKey($serviceIds)->where(fn ($q) => $q->where('status', 'retired')->orWhere('is_active', false))->exists();
        return $agreement->status === 'retired' || ! $vendor->is_active || ! $vendor->site?->is_active || $vendor->site?->archived || (bool) $vendor->site?->archived_at || $inactiveService;
    }

    public function vendorChanged(SiteVendor $vendor): void
    {
        VendorAgreement::where('vendor_id', $vendor->id)->orderBy('id')->lockForUpdate()->get()->each(fn ($agreement) => $this->reconcile($agreement));
    }

    public function due(): int
    {
        $changed = 0;
        VendorAgreement::orderBy('id')->chunkById(100, function ($agreements) use (&$changed) {
            foreach ($agreements as $agreement) {
                DB::transaction(function () use ($agreement, &$changed) {
                    $locked = VendorAgreement::lockForUpdate()->findOrFail($agreement->id);
                    $before = DB::table('vendor_renewal_followups')->where('agreement_id', $locked->id)->value('status');
                    $this->reconcile($locked);
                    $after = DB::table('vendor_renewal_followups')->where('agreement_id', $locked->id)->value('status');
                    if ($before !== $after && $after === 'due') $changed++;
                });
            }
        });
        return $changed;
    }
}
