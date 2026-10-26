<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Staff-reviewed facts remain distinct from the original website declarations. */
final class ReferralAssessment
{
    public function __construct(private readonly ReferralCommand $command) {}

    public function authority(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'state' => ['required', Rule::in(['pending', 'current', 'withdrawn', 'disputed'])],
            'basis' => ['required', Rule::in(['self', 'supported_decision', 'representative', 'other_lawful_basis', 'to_clarify'])],
            'basis_note' => 'required|string|min:5|max:4000', 'representative' => 'present|nullable|string|max:300',
            'participation' => 'required|string|min:5|max:2000', 'review_due_at' => 'required|date',
            'scopes' => 'required|array:contact,collect,assist,service',
            'scopes.contact' => 'required|boolean', 'scopes.collect' => 'required|boolean',
            'scopes.assist' => 'required|boolean', 'scopes.service' => 'required|boolean',
            'notice' => ['required', Rule::in(['notice_given', 'direct_collection', 'pending_review', 'exception_reviewed'])],
            'notice_note' => 'required|string|min:5|max:2000', 'reason' => 'required|string|min:5|max:2000',
            'sharing' => 'present|array|max:20', 'sharing.*' => 'required|array:recipient,purpose,information,expires_at',
            'sharing.*.recipient' => 'required|string|min:3|max:300', 'sharing.*.purpose' => 'required|string|min:5|max:1000',
            'sharing.*.information' => 'required|string|min:5|max:1000', 'sharing.*.expires_at' => 'required|date',
        ])->validate();

        $this->command->run($actor, $id, $version, 'assessment.manage', function (CareReferral $referral, User $actor) use ($data): void {
            $this->editable($referral);
            $due = ReferralDates::parse($data['review_due_at'], 'review_due_at');
            abort_if($due->isPast(), 422, 'Choose a future review date.');
            abort_if($data['state'] === 'current' && $data['basis'] === 'to_clarify', 422, 'Clarify the basis before recording current authority.');
            abort_if($data['basis'] === 'representative' && empty($data['representative']), 422, 'Record the representative and their verified authority.');
            abort_if($data['notice'] === 'direct_collection' && ($referral->intake['relationship'] ?? '') !== 'self', 422, 'Third-party intake requires a notice review; it is not direct collection.');
            if ($data['notice'] === 'exception_reviewed') {
                abort_unless($actor->canDo('privacy.processRequests'), 403, 'A staff member with privacy review permission must record a notice exception.');
            }
            $current = $data['state'] === 'current';
            abort_if(! $current && (array_filter($data['scopes']) !== [] || $data['sharing'] !== []), 422, 'Pending, withdrawn or disputed authority cannot grant new permissions. Clear the permissions and record the required review.');
            $sharing = [];
            foreach ($data['sharing'] as $index => $item) {
                $expiry = ReferralDates::parse($item['expires_at'], 'sharing.'.$index.'.expires_at');
                abort_if($expiry->isPast() || $expiry->gt($due), 422, 'A sharing permission must expire in the future and no later than the authority review.');
                $sharing[] = [...$item, 'expires_at' => $expiry->toISOString()];
            }
            $previous = $referral->authority;
            $review = [...$data, 'sharing' => $sharing, 'review_due_at' => $due->toISOString(),
                'version' => (int) ($previous['review']['version'] ?? 0) + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toISOString()];
            $referral->authority = [...$previous, 'review' => $review, 'review_required' => false, 'staff_review' => $data['state'], 'contact_permission' => $current && $data['scopes']['contact']];
            $this->invalidateReadiness($referral, $actor, 'authority');
            $this->command->record($referral, $actor, 'authority.reviewed', 'assessment', ['previous' => $previous, 'current' => $review, 'reason' => $data['reason']]);
        }, ['privacy.processRequests']);
    }

    public function assess(User $actor, int $id, int $version, array $input): void
    {
        $data = Validator::make($input, [
            'identity_status' => ['required', Rule::in(['pending', 'verified', 'disputed'])],
            'identity_note' => 'required|string|min:5|max:2000',
            'intention' => ['required', Rule::in(['exploring', 'proceed', 'other_provider', 'declined', 'to_clarify'])],
            'intention_note' => 'required|string|min:5|max:2000',
            'suitability' => ['required', Rule::in(['pending', 'suitable', 'not_suitable', 'more_information'])],
            'suitability_note' => 'required|string|min:5|max:4000',
            'capacity' => ['required', Rule::in(['unknown', 'available', 'waitlist', 'unavailable'])],
            'capacity_note' => 'required|string|min:5|max:2000',
            'goals' => 'required|string|min:5|max:4000', 'participation' => 'required|string|min:5|max:2000',
            'safety_needs' => 'required|string|min:5|max:4000', 'review_due_at' => 'required|date', 'reason' => 'required|string|min:5|max:2000',
        ])->validate();
        $this->command->run($actor, $id, $version, 'assessment.manage', function (CareReferral $referral, User $actor) use ($data): void {
            $this->editable($referral);
            abort_if($referral->general_enquiry, 422, 'Review authority and complete the person’s intake before recording a person assessment.');
            abort_unless(self::scope($referral, 'collect'), 422, 'Review the basis for collecting assessment information first.');
            $due = ReferralDates::parse($data['review_due_at'], 'review_due_at');
            abort_if($due->isPast(), 422, 'Choose a future assessment review date.');
            $previous = $referral->assessment;
            $review = [...$data, 'version' => (int) ($previous['version'] ?? 0) + 1,
                'authority_version' => $referral->authority['review']['version'], 'review_due_at' => $due->toISOString(),
                'reviewed_by' => $actor->id, 'reviewed_at' => now()->toISOString()];
            $referral->assessment = $review;
            $this->invalidateReadiness($referral, $actor, 'assessment');
            $this->command->record($referral, $actor, 'assessment.reviewed', 'assessment', ['previous' => $previous, 'current' => $review, 'reason' => $data['reason']]);
        });
    }

    public static function scope(CareReferral $referral, string $scope): bool
    {
        if ($referral->authority['review_required'] ?? false) {
            return false;
        }
        $review = $referral->authority['review'] ?? null;
        if (! $review) {
            // The original collection declaration permits only the requested callback, not assistance or sharing.
            return $scope === 'contact' && ($referral->authority['contact_permission'] ?? false);
        }

        return ($review['state'] ?? '') === 'current' && ! empty($review['review_due_at'])
            && CarbonImmutable::parse($review['review_due_at'])->isFuture() && ($review['scopes'][$scope] ?? false);
    }

    public static function currentSharing(CareReferral $referral): array
    {
        $review = $referral->authority['review'] ?? [];
        if (($referral->authority['review_required'] ?? false) || ($review['state'] ?? '') !== 'current' || empty($review['review_due_at']) || ! CarbonImmutable::parse($review['review_due_at'])->isFuture()) {
            return [];
        }

        return array_values(array_filter($review['sharing'] ?? [], fn ($item) => ! empty($item['expires_at']) && CarbonImmutable::parse($item['expires_at'])->isFuture()));
    }

    private function editable(CareReferral $referral): void
    {
        abort_if($referral->stage === 'closed', 422, 'Reopen the referral before recording a new review.');
    }

    /** Internal prerequisite-change hook; caller must hold the referral command transaction. */
    public function invalidateReadiness(CareReferral $referral, User $actor, string $source): void
    {
        if ($referral->stage === 'ready_for_handover') {
            $referral->fill(['stage' => 'assessment', 'stage_changed_at' => now()]);
            $this->command->record($referral, $actor, 'readiness.review_required', 'intake', ['reason' => 'A prerequisite review changed. Readiness must be checked again.']);
        }
        $start = ReferralServiceStart::query()->where('referral_id', $referral->id)->lockForUpdate()->first();
        if ($start) {
            // An active person's service is never silently stopped by a referral review.
            $previous = ['status' => $start->status, 'approved_by' => $start->approved_by, 'agreed_date' => $start->agreed_date?->toDateString()];
            $reviewWork = ReferralWorkItem::query()->where('referral_id', $referral->id)->where('status', 'open')
                ->whereKey($start->handover['review_work_item_id'] ?? 0)->lockForUpdate()->first();
            if (! $reviewWork) {
                $reviewWork = ReferralWorkItem::query()->create(['referral_id' => $referral->id, 'owner_id' => $actor->id, 'kind' => 'internal_review', 'audience' => 'intake',
                    'details' => ['title' => 'Arrange service readiness review', 'description' => 'Arrange an authorized manager review before relying on the previous service-start approval. The source evidence remains in its permitted audience.'], 'due_at' => now()->addDay()]);
            }
            $start->update(['status' => $start->commenced_at ? $start->status : 'review_required',
                'approved_by' => $start->commenced_at ? $start->approved_by : null, 'lock_version' => $start->lock_version + 1,
                'handover' => [...($start->handover ?? []), 'review_work_item_id' => $reviewWork->id, 'review_required' => true, 'review_source' => $source, 'review_required_at' => now()->toISOString()]]);
            $this->command->record($referral, $actor, 'service_start.review_required', 'assessment', ['previous' => $previous, 'reason' => 'The '.$source.' review changed.']);
        }
    }
}
