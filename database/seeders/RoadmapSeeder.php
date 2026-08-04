<?php

namespace Database\Seeders;

use App\Domain\Roadmap\Models\DelegationOfAuthorityRule;
use App\Domain\Roadmap\Models\InitiativeCategory;
use Illuminate\Database\Seeder;

class RoadmapSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['key' => 'it', 'name' => 'IT', 'sort_order' => 10],
            ['key' => 'maintenance', 'name' => 'Maintenance', 'sort_order' => 20],
            ['key' => 'facilities', 'name' => 'Facilities', 'sort_order' => 30],
            ['key' => 'operations', 'name' => 'Operations', 'sort_order' => 40],
            ['key' => 'overheads', 'name' => 'Overheads & Cost Control', 'sort_order' => 50],
            ['key' => 'continuous_improvement', 'name' => 'Continuous Improvement', 'sort_order' => 60],
        ];

        foreach ($categories as $category) {
            InitiativeCategory::firstOrCreate(
                ['key' => $category['key']],
                [
                    'name' => $category['name'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        $rules = [
            [
                'scope' => 'initiative_budget',
                'amount_min' => 0,
                'amount_max' => 10000,
                'required_approver_role' => 'provider_manager',
                'escalation_role' => 'admin',
            ],
            [
                'scope' => 'initiative_budget',
                'amount_min' => 10000.01,
                'amount_max' => 50000,
                'required_approver_role' => 'admin',
                'escalation_role' => 'board_chair',
            ],
            [
                'scope' => 'initiative_budget',
                'amount_min' => 50000.01,
                'amount_max' => null,
                'required_approver_role' => 'board_chair',
                'escalation_role' => 'board_member',
            ],
        ];

        foreach ($rules as $rule) {
            DelegationOfAuthorityRule::firstOrCreate(
                [
                    'scope' => $rule['scope'],
                    'amount_min' => $rule['amount_min'],
                    'amount_max' => $rule['amount_max'],
                    'required_approver_role' => $rule['required_approver_role'],
                ],
                [
                    'escalation_role' => $rule['escalation_role'],
                    'is_active' => true,
                    'notes' => 'Default roadmap delegation rule',
                ],
            );
        }

        $this->command->info('Roadmap baseline categories and delegation rules seeded.');
    }
}
