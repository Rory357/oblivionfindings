<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrKeyResult;
use App\Models\User;
use Illuminate\Database\Seeder;

class GoalsOkrSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::first();
        if (! $admin) return;

        $tenantId = $admin->tenant_id ?? 1;
        $this->command->info('Seeding Goals & OKRs test data...');

        // ============================================================
        // COMPANY OBJECTIVE 1: Service Quality
        // ============================================================
        $companyGoal1 = HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Improve Service Quality to 95% Satisfaction'],
            [
                'user_id' => $admin->id,
                'description' => 'Achieve 95% or higher client satisfaction rating across all service lines by end of Q2 2026.',
                'goal_type' => 'company',
                'category' => 'Quality',
                'target_value' => 95,
                'current_value' => 82,
                'unit' => '%',
                'progress_percentage' => 65,
                'status' => 'active',
                'priority' => 'high',
                'start_date' => '2026-01-01',
                'due_date' => '2026-06-30',
                'created_by' => $admin->id,
            ]
        );

        // KRs for Company Goal 1
        HrKeyResult::firstOrCreate(['goal_id' => $companyGoal1->id, 'title' => 'Achieve 95% client satisfaction score'], [
            'tenant_id' => $tenantId, 'target_value' => 95, 'current_value' => 82, 'unit' => '%', 'progress_percentage' => 86, 'status' => 'in_progress', 'due_date' => '2026-06-30', 'owner_id' => $admin->id,
        ]);
        HrKeyResult::firstOrCreate(['goal_id' => $companyGoal1->id, 'title' => 'Reduce incident reports by 30%'], [
            'tenant_id' => $tenantId, 'target_value' => 30, 'current_value' => 18, 'unit' => '% reduction', 'progress_percentage' => 60, 'status' => 'in_progress', 'due_date' => '2026-06-30', 'owner_id' => $admin->id,
        ]);
        HrKeyResult::firstOrCreate(['goal_id' => $companyGoal1->id, 'title' => 'Complete quality audits at all sites'], [
            'tenant_id' => $tenantId, 'target_value' => 5, 'current_value' => 3, 'unit' => 'audits', 'progress_percentage' => 60, 'status' => 'in_progress', 'due_date' => '2026-05-31', 'owner_id' => $admin->id,
        ]);

        // Team Goal under Company Goal 1
        $teamGoal1 = HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Residential Services Quality Improvement'],
            [
                'user_id' => $admin->id,
                'description' => 'Improve residential services quality metrics and staff training compliance.',
                'goal_type' => 'team',
                'category' => 'Quality',
                'parent_goal_id' => $companyGoal1->id,
                'target_value' => 100,
                'current_value' => 70,
                'unit' => '%',
                'progress_percentage' => 70,
                'status' => 'active',
                'priority' => 'high',
                'start_date' => '2026-01-15',
                'due_date' => '2026-06-30',
                'created_by' => $admin->id,
            ]
        );

        HrKeyResult::firstOrCreate(['goal_id' => $teamGoal1->id, 'title' => 'All staff complete medication management training'], [
            'tenant_id' => $tenantId, 'target_value' => 100, 'current_value' => 85, 'unit' => '%', 'progress_percentage' => 85, 'status' => 'in_progress', 'due_date' => '2026-04-30', 'owner_id' => $admin->id,
        ]);
        HrKeyResult::firstOrCreate(['goal_id' => $teamGoal1->id, 'title' => 'Implement new support plan review process'], [
            'tenant_id' => $tenantId, 'target_value' => 1, 'current_value' => 0, 'unit' => 'process', 'progress_percentage' => 40, 'status' => 'in_progress', 'due_date' => '2026-05-15', 'owner_id' => $admin->id,
        ]);

        // Individual Goal under Team Goal 1
        HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Complete Level 4 Health & Wellbeing Certificate'],
            [
                'user_id' => $admin->id,
                'goal_type' => 'individual',
                'category' => 'Training',
                'parent_goal_id' => $teamGoal1->id,
                'target_value' => 100,
                'current_value' => 60,
                'unit' => '%',
                'progress_percentage' => 60,
                'status' => 'active',
                'priority' => 'medium',
                'start_date' => '2026-02-01',
                'due_date' => '2026-07-31',
                'created_by' => $admin->id,
            ]
        );

        HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Achieve zero medication errors for Q2'],
            [
                'user_id' => $admin->id,
                'goal_type' => 'individual',
                'category' => 'Quality',
                'parent_goal_id' => $teamGoal1->id,
                'target_value' => 0,
                'current_value' => 0,
                'unit' => 'errors',
                'progress_percentage' => 100,
                'status' => 'completed',
                'priority' => 'high',
                'start_date' => '2026-01-01',
                'due_date' => '2026-03-31',
                'completed_at' => '2026-03-28',
                'created_by' => $admin->id,
            ]
        );

        // ============================================================
        // COMPANY OBJECTIVE 2: Staff Retention
        // ============================================================
        $companyGoal2 = HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Reduce Staff Turnover to Below 15%'],
            [
                'user_id' => $admin->id,
                'description' => 'Improve staff retention through better engagement, training, and career development pathways.',
                'goal_type' => 'company',
                'category' => 'People',
                'target_value' => 15,
                'current_value' => 22,
                'unit' => '% turnover',
                'progress_percentage' => 40,
                'status' => 'active',
                'priority' => 'high',
                'start_date' => '2026-01-01',
                'due_date' => '2026-12-31',
                'created_by' => $admin->id,
            ]
        );

        HrKeyResult::firstOrCreate(['goal_id' => $companyGoal2->id, 'title' => 'Reduce annual turnover from 28% to 15%'], [
            'tenant_id' => $tenantId, 'target_value' => 15, 'current_value' => 22, 'unit' => '%', 'progress_percentage' => 46, 'status' => 'in_progress', 'due_date' => '2026-12-31', 'owner_id' => $admin->id,
        ]);
        HrKeyResult::firstOrCreate(['goal_id' => $companyGoal2->id, 'title' => 'Launch career development programme'], [
            'tenant_id' => $tenantId, 'target_value' => 1, 'current_value' => 0, 'unit' => 'programme', 'progress_percentage' => 30, 'status' => 'in_progress', 'due_date' => '2026-06-30', 'owner_id' => $admin->id,
        ]);
        HrKeyResult::firstOrCreate(['goal_id' => $companyGoal2->id, 'title' => 'Achieve 80%+ employee engagement score'], [
            'tenant_id' => $tenantId, 'target_value' => 80, 'current_value' => 65, 'unit' => '%', 'progress_percentage' => 81, 'status' => 'in_progress', 'due_date' => '2026-09-30', 'owner_id' => $admin->id,
        ]);

        // Team goal under Company Goal 2
        $teamGoal2 = HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Day Services Team Engagement Initiative'],
            [
                'user_id' => $admin->id,
                'goal_type' => 'team',
                'category' => 'People',
                'parent_goal_id' => $companyGoal2->id,
                'progress_percentage' => 55,
                'status' => 'active',
                'priority' => 'medium',
                'start_date' => '2026-02-01',
                'due_date' => '2026-09-30',
                'created_by' => $admin->id,
            ]
        );

        HrKeyResult::firstOrCreate(['goal_id' => $teamGoal2->id, 'title' => 'Monthly team hui attendance above 90%'], [
            'tenant_id' => $tenantId, 'target_value' => 90, 'current_value' => 78, 'unit' => '%', 'progress_percentage' => 87, 'status' => 'in_progress', 'due_date' => '2026-09-30', 'owner_id' => $admin->id,
        ]);

        // ============================================================
        // COMPANY OBJECTIVE 3: Growth (Draft)
        // ============================================================
        HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Expand Community Living Services to Tauranga'],
            [
                'user_id' => $admin->id,
                'description' => 'Open new community living services in Tauranga region to serve 20+ new clients.',
                'goal_type' => 'company',
                'category' => 'Growth',
                'target_value' => 20,
                'unit' => 'clients',
                'progress_percentage' => 0,
                'status' => 'draft',
                'priority' => 'medium',
                'start_date' => '2026-07-01',
                'due_date' => '2026-12-31',
                'created_by' => $admin->id,
            ]
        );

        // Standalone individual goals
        HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Complete First Aid Refresher Course'],
            [
                'user_id' => $admin->id,
                'goal_type' => 'individual',
                'category' => 'Compliance',
                'progress_percentage' => 100,
                'status' => 'completed',
                'priority' => 'high',
                'start_date' => '2026-01-15',
                'due_date' => '2026-03-15',
                'completed_at' => '2026-03-10',
                'created_by' => $admin->id,
            ]
        );

        HrGoal::firstOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Achieve 40 hours professional development this year'],
            [
                'user_id' => $admin->id,
                'goal_type' => 'individual',
                'category' => 'Development',
                'target_value' => 40,
                'current_value' => 12,
                'unit' => 'hours',
                'progress_percentage' => 30,
                'status' => 'active',
                'priority' => 'low',
                'start_date' => '2026-01-01',
                'due_date' => '2026-12-31',
                'created_by' => $admin->id,
            ]
        );

        $this->command->info('Done! Created company, team, and individual goals with key results.');
    }
}
