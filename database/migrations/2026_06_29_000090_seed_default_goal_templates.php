<?php

use App\Domain\Hr\Models\HrGoalTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Seed one starter set of common application objective templates (item 16 —
 * "create from template"). Runs on deploy; firstOrCreate keeps it idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_goal_templates')) {
            return;
        }

        foreach ($this->templates() as $template) {
            HrGoalTemplate::query()->firstOrCreate(
                ['name' => $template['name']],
                [...$template, 'is_active' => true],
            );
        }
    }

    public function down(): void
    {
        // Templates dropped with their table.
    }

    private function templates(): array
    {
        $pct = fn ($title, $start, $target, $weight = 1) => [
            'title' => $title, 'kr_type' => 'percent', 'start_value' => $start,
            'target_value' => $target, 'unit' => '%', 'weight' => $weight,
        ];
        $num = fn ($title, $start, $target, $unit = '', $weight = 1) => [
            'title' => $title, 'kr_type' => 'number', 'start_value' => $start,
            'target_value' => $target, 'unit' => $unit, 'weight' => $weight,
        ];

        return [
            [
                'name' => 'Reduce preventable medication errors',
                'title' => 'Eliminate preventable medication errors',
                'description' => 'Drive med-safety practice through training, double-sign and audits.',
                'goal_type' => 'team', 'category' => 'Safety', 'priority' => 'high',
                'key_results' => [
                    $num('Medication errors per month', 14, 3, '', 2),
                    $pct('Staff passing med-safety refresher', 40, 100),
                    $pct('Double-sign compliance', 71, 98),
                ],
            ],
            [
                'name' => 'Lift resident & whānau satisfaction',
                'title' => 'Lift resident & whānau satisfaction',
                'description' => 'Improve experience across the supported-living network.',
                'goal_type' => 'company', 'category' => 'Quality', 'priority' => 'high',
                'key_results' => [$pct('Resident & whānau satisfaction', 78, 92, 2)],
            ],
            [
                'name' => 'Improve 12-month staff retention',
                'title' => 'Lift 12-month staff retention',
                'description' => 'Strengthen onboarding, supervision and recognition.',
                'goal_type' => 'team', 'category' => 'People', 'priority' => 'high',
                'key_results' => [
                    $pct('12-month retention', 79, 88, 2),
                    $num('Voluntary turnover', 22, 12, '%'),
                ],
            ],
            [
                'name' => 'Achieve 100% mandatory training compliance',
                'title' => 'Achieve 100% mandatory training compliance',
                'description' => 'Close certification gaps before audit season.',
                'goal_type' => 'team', 'category' => 'Compliance', 'priority' => 'medium',
                'key_results' => [
                    $pct('Mandatory modules complete', 72, 100, 2),
                    $num('Overdue certifications', 34, 0),
                ],
            ],
            [
                'name' => 'Reach 95% occupancy',
                'title' => 'Reach 95% occupancy across supported-living homes',
                'description' => 'Reduce vacancy turnaround and lift referral conversion.',
                'goal_type' => 'team', 'category' => 'Growth', 'priority' => 'medium',
                'key_results' => [$pct('Occupancy rate', 84, 95, 2)],
            ],
        ];
    }
};
