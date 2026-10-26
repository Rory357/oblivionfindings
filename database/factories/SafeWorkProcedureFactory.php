<?php

namespace Database\Factories;

use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureService;
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
            $snapshot = $procedure->only(SafeWorkProcedureService::CONTENT_FIELDS);
            $snapshot['review_date'] = $procedure->review_date?->toDateString();
            $version = SafeWorkProcedureVersion::create(['safe_work_procedure_id' => $procedure->id, 'version_number' => $procedure->current_version, 'content_snapshot' => $snapshot, 'document_manifest' => [], 'change_summary' => 'Fixture current version', 'changed_by' => $procedure->created_by, 'provenance' => 'controlled', 'captured_at' => now(), 'approved_by' => $procedure->status === 'approved' ? $procedure->approved_by : null, 'approved_at' => $procedure->status === 'approved' ? $procedure->approved_at : null, 'content_digest' => hash('sha256', json_encode([$snapshot, []], JSON_THROW_ON_ERROR))]);
            if ($procedure->status === 'approved') {
                $procedure->forceFill(['published_version_id' => $version->id, 'published_review_date' => $procedure->review_date])->saveQuietly();
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
            'review_frequency_months' => 12, 'applicable_roles' => [], 'applicable_sites' => [],
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
