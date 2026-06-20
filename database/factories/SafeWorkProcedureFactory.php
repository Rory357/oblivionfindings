<?php

namespace Database\Factories;

use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SafeWorkProcedureFactory extends Factory
{
    protected $model = SafeWorkProcedure::class;

    /**
     * Seed the matching version row(s) so tests exercise the real production path
     * (store/seed always populate a row for current_version) — otherwise lifecycle
     * snapshots silently dodge the unique (procedure, version) index.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (SafeWorkProcedure $procedure): void {
            for ($v = 1; $v <= (int) $procedure->current_version; $v++) {
                SafeWorkProcedureVersion::firstOrCreate(
                    ['safe_work_procedure_id' => $procedure->id, 'version_number' => $v],
                    ['content_snapshot' => [], 'change_summary' => $v === 1 ? 'Initial version' : 'Version '.$v, 'changed_by' => $procedure->created_by],
                );
            }
        });
    }

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'reference_number' => 'SWP-'.fake()->unique()->numerify('###'),
            'category' => fake()->randomElement([
                'manual_handling', 'challenging_behaviour', 'lone_working', 'medication',
                'infection_control', 'fire_safety', 'emergency_procedures', 'equipment_use', 'personal_care',
            ]),
            'purpose' => fake()->sentence(),
            'scope' => fake()->sentence(),
            'status' => 'draft',
            'current_version' => 1,
            'review_frequency_months' => 12,
            'created_by' => User::factory(),
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function underReview(): static
    {
        return $this->state(['status' => 'under_review']);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_by' => User::factory(),
            'approved_at' => now(),
            'review_date' => now()->addMonths(6),
        ]);
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived', 'previous_status' => 'approved']);
    }

    /** Approved but within 30 days of review — drives the review-due tab/badge. */
    public function reviewDue(): static
    {
        return $this->approved()->state(['review_date' => now()->addDays(14)]);
    }

    /** Approved with a past review date — drives the review-overdue badge. */
    public function reviewOverdue(): static
    {
        return $this->approved()->state(['review_date' => now()->subDays(10)]);
    }
}
