<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralServiceStart;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/** Explicit response contracts keep encrypted model attributes out of accidental serialization. */
final class ReferralPresenter
{
    /** The published configuration whose hold and close labels this request shows (read once). */
    private ?array $choiceConfiguration = null;

    private bool $choiceConfigurationRead = false;

    public function __construct(private readonly ReferralAccess $access) {}

    private function choiceConfiguration(): ?array
    {
        if (! $this->choiceConfigurationRead) {
            $this->choiceConfiguration = app(ReferralSettings::class)->effective()?->configuration;
            $this->choiceConfigurationRead = true;
        }

        return $this->choiceConfiguration;
    }

    public function summary(CareReferral $referral): array
    {
        $identity = $referral->identity;
        $name = trim(($identity['first_name'] ?? '').' '.($identity['last_name'] ?? ''));

        return [
            'reference' => $referral->reference, 'version' => $referral->lock_version,
            // ENQ- number given to a general enquiry; shown wherever the record appears.
            'enquiry_reference' => $referral->enquiryReference(),
            'is_general_enquiry' => $referral->isGeneralEnquiry(),
            'name' => isset($identity['retention_redacted_at']) ? 'Details removed under the retention policy'
                : ($name !== '' ? $name : 'Enquiry from '.($referral->intake['submitted_contact']['name'] ?? 'an unnamed contact')),
            'preferred_name' => $identity['preferred_name'] ?? null,
            'site_id' => $referral->site_id, 'owner_id' => $referral->owner_id, 'team' => $referral->team,
            'site_name' => $referral->site?->name, 'owner_name' => $referral->owner?->name,
            'stage' => $referral->stage, 'source' => $referral->source, 'requested_service' => $referral->requested_service,
            'priority' => $referral->priority, 'restricted' => $referral->restricted, 'general_enquiry' => $referral->general_enquiry,
            'next_action' => $referral->next_action, 'next_due_at' => $referral->next_due_at?->toISOString(),
            'created_at' => $referral->created_at->toISOString(),
            'first_human_contact_at' => $referral->first_human_contact_at?->toISOString(),
            'hold_reason' => $referral->hold_reason, 'closure_reason' => $referral->closure_reason,
            // Configured labels (Settings › Referrals); historical keys keep a readable fallback.
            'hold_reason_label' => ReferralSettings::choiceLabel($this->choiceConfiguration(), 'hold', $referral->hold_reason),
            'closure_reason_label' => ReferralSettings::choiceLabel($this->choiceConfiguration(), 'close', $referral->closure_reason),
            'priority_label' => ReferralSettings::choiceLabel($this->choiceConfiguration(), 'priority', $referral->priority),
        ];
    }

