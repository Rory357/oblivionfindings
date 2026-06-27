<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CompensationService
{
    /**
     * Record a compensation change for an employee and update their profile.
     */
    public function recordCompensationChange(HrEmployeeProfile $profile, array $data): HrCompensationHistory
    {
        return DB::transaction(function () use ($profile, $data) {
            $history = HrCompensationHistory::create([
                'tenant_id' => $profile->tenant_id,
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
     * Get the active salary band for a given role within a tenant.
     */
    public function getSalaryBandForRole(?int $tenantId, string $role): ?HrSalaryBand
    {
        return HrSalaryBand::forTenant($tenantId)
            ->where('position_role', $role)
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
     * Full hub-hero stat set (band-health placement + cross-hub aggregates),
     * shared by every Compensation & Benefits surface so the hero is identical
     * across the hub. Salary fields are encrypted → placement runs in PHP.
     *
     * @return array<string, int|float>
     */
    public function heroStats(int $tenantId, User $user): array
    {
        $employees = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->active()
            ->get(['id', 'position_role', 'annual_salary', 'hourly_rate']);

        $activeBands = HrSalaryBand::query()->forTenant($tenantId)->active()
            ->orderByDesc('effective_from')->get();
        $activeByRole = $activeBands->groupBy('position_role');

        $placed = 0;
        $outOfBand = 0;
        foreach ($employees->groupBy('position_role') as $role => $people) {
            $band = $activeByRole->get($role)?->first();
            if (! $band) {
                continue;
            }
            foreach ($people as $p) {
                $pos = $this->bandPlacement($p, $band)['position'];
                if ($pos === null) {
                    continue;
                }
                $placed++;
                if ($pos !== 'in') {
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
            'band_health' => $placed > 0 ? (int) round((($placed - $outOfBand) / $placed) * 100) : 100,
            ...$this->hubAggregates($tenantId, $user),
        ];
    }

    /**
     * Cross-hub hero aggregates: reviews in flight, items awaiting approval,
     * reimbursed this month, claims overdue. Counts respect the viewer's gates so
     * a comp-only user never sees benefits/expenses numbers they can't open.
     *
     * @return array<string, int|float>
     */
    public function hubAggregates(int $tenantId, User $user): array
    {
        $reviewsInFlight = HrCompensationReview::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['planning', 'in_progress', 'approved'])
            ->count();

        $canExpenses = $user->canDo('hr.expenses.view');
        $awaitingClaims = $canExpenses
            ? HrExpenseClaim::query()->where('tenant_id', $tenantId)->where('status', 'submitted')->count()
            : 0;
        $pendingBonuses = HrBonusPayment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->count();

        $monthStart = Carbon::now()->startOfMonth();
        $reimbursed = $canExpenses
            ? (float) HrExpenseClaim::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $monthStart)
                ->sum('total_amount')
            : 0.0;

        $claimsOverdue = $canExpenses
            ? HrExpenseClaim::query()
                ->where('tenant_id', $tenantId)
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

    /**
     * Per-tab record counts for the hub tab-strip badges.
     *
     * @return array<string, int>
     */
    public function tabCounts(int $tenantId): array
    {
        return [
            'bands' => HrSalaryBand::query()->forTenant($tenantId)->active()->count(),
            'reviews' => HrCompensationReview::query()->where('tenant_id', $tenantId)->count(),
            'bonuses' => HrBonusPayment::query()->where('tenant_id', $tenantId)->count(),
            'benefits' => HrBenefitEnrollment::query()->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'expenses' => HrExpenseClaim::query()->where('tenant_id', $tenantId)->count(),
        ];
    }

    /**
     * Create a new compensation review cycle.
     */
    public function createCompensationReview(array $data): HrCompensationReview
    {
        return DB::transaction(function () use ($data) {
            $review = HrCompensationReview::create([
                'tenant_id' => $data['tenant_id'],
                'title' => $data['title'],
                'review_cycle' => $data['review_cycle'],
                'effective_date' => $data['effective_date'],
                'status' => $data['status'] ?? 'planning',
                'budget_amount' => $data['budget_amount'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            if (! empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $review->items()->create([
                        'employee_profile_id' => $item['employee_profile_id'],
                        'current_salary' => $item['current_salary'],
                        'proposed_salary' => $item['proposed_salary'],
                        'change_percentage' => $item['change_percentage'],
                        'justification' => $item['justification'] ?? null,
                        'status' => 'pending',
                    ]);
                }
            }

            return $review->load('items');
        });
    }

    /**
     * Approve a compensation review: mark its pending line-items approved and flip
     * the review to 'approved' so it becomes eligible for applyCompensationReview().
     */
    public function approveCompensationReview(HrCompensationReview $review, int $approverId): void
    {
        if (! in_array($review->status, ['planning', 'in_progress'], true)) {
            throw new \LogicException("Cannot approve a '{$review->status}' compensation review. Only planning or in-progress reviews can be approved.");
        }

        DB::transaction(function () use ($review, $approverId) {
            // Update each pending item through the model so the change is audited.
            $review->items()
                ->where('status', 'pending')
                ->get()
                ->each(function ($item) use ($approverId) {
                    $item->update([
                        'status' => 'approved',
                        'approved_by' => $approverId,
                    ]);
                });

            // The reviews table has no approved_by column — approver attribution
            // lives on each line-item; the review only tracks its status.
            $review->update(['status' => 'approved']);
        });
    }

    /**
     * Apply an approved compensation review: bulk-update profiles and create history entries.
     */
    public function applyCompensationReview(HrCompensationReview $review): void
    {
        if ($review->status !== 'approved') {
            throw new \LogicException("Cannot apply a '{$review->status}' compensation review. It must be approved first.");
        }

        DB::transaction(function () use ($review) {
            $approvedItems = $review->items()->where('status', 'approved')->get();

            foreach ($approvedItems as $item) {
                $profile = HrEmployeeProfile::findOrFail($item->employee_profile_id);

                $this->recordCompensationChange($profile, [
                    'change_type' => 'review',
                    'new_hourly_rate' => $item->proposed_salary, // Service caller maps salary to hourly if needed
                    'new_annual_salary' => $item->proposed_salary,
                    'change_percentage' => $item->change_percentage,
                    'reason' => $item->justification ?? "Applied from compensation review: {$review->title}",
                    'effective_date' => $review->effective_date,
                    'approved_by' => $item->approved_by,
                    'created_by' => $review->created_by,
                ]);
            }

            $review->update(['status' => 'applied']);
        });
    }
}
