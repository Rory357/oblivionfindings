<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Services\GovernanceRecordAccessService;
use App\Models\User;

class PerformanceReviewPolicy
{
    /**
     * Anyone with the view permission can open the list; which reviews it
     * shows is decided per record by GovernanceRecordAccessService::
     * scopePerformanceReviews (chair, the remuneration/governance/executive
     * committees, people who manage reviews, and the person being reviewed).
     * Other members get an explained empty list, not a 403.
     */
    public function viewAny(User $user): bool
    {
        return $user->canDo('governance.performance.view');
    }

    public function view(User $user, PerformanceReview $review): bool
    {
        if (! $user->canDo('governance.performance.view')) {
            return false;
        }

        return app(GovernanceRecordAccessService::class)->canViewPerformanceReview($user, $review);
    }

    public function create(User $user): bool
    {
        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair');
    }

    public function update(User $user, PerformanceReview $review): bool
    {
        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair');
    }

    public function delete(User $user, PerformanceReview $review): bool
    {
        return $user->hasRole('admin');
    }

    public function assess(User $user, PerformanceReview $review): bool
    {
        return $user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair');
    }

    /**
     * A self-assessment is the reviewee's own account, so only the person
     * being reviewed can send it — nobody submits it on their behalf.
     */
    public function submitSelfAssessment(User $user, PerformanceReview $review): bool
    {
        return (int) $user->id === (int) $review->reviewee_id;
    }
}
