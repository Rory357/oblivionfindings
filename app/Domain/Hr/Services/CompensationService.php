<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCompensationHistory;
use App\Domain\Hr\Models\HrCompensationReview;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
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
