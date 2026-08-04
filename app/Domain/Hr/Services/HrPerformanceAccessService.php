<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrCompensationReviewItem;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPipMilestone;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Canonical subject and Site boundary for sensitive performance records.
 *
 * Historical records remain visible when their subject retains provenance at
 * an accessible Site. New assignments and picker options require current,
 * approved staff. Missing provenance fails closed.
 */
class HrPerformanceAccessService
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @return Builder<*> */
    public function applyHistoricalSubjectScope(
        Builder $query,
        User $viewer,
        string $subjectColumn = 'employee_user_id',
    ): Builder {
        return $query->whereIn(
            $query->qualifyColumn($subjectColumn),
            $this->historicalUserIds($viewer),
        );
    }

    /** @return Builder<*> */
    public function applyCurrentSubjectScope(
        Builder $query,
        User $viewer,
        string $subjectColumn = 'employee_user_id',
    ): Builder {
        return $query->whereIn(
            $query->qualifyColumn($subjectColumn),
            $this->currentUserIds($viewer),
        );
    }

    /** @return Builder<HrEmployeeProfile> */
    public function applyHistoricalProfileScope(Builder $query, User $viewer): Builder
    {
        return $query->whereIn($query->qualifyColumn('user_id'), $this->historicalUserIds($viewer));
    }

    /** @return Builder<HrEmployeeProfile> */
    public function applyCurrentProfileScope(Builder $query, User $viewer): Builder
    {
        return $query->whereIn($query->qualifyColumn('user_id'), $this->currentUserIds($viewer));
    }

    /** @return Builder<User> */
    public function historicalUserIds(User $viewer): Builder
    {
        return $this->siteAccess->applyHistoricalStaffSiteScope(
            User::query()->select('users.id'),
            $viewer,
        );
    }

    /** @return Builder<User> */
    public function currentUserIds(User $viewer): Builder
    {
        return $this->siteAccess->applyStaffScope(
            User::query()->select('users.id'),
            $viewer,
        );
    }

    public function currentStaff(User $viewer, User|int $staff): User
    {
        $staffId = $staff instanceof User ? $staff->getKey() : $staff;

        return $this->siteAccess
            ->applyStaffScope(User::query(), $viewer)
            ->findOrFail($staffId);
    }

    /** @return Builder<HrBenefitEnrollment> */
    public function applyBenefitEnrollmentScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'employeeProfile',
            fn (Builder $profileQuery) => $this->applyHistoricalProfileScope($profileQuery, $viewer),
        );
    }

    public function benefitEnrollment(
        User $viewer,
        HrBenefitEnrollment|int $enrollment,
    ): HrBenefitEnrollment {
        $enrollmentId = $enrollment instanceof HrBenefitEnrollment
            ? $enrollment->getKey()
            : $enrollment;

        return $this->applyBenefitEnrollmentScope(HrBenefitEnrollment::query(), $viewer)
            ->findOrFail($enrollmentId);
    }

    /** @return Builder<HrBonusPayment> */
    public function applyBonusScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'employeeProfile',
            fn (Builder $profileQuery) => $this->applyHistoricalProfileScope($profileQuery, $viewer),
        );
    }

    public function bonusPayment(User $viewer, HrBonusPayment|int $bonus): HrBonusPayment
    {
        $bonusId = $bonus instanceof HrBonusPayment ? $bonus->getKey() : $bonus;

        return $this->applyBonusScope(HrBonusPayment::query(), $viewer)
            ->findOrFail($bonusId);
    }

    /** @return Builder<HrExpenseClaim> */
    public function applyExpenseClaimScope(Builder $query, User $viewer): Builder
    {
        return $this->applyHistoricalSubjectScope($query, $viewer, 'user_id');
    }

    public function expenseClaim(User $viewer, HrExpenseClaim|int $claim): HrExpenseClaim
    {
        $claimId = $claim instanceof HrExpenseClaim ? $claim->getKey() : $claim;

        return $this->applyExpenseClaimScope(HrExpenseClaim::query(), $viewer)
            ->findOrFail($claimId);
    }

    /** @return Builder<HrPayslip> */
    public function applyPayslipScope(Builder $query, User $viewer): Builder
    {
        return $this->applyHistoricalSubjectScope($query, $viewer, 'user_id');
    }

    public function payslip(User $viewer, HrPayslip|int $payslip): HrPayslip
    {
        $payslipId = $payslip instanceof HrPayslip ? $payslip->getKey() : $payslip;

        return $this->applyPayslipScope(HrPayslip::query(), $viewer)
            ->findOrFail($payslipId);
    }

    /** @return Builder<HrCompensationHistory> */
    public function applyCompensationHistoryScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'employeeProfile',
            fn (Builder $profileQuery) => $this->applyHistoricalProfileScope($profileQuery, $viewer),
        );
    }

    /** @return Builder<HrCompensationReviewItem>|Relation<HrCompensationReviewItem, *, *> */
    public function applyCompensationReviewItemScope(Builder|Relation $query, User $viewer): Builder|Relation
    {
        return $query->whereHas(
            'employeeProfile',
            fn (Builder $profileQuery) => $this->applyHistoricalProfileScope($profileQuery, $viewer),
        );
    }

    /** @return Builder<HrCompensationReview> */
    public function applyCompensationReviewScope(Builder $query, User $viewer): Builder
    {
        return $query->where(function (Builder $visible) use ($viewer): void {
            $visible->whereHas(
                'items.employeeProfile',
                fn (Builder $profileQuery) => $this->applyHistoricalProfileScope($profileQuery, $viewer),
            )->orWhere(function (Builder $ownedEmpty) use ($viewer): void {
                $ownedEmpty
                    ->where('created_by', $viewer->getKey())
                    ->whereDoesntHave('items');
            });
        });
    }

    public function compensationReview(
        User $viewer,
        HrCompensationReview|int $review,
    ): HrCompensationReview {
        $reviewId = $review instanceof HrCompensationReview ? $review->getKey() : $review;

        return $this->applyCompensationReviewScope(HrCompensationReview::query(), $viewer)
            ->findOrFail($reviewId);
    }

    public function compensationReviewItem(
        User $viewer,
        HrCompensationReviewItem|int $item,
    ): HrCompensationReviewItem {
        $itemId = $item instanceof HrCompensationReviewItem ? $item->getKey() : $item;

        return $this->applyCompensationReviewItemScope(HrCompensationReviewItem::query(), $viewer)
            ->findOrFail($itemId);
    }

    /** @return Builder<HrCompetencyAssessment> */
    public function applyCompetencyAssessmentScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'employeeProfile',
            fn (Builder $profileQuery) => $this->applyHistoricalProfileScope($profileQuery, $viewer),
        );
    }

    public function competencyAssessment(
        User $viewer,
        HrCompetencyAssessment|int $assessment,
    ): HrCompetencyAssessment {
        $assessmentId = $assessment instanceof HrCompetencyAssessment
            ? $assessment->getKey()
            : $assessment;

        return $this->applyCompetencyAssessmentScope(HrCompetencyAssessment::query(), $viewer)
            ->findOrFail($assessmentId);
    }

    /** @return Builder<HrFeedbackRequest> */
    public function applyFeedbackSubjectScope(Builder $query, User $viewer): Builder
    {
        return $this->applyHistoricalSubjectScope($query, $viewer, 'subject_user_id');
    }

    public function feedbackRequest(
        User $viewer,
        HrFeedbackRequest|int $feedbackRequest,
    ): HrFeedbackRequest {
        $requestId = $feedbackRequest instanceof HrFeedbackRequest
            ? $feedbackRequest->getKey()
            : $feedbackRequest;

        return $this->applyFeedbackSubjectScope(HrFeedbackRequest::query(), $viewer)
            ->findOrFail($requestId);
    }

    public function supervisionNote(User $viewer, HrSupervisionNote|int $note): HrSupervisionNote
    {
        $noteId = $note instanceof HrSupervisionNote ? $note->getKey() : $note;

        return $this->applyHistoricalSubjectScope(HrSupervisionNote::query(), $viewer)
            ->findOrFail($noteId);
    }

    public function performanceReview(User $viewer, HrPerformanceReview|int $review): HrPerformanceReview
    {
        $reviewId = $review instanceof HrPerformanceReview ? $review->getKey() : $review;

        return $this->applyHistoricalSubjectScope(HrPerformanceReview::query(), $viewer)
            ->findOrFail($reviewId);
    }

    public function probationReview(User $viewer, HrProbationReview|int $review): HrProbationReview
    {
        $reviewId = $review instanceof HrProbationReview ? $review->getKey() : $review;

        return $this->applyHistoricalSubjectScope(HrProbationReview::query(), $viewer)
            ->findOrFail($reviewId);
    }

    public function performanceImprovementPlan(
        User $viewer,
        HrPerformanceImprovementPlan|int $pip,
    ): HrPerformanceImprovementPlan {
        $pipId = $pip instanceof HrPerformanceImprovementPlan ? $pip->getKey() : $pip;

        return $this->applyHistoricalSubjectScope(HrPerformanceImprovementPlan::query(), $viewer)
            ->findOrFail($pipId);
    }

    /** @return Builder<HrPipMilestone> */
    public function applyPipMilestoneScope(Builder $query, User $viewer): Builder
    {
        return $query->whereHas(
            'pip',
            fn (Builder $pipQuery) => $this->applyHistoricalSubjectScope($pipQuery, $viewer),
        );
    }

    public function pipMilestone(User $viewer, HrPipMilestone|int $milestone): HrPipMilestone
    {
        $milestoneId = $milestone instanceof HrPipMilestone ? $milestone->getKey() : $milestone;

        return $this->applyPipMilestoneScope(HrPipMilestone::query(), $viewer)
            ->findOrFail($milestoneId);
    }
}