    public function detail(User $actor, CareReferral $referral, int $workPage = 1, int $activityPage = 1, int $fundingPage = 1): array
    {
        // The boundary applies even when this presenter is called outside its HTTP controller.
        abort_unless($this->access->query($actor)->whereKey($referral->id)->exists(), 404);
        $audiences = array_values(array_filter(['intake', 'assessment', 'funding'], fn ($audience) => $this->access->audience($actor, $audience)));
        // After a governed retention run only the case outline is left; its removed sections read as empty.
        $anonymised = ReferralRetention::anonymised($referral);
        $result = [
            ...$this->summary($referral),
            'workflow_targets' => app(ReferralWorkflow::class)->targets($actor, $referral),
            // Reasons and priorities staff may choose now; turned-off ones stay readable on older records only.
            'choices' => collect(['hold', 'close', 'priority'])->mapWithKeys(fn (string $list) => [$list => array_values(array_map(
                fn (array $item) => ['id' => $item['id'], 'label' => $item['label']],
                array_filter(ReferralSettings::choices($this->choiceConfiguration(), $list), fn (array $item) => $item['enabled']),
            ))])->all(),
            'duplicate_closures' => $anonymised ? [] : app(ReferralDuplicateClosure::class)->present($actor, $referral),
            'form_fields' => $anonymised ? [] : app(ReferralForms::class)->fields($actor, $referral),
            'checklists' => $anonymised ? [] : app(ReferralChecklists::class)->present($actor, $referral),
            'form_answers' => $anonymised ? [] : app(ReferralForms::class)->history($actor, $referral),
            'identity' => Arr::only($referral->identity, ['first_name', 'last_name', 'preferred_name', 'date_of_birth']),
            'contact' => Arr::only($referral->intake['submitted_contact'] ?? [], ['name', 'email', 'phone', 'channel', 'safe_time', 'leave_message', 'communication']),
            'intake' => Arr::only($referral->intake, ['relationship', 'organisation', 'region', 'goals', 'timeframe', 'provenance', 'preparation_reason']),
            // A Contact us message: what it's about, what they wrote and how they asked us to reply.
            'enquiry' => isset($referral->intake['enquiry']) ? Arr::only($referral->intake['enquiry'], ['topic', 'message', 'reply_by']) : null,
            'contact_permission' => ReferralAssessment::scope($referral, 'contact'),
            'can_complete_intake' => $referral->general_enquiry && $this->access->can($actor, 'edit') && ReferralAssessment::scope($referral, 'collect'),
            'primary_work_item_id' => $referral->primary_work_item_id,
            'work_counts' => [
                'open' => ReferralWorkItem::query()->where('referral_id', $referral->id)->whereIn('audience', $audiences)->where('status', 'open')->count(),
                'overdue' => ReferralWorkItem::query()->where('referral_id', $referral->id)->whereIn('audience', $audiences)->where('status', 'open')->where('due_at', '<', now())->count(),
            ],
            'work' => ReferralWorkItem::query()->with(['owner:id,name', 'fundingCase'])->where('referral_id', $referral->id)->whereIn('audience', $audiences)
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderBy('due_at')->orderBy('id')
                ->paginate(50, ['*'], 'work_page', $workPage)->withQueryString()->through(fn ($work) => $this->work($work)),
            'activity' => ReferralEvent::query()->with('actor:id,name')->where('referral_id', $referral->id)->whereIn('audience', $audiences)
                ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50, ['*'], 'activity_page', $activityPage)->withQueryString()->through(fn ($event) => [
                    'reference' => $event->reference, 'kind' => $event->kind, 'actor_id' => $event->actor_id, 'actor_name' => $event->actor?->name,
                    'audience' => $event->audience, 'occurred_at' => $event->occurred_at->toISOString(), 'details' => $event->payload,
                ]),
        ];
        if ($anonymised) {
            $result['retention'] = ['anonymised_at' => ReferralRetention::anonymisedAt($referral)];
        }
        if ($this->access->can($actor, 'assessment.view')) {
            $result['assessment'] = $referral->assessment;
            $result['people_contacts'] = $anonymised ? [] : app(ReferralPeopleContacts::class)->present($actor, $referral);
            $result['authority'] = $referral->authority;
            $result['authority_scopes'] = array_combine(['contact', 'collect', 'assist', 'service'], array_map(fn ($scope) => ReferralAssessment::scope($referral, $scope), ['contact', 'collect', 'assist', 'service']));
            $result['current_sharing'] = ReferralAssessment::currentSharing($referral);
        }
        if ($this->access->can($actor, 'funding.view') && ! $anonymised) {
            $result['funding_intake'] = Arr::only($referral->intake, ['funding_situation', 'funding_agency', 'reported_pathway']);
            $result['funding'] = app(ReferralFundingPresenter::class)->cases($actor, $referral, $fundingPage);
        }
        $result['profile'] = $anonymised ? ['readiness' => null, 'funding' => null, 'service_start' => null] : $this->profile($actor, $referral);

