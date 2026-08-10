<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrCompensationReviewItem;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Notifications\CompensationAppliedNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CompensationService
{
    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * Record a compensation change for an employee and update their profile.
     */
    public function recordCompensationChange(HrEmployeeProfile $profile, array $data): HrCompensationHistory
    {
        return DB::transaction(function () use ($profile, $data) {
            $history = HrCompensationHistory::create([
                'employee_profile_id' => $profile->id,
                'change_type' => $data['change_type'],
                'previous_hourly_rate' => $profile->hourly_rate,
                'new_hourly_rate' => $data['new_hourly_rate'],
                'previous_annual_salary' => $profile->annual_salary,
                'new_annual_salary' => $data['new_annual_salary'],
                'change_percentage' => $data['change_percentage'] ?? null,
                'reason' => $data['reason'] ?? null,
                'effective_date' => $data['effective_date'],
                'approved_by' => $data['approved_by'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $profile->update([
                'hourly_rate' => $data['new_hourly_rate'],
                'annual_salary' => $data['new_annual_salary'],
            ]);

            return $history;
        });
    }

    /**
     * Get the active application salary band for a given role.
     */
    public function getSalaryBandForRole(string $role): ?HrSalaryBand
    {
        return HrSalaryBand::query()->where('position_role', $role)
            ->active()
            ->first();
    }

    /**
     * Compute where a salary sits within a band: compa-ratio (pay ÷ midpoint) and
     * a position bucket (under / in / over). Salary fields are encrypted, so this
     * runs in PHP rather than SQL. Prefers annual salary against the salary range;
     * falls back to the hourly rate against the hourly range when annual is absent.
     *
     * @return array{compa_ratio: float|null, position: 'under'|'in'|'over'|null}
     */
    public function bandPlacement(HrEmployeeProfile $profile, HrSalaryBand $band): array
    {
        $annual = $profile->annual_salary !== null ? (float) $profile->annual_salary : null;
        $hourly = $profile->hourly_rate !== null ? (float) $profile->hourly_rate : null;

        if ($annual !== null && $annual > 0) {
            return $this->placeWithin(
                $annual,
                (float) $band->min_salary,
                (float) $band->mid_salary,
                (float) $band->max_salary,
            );
        }

        if ($hourly !== null && $hourly > 0) {
            $minH = (float) $band->min_hourly;
            $maxH = (float) $band->max_hourly;

            return $this->placeWithin($hourly, $minH, ($minH + $maxH) / 2, $maxH);
        }

        return ['compa_ratio' => null, 'position' => null];
    }

    /**
     * @return array{compa_ratio: float|null, position: 'under'|'in'|'over'|null}
     */
    private function placeWithin(float $pay, float $min, float $mid, float $max): array
    {
        $compa = $mid > 0 ? round($pay / $mid, 4) : null;

        $position = $pay < $min ? 'under' : ($pay > $max ? 'over' : 'in');

        return ['compa_ratio' => $compa, 'position' => $position];
    }

    /**
     * Canonical compensation hero for the current application and the viewer's
     * complete Site-visible staff population.
     *
     * @param  Collection<int, HrEmployeeProfile>|null  $employees
     * @return array<string, int|float>
     */
    public function heroStatsFor(User $user, ?Collection $employees = null): array
    {
        if (! $user->canDo('hr.compensation.view')) {
            return [
                'bands_total' => 0,
                'roles_covered' => 0,
                'people_placed' => 0,
                'people_in_band' => 0,
                'people_out_of_band' => 0,
                'band_health' => 100,
                ...$this->hubAggregatesFor($user),
            ];
        }

        $employees ??= $this->access
            ->applyCurrentProfileScope(HrEmployeeProfile::query(), $user)
            ->get(['id', 'position_role', 'annual_salary', 'hourly_rate']);

        $activeBands = HrSalaryBand::query()->active()
            ->orderByDesc('effective_from')
            ->get();
        $activeByRole = $activeBands->groupBy('position_role');

        $placed = 0;
        $outOfBand = 0;
        foreach ($employees->groupBy('position_role') as $role => $people) {
            $band = $activeByRole->get($role)?->first();
            if (! $band) {
                continue;
            }
            foreach ($people as $profile) {
                $position = $this->bandPlacement($profile, $band)['position'];
                if ($position === null) {
                    continue;
                }
                $placed++;
                if ($position !== 'in') {
                    $outOfBand++;
                }
            }
        }

        return [
            'bands_total' => $activeBands->count(),
            'roles_covered' => $activeByRole->keys()->filter()->count(),
            'people_placed' => $placed,
            'people_in_band' => max(0, $placed - $outOfBand),
            'people_out_of_band' => $outOfBand,
            'band_health' => $placed === 0
                ? 100
                : (int) round((($placed - $outOfBand) / $placed) * 100),
            ...$this->hubAggregatesFor($user),
        ];
    }

    /** @return array<string, int|float> */
    public function hubAggregatesFor(User $user): array
    {
        $reviewsInFlight = $user->canDo('hr.compensation.view')
            ? $this->visibleCompensationReviews($user)
                ->whereIn('status', ['planning', 'in_progress', 'approved'])
                ->count()
            : 0;
        $awaitingClaims = $user->canDo('hr.expenses.approve')
            ? $this->visibleExpenseClaims($user)->where('status', 'submitted')->count()
            : 0;
        $pendingBonuses = $user->canDo('hr.compensation.manage')
            ? $this->visibleBonuses($user)->where('status', 'pending')->count()
            : 0;
        $monthStart = Carbon::now(config('app.worker_timezone'))->startOfMonth()->utc();
        $reimbursed = $user->canDo('hr.expenses.view')
            ? (float) $this->visibleExpenseClaims($user)
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $monthStart)
                ->sum('total_amount')
            : 0.0;
        $claimsOverdue = $user->canDo('hr.expenses.view')
            ? $this->visibleExpenseClaims($user)
                ->where('status', 'submitted')
                ->where('submitted_at', '<', Carbon::now()->subDays(7))
                ->count()
            : 0;

        return [
            'reviews_in_flight' => $reviewsInFlight,
            'awaiting_approval' => $awaitingClaims + $pendingBonuses,
            'reimbursed_this_month' => round($reimbursed, 2),
            'claims_overdue' => $claimsOverdue,
        ];
    }

    /** @return array<string, int> */
    public function tabCountsFor(User $user): array
    {
        return [
            'bands' => $user->canDo('hr.compensation.view') ? HrSalaryBand::query()->active()->count() : 0,
            'reviews' => $user->canDo('hr.compensation.view')
                ? $this->visibleCompensationReviews($user)->count()
                : 0,
            'bonuses' => $user->canDo('hr.compensation.view')
                ? $this->visibleBonuses($user)->count()
                : 0,
            'benefits' => $user->canDo('hr.benefits.view')
                ? $this->access->applyBenefitEnrollmentScope(HrBenefitEnrollment::query(), $user)->active()->count()
                : 0,
            'expenses' => $user->canDo('hr.expenses.view')
                ? $this->visibleExpenseClaims($user)->count()
                : 0,
        ];
    }

    /** @return Builder<HrCompensationReview> */
    public function visibleCompensationReviews(User $user): Builder
    {
        return $this->access->applyCompensationReviewScope(HrCompensationReview::query(), $user);
    }

    /** @return Builder<HrBonusPayment> */
    public function visibleBonuses(User $user): Builder
    {
        return $this->access->applyBonusScope(HrBonusPayment::query(), $user);
    }

    /** @return Builder<HrCompensationHistory> */
    public function visibleCompensationHistory(User $user): Builder
    {
        return $this->access->applyCompensationHistoryScope(HrCompensationHistory::query(), $user);
    }

    /** @return Builder<HrExpenseClaim> */
    public function visibleExpenseClaims(User $user): Builder
    {
        return $this->access->applyExpenseClaimScope(HrExpenseClaim::query(), $user);
    }

    /**
     * Create a new compensation review cycle.
     */
    public function createCompensationReview(array $data, User $actor): HrCompensationReview
    {
        return DB::transaction(function () use ($data, $actor): HrCompensationReview {
            $this->access->currentStaff($actor, $actor);
            $items = collect($data['items'] ?? []);
            $profileIds = $items
                ->pluck('employee_profile_id')
                ->map(fn ($id): int => (int) $id)
                ->values();
            if ($profileIds->unique()->count() !== $profileIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Each employee can appear only once in a compensation review.',
                ]);
            }

            $profiles = $this->access
                ->applyCurrentProfileScope(HrEmployeeProfile::query(), $actor)
                ->whereKey($profileIds->all())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($profiles->count() !== $profileIds->count()) {
                throw (new ModelNotFoundException)->setModel(HrEmployeeProfile::class);
            }

            $review = HrCompensationReview::create([
                'title' => $data['title'],
                'review_cycle' => $data['review_cycle'],
                'effective_date' => $data['effective_date'],
                'status' => $data['status'] ?? 'planning',
                'budget_amount' => $data['budget_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            foreach ($items as $item) {
                $profile = $profiles->get((int) $item['employee_profile_id']);
                $currentSalary = (float) ($profile->annual_salary ?? 0);
                $proposedSalary = (float) $item['proposed_salary'];
                $review->items()->create([
                    'employee_profile_id' => $profile->id,
                    'current_salary' => $currentSalary,
                    'proposed_salary' => $proposedSalary,
                    'change_percentage' => $currentSalary > 0
                        ? round((($proposedSalary - $currentSalary) / $currentSalary) * 100, 2)
                        : 0,
                    'justification' => $item['justification'] ?? null,
                    'status' => 'pending',
                ]);
            }

            return $review->load('items');
        }, attempts: 1);
    }

    /**
     * Approve a compensation review: mark its pending line-items approved and flip
     * the review to 'approved' so it becomes eligible for applyCompensationReview().
     */
    public function approveCompensationReview(HrCompensationReview $review, User $actor): void
    {
        DB::transaction(function () use ($review, $actor): void {
            [$locked, $items] = $this->lockFullyVisibleReview($review, $actor);
            if (! in_array($locked->status, ['planning', 'in_progress'], true)) {
                throw new \LogicException("Cannot approve a '{$locked->status}' compensation review. Only planning or in-progress reviews can be approved.");
            }
            if ($items->isEmpty()) {
                throw new \LogicException('A compensation review must contain at least one employee before approval.');
            }

            // Update each pending item through the model so the change is audited.
            $items
                ->where('status', 'pending')
                ->each(function (HrCompensationReviewItem $item) use ($actor): void {
                    $item->update([
                        'status' => 'approved',
                        'approved_by' => $actor->id,
                    ]);
                });

            // The reviews table has no approved_by column — approver attribution
            // lives on each line-item; the review only tracks its status.
            $locked->update(['status' => 'approved']);
        }, attempts: 1);
    }

    /**
     * Approve a single review line-item (pending → approved). Lets a reviewer
     * sign off lines individually before approving the whole review; apply only
     * touches approved items.
     */
    public function approveReviewItem(
        HrCompensationReview $review,
        HrCompensationReviewItem $item,
        User $actor,
    ): void {
        DB::transaction(function () use ($review, $item, $actor): void {
            $this->access->currentStaff($actor, $actor);
            $lockedReview = $this->access
                ->applyCompensationReviewScope(HrCompensationReview::query(), $actor)
                ->lockForUpdate()
                ->findOrFail($review->getKey());
            $lockedItem = $this->access
                ->applyCompensationReviewItemScope(HrCompensationReviewItem::query(), $actor)
                ->where('compensation_review_id', $lockedReview->id)
                ->lockForUpdate()
                ->findOrFail($item->getKey());

            if ($lockedItem->status !== 'pending') {
                throw new \LogicException("Only a pending line can be approved (this one is '{$lockedItem->status}').");
            }

            $lockedItem->update(['status' => 'approved', 'approved_by' => $actor->id]);
        }, attempts: 1);
    }

    /**
     * Reject a single review line-item (pending → rejected) so it is excluded
     * from apply. The reason is recorded on the line's justification trail.
     */
    public function rejectReviewItem(
        HrCompensationReview $review,
        HrCompensationReviewItem $item,
        User $actor,
        ?string $reason = null,
    ): void {
        DB::transaction(function () use ($review, $item, $actor, $reason): void {
            $this->access->currentStaff($actor, $actor);
            $lockedReview = $this->access
                ->applyCompensationReviewScope(HrCompensationReview::query(), $actor)
                ->lockForUpdate()
                ->findOrFail($review->getKey());
            $lockedItem = $this->access
                ->applyCompensationReviewItemScope(HrCompensationReviewItem::query(), $actor)
                ->where('compensation_review_id', $lockedReview->id)
                ->lockForUpdate()
                ->findOrFail($item->getKey());

            if ($lockedItem->status !== 'pending') {
                throw new \LogicException("Only a pending line can be rejected (this one is '{$lockedItem->status}').");
            }

            $lockedItem->update([
                'status' => 'rejected',
                'approved_by' => $actor->id,
                'justification' => $reason !== null && $reason !== ''
                    ? trim(($lockedItem->justification ? $lockedItem->justification."\n" : '')."Rejected: {$reason}")
                    : $lockedItem->justification,
            ]);
        }, attempts: 1);
    }

    /**
     * Apply an approved compensation review: bulk-update profiles and create history entries.
     */
    public function applyCompensationReview(HrCompensationReview $review, User $actor): void
    {
        $applied = [];

        DB::transaction(function () use ($review, $actor, &$applied): void {
            [$locked, $items] = $this->lockFullyVisibleReview($review, $actor);
            if ($locked->status !== 'approved') {
                throw new \LogicException("Cannot apply a '{$locked->status}' compensation review. It must be approved first.");
            }
            $approvedItems = $items->where('status', 'approved');
            if ($approvedItems->isEmpty()) {
                throw new \LogicException('A compensation review must contain at least one approved employee before it can be applied.');
            }

            foreach ($approvedItems as $item) {
                $profile = $this->access
                    ->applyCurrentProfileScope(HrEmployeeProfile::query(), $actor)
                    ->lockForUpdate()
                    ->findOrFail($item->employee_profile_id);

                // proposed_salary is an ANNUAL figure (the builder seeds it from
                // annual_salary and places it against the band's annual range).
                // Derive the hourly rate from contracted hours rather than writing
                // the annual amount straight into hourly_rate (the old bug).
                $proposedAnnual = (float) $item->proposed_salary;
                $weeklyHours = (float) ($profile->hours_per_week ?? 0);
                $annualHours = $weeklyHours > 0 ? $weeklyHours * 52 : 2080; // default FT year
                $newHourly = $annualHours > 0
                    ? round($proposedAnnual / $annualHours, 2)
                    : $profile->hourly_rate;

                $this->recordCompensationChange($profile, [
                    'change_type' => 'review',
                    'new_hourly_rate' => $newHourly,
                    'new_annual_salary' => $proposedAnnual,
                    'change_percentage' => $item->change_percentage,
                    'reason' => $item->justification ?? "Applied from compensation review: {$locked->title}",
                    'effective_date' => $locked->effective_date,
                    'approved_by' => $item->approved_by,
                    'created_by' => $locked->created_by,
                ]);

                $applied[] = [
                    'user_id' => $profile->user_id,
                    'annual' => $proposedAnnual,
                    'pct' => $item->change_percentage !== null ? (float) $item->change_percentage : null,
                ];
            }

            $locked->update(['status' => 'applied']);
        }, attempts: 1);

        // Pay changes carry a statutory expectation of notice — tell each
        // affected employee after commit (best-effort).
        foreach ($applied as $change) {
            $employee = $change['user_id'] ? User::find($change['user_id']) : null;
            if (! $employee) {
                continue;
            }
            try {
                $employee->notify(new CompensationAppliedNotification(
                    $change['annual'],
                    $review->effective_date?->toDateString(),
                    $change['pct'],
                ));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send compensation-applied notification', [
                    'review_id' => $review->id,
                    'user_id' => $change['user_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array{HrCompensationReview, Collection<int, HrCompensationReviewItem>}
     */
    private function lockFullyVisibleReview(HrCompensationReview $review, User $actor): array
    {
        $this->access->currentStaff($actor, $actor);
        $locked = $this->access
            ->applyCompensationReviewScope(HrCompensationReview::query(), $actor)
            ->lockForUpdate()
            ->findOrFail($review->getKey());
        $items = HrCompensationReviewItem::query()
            ->where('compensation_review_id', $locked->id)
            ->lockForUpdate()
            ->get();
        $visibleItemIds = $this->access
            ->applyCompensationReviewItemScope(HrCompensationReviewItem::query(), $actor)
            ->where('compensation_review_id', $locked->id)
            ->pluck('id');
        if ($visibleItemIds->count() !== $items->count()) {
            throw (new ModelNotFoundException)->setModel(HrCompensationReview::class);
        }

        return [$locked, $items];
    }
}
