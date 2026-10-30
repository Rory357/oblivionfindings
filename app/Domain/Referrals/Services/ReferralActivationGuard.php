<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Support\SchemaCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical activation and respite admission share the recorded service-start decision.
 *
 * Scope: by default the gate applies only to people whose own start was handed over by a
 * referral conversion or an open internal "Prepare support" case. Everyone else keeps the
 * established profile, onboarding-completion and respite behaviour. The organisation can opt
 * in to requiring the review before ANY person becomes active (REQUIRE_FOR_EVERYONE_KEY).
 * Site or service moves of an already-active person are never treated as a new activation.
 */
final class ReferralActivationGuard
{
    /** Org-level switch (app_settings, boolean, default off). */
    public const REQUIRE_FOR_EVERYONE_KEY = 'referrals.service_start.require_review_for_all_people';

    public static function requiredForEveryone(): bool
    {
        if (! SchemaCache::hasTable('app_settings')) {
            return false;
        }

        return filter_var(AppSetting::query()->useWritePdo()->where('key', self::REQUIRE_FOR_EVERYONE_KEY)->value('value'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether a transition of this existing person INTO active must follow a recorded commencement. */
    public function governsActivation(Client $client): bool
    {
        if (self::requiredForEveryone()) {
            return true;
        }
        if (! $client->exists || ! SchemaCache::hasTable('care_referral_conversions')) {
            return false;
        }

        // Additional-support conversions belong to people whose own start predates the referral.
        return $this->personConversions($client)->isNotEmpty()
            || CareReferral::query()->where('client_id', $client->id)->where('entry_kind', InternalSupportPreparation::KIND)
                ->whereNotIn('stage', ['closed', 'converted'])->sharedLock()->exists();
    }

    /**
     * Respite stays follow the same rule as activation: referral-originated people (and everyone,
     * when the organisation opts in) need a recorded commencement for the respite site and
     * service. A referral for additional respite support also governs that respite service.
     */
    public function governsRespite(Client $client, RespiteBooking $booking): bool
    {
        if ($this->governsActivation($client)) {
            return true;
        }
        if (! SchemaCache::hasTable('care_referral_conversions')) {
            return false;
        }
        [$siteId, $contextId] = $this->respiteScope($client, $booking);

        return ReferralConversion::query()->where('client_id', $client->id)->sharedLock()->get()
            ->contains(fn (ReferralConversion $conversion) => $this->matchesScope($conversion, $siteId, $contextId));
    }

    public function assertStartDatePermitted(Client $client): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Start-date checks must share the canonical update transaction.');
        }
        $current = Client::query()->lockForUpdate()->findOrFail($client->id);
        $message = 'The actual service-start date is recorded by the separate commencement review and cannot be changed in the profile.';
        abort_unless($current->status === 'onboarding' && $client->status === 'active' && $client->service_start_date, 422, $message);
        abort_unless(SchemaCache::hasTable('care_referral_conversions'), 503, 'Service-start review is unavailable. Complete application setup before starting support.');
        $conversions = ReferralConversion::query()->where('client_id', $client->id)->lock('for share nowait')->get();
        foreach ($conversions as $conversion) {
            if (! $this->matchesScope($conversion, (int) $client->site_id, (int) $client->service_context_id)) {
                continue;
            }
            $start = $this->recordedStart($client, $conversion);
            if ($start && $start->commenced_at->copy()->timezone('Pacific/Auckland')->toDateString() === $client->service_start_date->toDateString()) {
                return;
            }
        }
        abort(422, $message);
    }

    /**
     * A first activation needs a recorded commencement for the person's current site and service.
     * A re-activation (for example after a hospital stay) relies on the latest person-level
     * conversion having a recorded commencement; later site/service moves do not invalidate it.
     */
    public function assertPermitted(Client $client): void
    {
        abort_unless(SchemaCache::hasTable('care_referral_conversions'), 503, 'Service-start review is unavailable. Complete application setup before starting support.');
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Activation checks must share the canonical update transaction.');
        }
        $conversions = ReferralConversion::query()->where('client_id', $client->id)->lock('for share nowait')->get();
        foreach ($conversions as $conversion) {
            if ($this->matchesScope($conversion, (int) $client->site_id, (int) $client->service_context_id) && $this->recordedStart($client, $conversion)) {
                return;
            }
        }
        $latest = $this->personConversions($client, $conversions)->sortByDesc('id')->first();
        if ($latest && $client->getOriginal('service_start_date') !== null && $this->recordedStart($client, $latest)) {
            return;
        }
        abort(422, 'This person needs a current, separately approved service commencement. Use Prepare support on their onboarding page to arrange the review. Completing onboarding or editing the profile does not start support.');
    }