        return $result;
    }

    /**
     * Header and Overview summary for the profile page. Every value is derived from
     * recorded reviews, priced proposal lines and verified funding; nothing is estimated.
     * Each part follows the permission of the section it summarises.
     */
    private function profile(User $actor, CareReferral $referral): array
    {
        $assessment = $this->access->can($actor, 'assessment.view');
        $funding = $this->access->can($actor, 'funding.view');
        $start = null;
        if ($assessment && $funding && $this->access->can($actor, 'commence')) {
            $record = ReferralServiceStart::query()->where('referral_id', $referral->id)->orderByDesc('id')->first();
            $start = $record ? ['status' => $record->status, 'agreed_date' => $record->agreed_date?->toDateString(), 'commenced' => $record->commenced_at !== null] : null;
        }

        return [
            'readiness' => $assessment ? $this->readiness($referral) : null,
            'funding' => $funding ? $this->fundingPosition($referral) : null,
            'service_start' => $start,
        ];
    }

    /** The six handover readiness checks shown on the Overview, in the reviewer's own terms. */
    private function readiness(CareReferral $referral): array
    {
        $assessment = $referral->assessment ?? [];
        $authority = $referral->authority['review'] ?? [];
        $assessed = ! empty($assessment['version']);
        $current = $assessed && empty($assessment['invalidated_at'])
            && ($assessment['authority_version'] ?? null) === ($authority['version'] ?? null)
            && ! empty($assessment['review_due_at']) && CarbonImmutable::parse($assessment['review_due_at'])->isFuture();
        $reviewed = function (string $key, string $label, string $field, array $states) use ($assessment, $assessed, $current): array {
            [$state, $detail] = $states[$assessment[$field] ?? ''] ?? ['to_review', $assessed ? 'Not recorded in the assessment' : 'Not yet assessed'];
            if ($state === 'confirmed' && ! $current) {
                [$state, $detail] = ['to_review', $detail.' · the assessment needs review again'];
            }

            return compact('key', 'label', 'state', 'detail');
        };

        $authorityState = $authority['state'] ?? null;
        [$authorityStatus, $authorityDetail] = match (true) {
            ReferralAssessment::scope($referral, 'collect') && ReferralAssessment::scope($referral, 'service') => ['confirmed', 'Current for collection and the proposed service'],
            in_array($authorityState, ['withdrawn', 'disputed'], true) => ['attention', $authorityState === 'withdrawn' ? 'Authority has been withdrawn' : 'Authority is disputed'],
            (bool) ($referral->authority['review_required'] ?? false) => ['to_review', 'Authority needs review'],
            $authority === [] => ['to_review', 'Not yet reviewed'],
            $authorityState === 'current' => ['to_review', 'Review is due, or collection or service permission is not recorded'],
            default => ['to_review', 'Authority is still being confirmed'],
        };
        [$safeStatus, $safeDetail] = ! (filled($assessment['goals'] ?? null) && filled($assessment['participation'] ?? null) && filled($assessment['safety_needs'] ?? null))
            ? ['to_review', 'Record goals, participation and safety needs']
            : ($current ? ['confirmed', 'Goals, participation and safety needs reviewed'] : ['to_review', 'Goals, participation and safety needs recorded · the assessment needs review again']);

        return [
            $reviewed('identity', 'Identity', 'identity_status', [
                'verified' => ['confirmed', 'Identity verified'], 'disputed' => ['attention', 'Identity is disputed'], 'pending' => ['to_review', 'Identity not yet verified'],
            ]),
            ['key' => 'authority', 'label' => 'Authority & sharing', 'state' => $authorityStatus, 'detail' => $authorityDetail],
            $reviewed('choice', 'Provider choice', 'intention', [
                'proceed' => ['confirmed', 'Wants to go ahead with us'], 'other_provider' => ['attention', 'Prefers another provider'], 'declined' => ['attention', 'Has declined support'],
                'exploring' => ['to_review', 'Still exploring options'], 'to_clarify' => ['to_review', 'To clarify with the person'],
            ]),
            $reviewed('suitability', 'Support suitability', 'suitability', [
                'suitable' => ['confirmed', 'We can provide the support'], 'not_suitable' => ['attention', 'Not suitable for our support'],
                'more_information' => ['to_review', 'More information needed'], 'pending' => ['to_review', 'Not yet decided'],
            ]),
            $reviewed('capacity', 'Capacity', 'capacity', [
                'available' => ['confirmed', 'Capacity available'], 'waitlist' => ['attention', 'On the waitlist'],
                'unavailable' => ['attention', 'No capacity at present'], 'unknown' => ['to_review', 'Capacity not yet known'],
            ]),
            ['key' => 'safe_start', 'label' => 'Safe start', 'state' => $safeStatus, 'detail' => $safeDetail],
        ];
    }

    /**
     * Weekly (or other period) cost of the latest support proposal against the part covered by
     * verified funding allocations or approved company funding. A line without a recorded price
     * is counted as not yet costed, never as zero.
     */
    private function fundingPosition(CareReferral $referral): array
    {
        $cases = ReferralFundingCase::query()->where('referral_id', $referral->id)->get(['id', 'status', 'personal_income', 'details']);
        $open = $cases->reject(fn (ReferralFundingCase $case) => in_array($case->status, ['closed', 'withdrawn'], true));
        $verified = fn (ReferralFundingCase $case) => ! $case->personal_income && ($case->details['verification']['status'] ?? null) === 'verified'
            && ($case->details['verification']['decision_id'] ?? null) === ($case->details['latest_decision_id'] ?? false);
        $proposal = app(ReferralProposals::class)->latest($referral);
        $totals = [];
        if ($proposal) {
            $terms = $proposal->proposal;
            $covered = array_column(app(ReferralProposalCoverage::class)->inspect($referral, $terms)['lines'], 'covered_quantity', 'line_key');
            foreach ($terms['lines'] as $line) {
                // Older or unpriced lines may carry no currency/period; they count as not yet costed, never zero.
                $line['currency'] ??= 'NZD';
                $line['period'] ??= 'week';
                $key = $line['currency'].':'.$line['period'];
                $totals[$key] ??= ['currency' => $line['currency'], 'period' => $line['period'], 'proposed' => BigDecimal::zero(), 'covered' => BigDecimal::zero(), 'unknown_lines' => 0];
                if (! ($line['estimate']['known'] ?? false) || $line['quantity'] === null || BigDecimal::of($line['quantity'])->isZero()) {
                    $totals[$key]['unknown_lines']++;

                    continue;
                }
                $gross = BigDecimal::of($line['estimate']['gross']);
                $requested = BigDecimal::of($line['quantity']);
                $share = BigDecimal::of($covered[$line['key']] ?? '0');
                $share = $share->isGreaterThan($requested) ? $requested : $share;
                $totals[$key]['proposed'] = $totals[$key]['proposed']->plus($gross);
                $totals[$key]['covered'] = $totals[$key]['covered']->plus($gross->multipliedBy($share)->dividedBy($requested, 2, RoundingMode::HALF_UP));
            }
        }
        // Weekly NZD first: the header and Overview show the first position.
        uasort($totals, fn (array $a, array $b) => [$a['currency'] !== 'NZD', $a['period'] !== 'week'] <=> [$b['currency'] !== 'NZD', $b['period'] !== 'week']);

        return [
            'proposal_version' => $proposal?->version,
            'totals' => array_values(array_map(fn (array $total) => [...$total, 'proposed' => (string) $total['proposed']->toScale(2), 'covered' => (string) $total['covered']->toScale(2)], $totals)),
            'cases' => ['total' => $cases->count(), 'open' => $open->count(), 'verified' => $cases->filter($verified)->count(), 'awaiting' => $open->reject($verified)->count()],
        ];
    }

    public function work(ReferralWorkItem $work): array
    {
        return [
            'id' => $work->id, 'reference' => $work->reference, 'version' => $work->lock_version,
            'kind' => $work->kind, 'audience' => $work->audience, 'status' => $work->status,
            'owner_id' => $work->owner_id, 'owner_name' => $work->owner?->name, 'funding_case_id' => $work->funding_case_id,
            'is_primary_funding_action' => $work->funding_case_id && ($work->fundingCase?->details['primary_work_item_id'] ?? null) === $work->id && ! in_array($work->fundingCase?->status, ['withdrawn', 'closed'], true),
            'due_at' => $work->due_at->toISOString(), 'ends_at' => $work->ends_at?->toISOString(),
            'details' => $work->details,
        ];
    }
}
