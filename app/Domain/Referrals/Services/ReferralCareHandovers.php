<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralCareHandover;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Models\ClientOnboardingWorkflow;
use App\Models\User;
use App\Services\Clients\ClientOnboardingAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Arr;

/** A deliberately narrow care projection, not permission to read the source referral. */
final class ReferralCareHandovers
{
    public function forWorkflow(User $actor, ClientOnboardingWorkflow $workflow): array
    {
        $sites = app(UserSiteAccessService::class);
        $bypass = ['clinical.accessAllSites', 'sites.viewAll'];
        if (! app(ClientOnboardingAccess::class)->canViewWorkflows($actor)
            || ! $workflow->client || ! $actor->can('view', $workflow->client)
            || ! $sites->applyStaffScope(User::query()->whereKey($actor->id), $actor, $bypass)->exists()) {
            return [];
        }
        $siteIds = $sites->accessibleSiteIds($actor, $bypass);
        if ($workflow->site_id && ! in_array((int) $workflow->site_id, $siteIds, true)) {
            return [];
        }
        $conversions = ReferralConversion::query()->where('workflow_id', $workflow->id)->where('client_id', $workflow->client_id)
            ->orderByDesc('converted_at')->get();
        $sources = CareReferral::query()->whereIn('id', $conversions->pluck('referral_id'))->whereIn('site_id', $siteIds)
            ->when(! $actor->canDo('referrals.restricted'), fn ($query) => $query->where('restricted', false))->get()->keyBy('id');
        $latest = ReferralCareHandover::query()->whereIn('id', ReferralCareHandover::query()->selectRaw('MAX(id)')->whereIn('conversion_id', $conversions->pluck('id'))->groupBy('conversion_id'))->get()->keyBy('conversion_id');
        $result = [];
        foreach ($conversions as $conversion) {
            $source = $sources->get($conversion->referral_id);
            $revision = $latest->get($conversion->id);
            $receipt = $revision?->snapshot ?? $conversion->receipt;
            if (! $source || ! ($receipt['review']['acknowledge_care_handover'] ?? false) || ! isset($receipt['care_summary'])) {
                continue;
            }
            // Once authority or the reviewed assessment changes, the historical packet is
            // no longer a current basis for care. Keep the receipt; withhold obsolete facts.
            $current = ReferralCareHandoverRevisions::reviewReady($source)
                && ($source->authority['review']['version'] ?? null) === ($receipt['authority_version'] ?? null)
                && ($source->assessment['version'] ?? null) === ($receipt['assessment_version'] ?? null);
            $canOpenSource = app(ReferralAccess::class)->can($actor, 'assessment.view')
                && app(ReferralAccess::class)->query($actor)->whereKey($source->id)->exists();
            $result[] = [
                'id' => $conversion->id, 'handed_over_at' => ($revision?->published_at ?? $conversion->converted_at)->toISOString(),
                'handover_version' => $revision?->version ?? 1,
                'status' => $current ? 'current' : 'review_required',
                'care' => $current ? Arr::only($receipt['care_summary'], ['goals', 'participation', 'safety_needs']) : null,
                'source_url' => $canOpenSource ? '/operations/referrals/'.$source->reference : null,
            ];
        }

        return $result;
    }
}