    public function assertRespitePermitted(Client $client, RespiteBooking $booking): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Respite commencement checks must share the stay transaction.');
        }
        if (! $this->governsRespite($client, $booking)) {
            return;
        }
        abort_unless(SchemaCache::hasTable('care_referral_conversions'), 503, 'Service-start review is unavailable. Complete application setup before starting support.');
        $conversions = ReferralConversion::query()->where('client_id', $client->id)->lock('for share nowait')->get();
        [$siteId, $contextId] = $this->respiteScope($client, $booking);
        $context = ServiceContext::query()->availableToSite($siteId)->where('is_active', true)
            ->whereIn('type', ['planned_respite', 'emergency_respite', 'community_respite'])->lock('for share nowait')->find($contextId);
        if ($context && $booking->service_agreement_id && (int) $booking->client_id === (int) $client->id) {
            foreach ($conversions as $conversion) {
                if (! $this->matchesScope($conversion, $siteId, $contextId)) {
                    continue;
                }
                $start = $this->recordedStart($client, $conversion);
                $period = $start?->handover['proposal_period'] ?? null;
                if ($start && (int) $start->service_agreement_id === (int) $booking->service_agreement_id
                    && $booking->start_at && $booking->end_at && $period
                    && $booking->start_at->copy()->timezone('Pacific/Auckland')->toDateString() >= $period['starts_on']
                    && $booking->end_at->copy()->timezone('Pacific/Auckland')->toDateString() <= $period['ends_on']) {
                    return;
                }
            }
        }
        throw ValidationException::withMessages(['service_start' => 'Ask the responsible manager to review the current service-start decision for this respite site, service, agreement and stay dates. Scheduled approval alone does not record actual commencement.']);
    }

    /**
     * The recorded commencement decision, not a fresh readiness recomputation.
     *
     * Readiness (funding validity, agreement terms, care plan digest, staffing) is checked by
     * ReferralServiceCommencement when the start is approved and again when actual commencement
     * is recorded, inside the same transaction that activates the person. Re-running it here
     * would let routine care-plan edits, agreement updates, a review date passing or a new
     * agreement column retroactively invalidate support that already started. A start is
     * current when it is recorded as commenced and not in the future. A later prerequisite
     * change on a commenced start opens a manager review task (ReferralAssessment::
     * invalidateReadiness) but never stops existing support, so it does not void the record here;
     * starts that had not commenced are moved to review_required and never match.
     */
    private function recordedStart(Client $client, ReferralConversion $conversion): ?ReferralServiceStart
    {
        if (! CareReferral::query()->lock('for share nowait')->whereKey($conversion->referral_id)->exists()) {
            return null;
        }
        $start = ReferralServiceStart::query()->where('referral_id', $conversion->referral_id)->where('client_id', $client->id)
            ->where('status', 'commenced')->lock('for share nowait')->first();

        return $start?->commenced_at && ! $start->commenced_at->isFuture() && ! empty($start->handover['selection']) ? $start : null;
    }

    private function personConversions(Client $client, ?Collection $conversions = null): Collection
    {
        $conversions ??= ReferralConversion::query()->where('client_id', $client->id)->sharedLock()->get();

        return $conversions->filter(fn (ReferralConversion $conversion) => ($conversion->receipt['workflow_scope'] ?? 'person') !== 'additional_support')->values();
    }

    private function matchesScope(ReferralConversion $conversion, int $siteId, int $contextId): bool
    {
        return (int) ($conversion->receipt['site_id'] ?? 0) === $siteId && (int) ($conversion->receipt['service_context_id'] ?? 0) === $contextId;
    }

    private function respiteScope(Client $client, RespiteBooking $booking): array
    {
        return [(int) ($booking->location_id ?: $client->site_id), (int) ($booking->request?->service_context_id ?: $client->service_context_id)];
    }

    public static function agreementFingerprint(ServiceAgreement $agreement): string
    {
        return app(ReferralAgreementTerms::class)->fingerprint($agreement);
    }
}
