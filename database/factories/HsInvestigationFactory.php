<?php

namespace Database\Factories;

use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HsInvestigationFactory extends Factory
{
    protected $model = HsInvestigation::class;

    public function definition(): array
    {
        return [
            'hs_event_id' => HsEvent::factory()->high(),
            'reference_number' => HsInvestigation::generateReferenceNumber(),
            'investigation_type' => HsInvestigation::TYPE_STANDARD,
            'status' => HsInvestigation::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => HsInvestigation::STATUS_IN_PROGRESS,
            'lead_investigator_id' => User::factory(),
            'started_at' => now(),
        ]);
    }

    public function withFindings(): static
    {
        return $this->state(fn () => [
            'status' => HsInvestigation::STATUS_FINDINGS_RECORDED,
            'lead_investigator_id' => User::factory(),
            'started_at' => now()->subDays(3),
            'methodology' => HsInvestigation::METHODOLOGY_5_WHYS,
            'immediate_causes' => [
                ['description' => 'Wet floor not marked', 'category' => 'environmental'],
            ],
            'root_causes' => [
                ['description' => 'No cleaning schedule posted', 'category' => 'procedural'],
            ],
            'contributing_factors' => [
                ['description' => 'Staff unaware of hazard protocol', 'factor_type' => 'human'],
            ],
            'findings_summary' => 'Wet floor caused slip. No signage protocol in place.',
            'recommendations' => [
                ['description' => 'Implement wet floor signage procedure', 'priority' => 'high', 'target_area' => 'procedure'],
                ['description' => 'Refresher training for all staff', 'priority' => 'medium', 'target_area' => 'training'],
            ],
        ]);
    }

    public function completed(): static
    {
        return $this->withFindings()->state(fn () => [
            'status' => HsInvestigation::STATUS_COMPLETED,
            'completed_at' => now(),
            'reviewed_by_id' => User::factory(),
            'reviewed_at' => now()->subHour(),
            'approved_by_id' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function worksafeDirected(): static
    {
        return $this->state(fn () => [
            'investigation_type' => HsInvestigation::TYPE_WORKSAFE_DIRECTED,
            'hs_event_id' => HsEvent::factory()->worksafeNotifiable(),
        ]);
    }
}
