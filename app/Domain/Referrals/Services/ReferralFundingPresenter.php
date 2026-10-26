<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Models\User;
use Illuminate\Support\Arr;

final class ReferralFundingPresenter
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralFundingDecisionService $decisions) {}

    public function cases(User $actor, CareReferral $referral, int $page = 1): array
    {
        $this->access->authorize($actor, $referral, 'funding.view');
        $cases = ReferralFundingCase::query()->with('navigator:id,name')->where('referral_id', $referral->id)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15, ['*'], 'funding_page', $page)->withQueryString()->through(fn ($case) => $this->summary($case));
        $authority = $referral->authority['review'] ?? [];
        $currentSharing = ReferralAssessment::currentSharing($referral);
        $sharing = array_values(array_filter(array_map(fn ($item, $index) => ['index' => $index, ...$item], $authority['sharing'] ?? [], array_keys($authority['sharing'] ?? [])), fn ($item) => in_array(Arr::except($item, ['index']), $currentSharing, true)));

        return ['cases' => $cases, 'authority' => ['assistance_permitted' => ReferralAssessment::scope($referral, 'assist'), 'version' => $authority['version'] ?? 0,
            'sharing' => $this->access->can($actor, 'disclose') ? $sharing : []]];
    }

    private function summary(ReferralFundingCase $case): array
    {
        $next = ReferralWorkItem::query()->with('owner:id,name')->where('referral_id', $case->referral_id)->where('funding_case_id', $case->id)->find($case->details['primary_work_item_id'] ?? 0);
        $latest = ReferralFundingDecision::query()->where('funding_case_id', $case->id)->find($case->details['latest_decision_id'] ?? 0);

        return ['id' => $case->id, 'reference' => $case->reference, 'version' => $case->lock_version, 'pathway' => $case->pathway,
            'pathway_label' => $case->details['pathway_label'] ?? ucfirst(str_replace('_', ' ', $case->pathway)), 'status' => $case->status, 'personal_income' => $case->personal_income,
            'navigator_id' => $case->navigator_id, 'navigator_name' => $case->navigator?->name, 'previous_case_id' => $case->previous_case_id,
            'settings_version_id' => $case->settings_version_id,
            'details' => Arr::only($case->details, ['service_id', 'starting_point', 'assistance_requested', 'person_review_plan', 'guide', 'roles', 'external_contact', 'preparation', 'blocker', 'last_submission', 'submission_count', 'verification', 'person_outcome']),
            'next_action' => $next?->details['title'], 'next_due_at' => $next?->due_at?->toISOString(), 'next_work_status' => $next?->status,
            'latest_decision' => $latest ? ['id' => $latest->id, 'version' => $latest->version, 'outcome' => $latest->outcome, 'recorded_by' => $latest->recorded_by,
                'recorded_at' => $latest->recorded_at->toISOString(), 'terms' => $latest->decision] : null,
            'availability' => $this->decisions->availability($case),
        ];
    }
}
