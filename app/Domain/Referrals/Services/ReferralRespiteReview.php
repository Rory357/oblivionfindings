<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\ReferralConversion;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\ServiceContext;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Schema;

/** Only a currently authorised reviewer receives a private referral link. */
final class ReferralRespiteReview
{
    /** Preparation is separately authorised; opening a case does not grant start approval. */
    public function preparation(User $actor, Client $client, RespiteBooking $booking): ?array
    {
        $siteId = (int) ($booking->location_id ?: $client->site_id);
        $contextId = (int) ($booking->request?->service_context_id ?: $client->service_context_id);
        if (! app(InternalSupportPreparation::class)->canPrepare($actor, $client)
            || ! app(ReferralAccess::class)->eligibleForSite($actor, $siteId)
            || ! in_array($siteId, app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['clinical.accessAllSites', 'sites.viewAll']), true)
            || ! ServiceContext::query()->availableToSite($siteId)->where('is_active', true)
                ->whereIn('type', ['planned_respite', 'emergency_respite', 'community_respite'])->whereKey($contextId)->exists()) {
            return null;
        }

        return ['person_id' => $client->id, 'site_id' => $siteId, 'service_context_id' => $contextId];
    }

    public function url(User $actor, Client $client, RespiteBooking $booking): ?string
    {
        $access = app(ReferralAccess::class);
        if (! Schema::hasTable('care_referral_conversions') || ! $access->can($actor, 'commence')
            || ! $access->can($actor, 'assessment.view') || ! $access->can($actor, 'funding.view')
            || ! $actor->canDo('care_plans.viewAny') || ! $actor->canDo('service_agreements.viewAny')
            || ! app(ClientPolicy::class)->view($actor, $client) || ! app(ClientPolicy::class)->update($actor, $client)) {
            return null;
        }
        $siteId = (int) ($booking->location_id ?: $client->site_id);
        $contextId = (int) ($booking->request?->service_context_id ?: $client->service_context_id);
        if (! ServiceContext::query()->availableToSite($siteId)->where('is_active', true)
            ->whereIn('type', ['planned_respite', 'emergency_respite', 'community_respite'])->whereKey($contextId)->exists()) {
            return null;
        }
        $visible = $access->query($actor)->where('client_id', $client->id)->where('stage', 'converted')->where('site_id', $siteId);
        $conversions = ReferralConversion::query()->where('client_id', $client->id)
            ->whereIn('referral_id', (clone $visible)->select('id'))->orderByDesc('id')->limit(50)->get();
        foreach ($conversions as $conversion) {
            if ((int) ($conversion->receipt['site_id'] ?? 0) === $siteId
                && (int) ($conversion->receipt['service_context_id'] ?? 0) === $contextId) {
                $reference = (clone $visible)->whereKey($conversion->referral_id)->value('reference');

                return $reference ? '/operations/referrals/'.rawurlencode($reference).'?tab=service-start' : null;
            }
        }

        return null;
    }
}
