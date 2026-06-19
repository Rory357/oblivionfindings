<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrFeedbackRequest;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class HrPerformanceSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;

        // Get any available users - be flexible with what exists
        $allUsers = User::orderBy('id')->limit(20)->get(['id', 'email', 'name']);

        if ($allUsers->count() < 3) {
            $this->command->warn('Need at least 3 users in the database. Run SystemUsersSeeder first.');
            return;
        }

        // Try to find manager/hr by email, fallback to first users
        $manager = $allUsers->firstWhere('email', 'manager@demo.test') ?? $allUsers->first();
        $hr = $allUsers->firstWhere('email', 'hr@demo.test') ?? $allUsers->skip(1)->first();

        // Staff = everyone except manager and hr
        $staffUsers = $allUsers->filter(fn ($u) => $u->id !== $manager->id && $u->id !== $hr->id);
        if ($staffUsers->isEmpty()) {
            $staffUsers = $allUsers->take(4); // fallback
        }

        $managerId = $manager->id;
        $hrId = $hr->id;
        $staffIds = $staffUsers->pluck('id')->values()->all();

        // ── Performance Reviews (spread across last 6 months) ──────────
        $reviewTypes = ['annual', 'mid_year', 'quarterly', 'ad_hoc'];
        $statuses = ['completed', 'completed', 'completed', 'in_progress', 'draft', 'signed_off'];
        $ratings = [3, 4, 5, 4, 3, 5, 2, 4, 4, 3, 5, 4, 1, 3, 4, 5, 4, 3];

        $ratingIdx = 0;
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $baseDate = Carbon::now()->subMonths($monthsAgo);
            $count = $monthsAgo === 0 ? 4 : rand(2, 5);

            for ($i = 0; $i < $count; $i++) {
                $empId = $staffIds[array_rand($staffIds)];
                $status = $statuses[array_rand($statuses)];
                $rating = in_array($status, ['completed', 'signed_off']) ? $ratings[$ratingIdx % count($ratings)] : null;
                $ratingIdx++;

                HrPerformanceReview::firstOrCreate([
                    'tenant_id' => $tenantId,
                    'employee_user_id' => $empId,
                    'review_period_start' => $baseDate->copy()->startOfMonth()->toDateString(),
                    'review_type' => $reviewTypes[array_rand($reviewTypes)],
                ], [
                    'reviewer_user_id' => $managerId,
                    'review_period_end' => $baseDate->copy()->endOfMonth()->toDateString(),
                    'status' => $status,
                    'overall_rating' => $rating,
                    'strengths' => 'Demonstrates strong teamwork and communication skills.',
                    'development_areas' => 'Could improve time management and documentation.',
                    'goals' => ['Complete NZ compliance training', 'Improve client satisfaction scores'],
                    'training_recommendations' => ['First aid refresher', 'De-escalation workshop'],
                    'next_review_date' => $baseDate->copy()->addMonths(3)->toDateString(),
                    'created_by' => $managerId,
                    'created_at' => $baseDate->copy()->addDays(rand(1, 20)),
                ]);
            }
        }

        // ── Supervision Notes (spread across last 6 months) ──────────
        $sessionTypes = ['one_to_one', 'supervision', 'review', 'check_in'];
        $topics = [
            'Discussed client progress and care plan updates.',
            'Reviewed incident report from last week. Actions agreed.',
            'General wellbeing check-in. Staff morale is positive.',
            'Performance goals review and training needs assessment.',
            'Discussed shift patterns and workload distribution.',
            'Medication competency follow-up and documentation review.',
            'Team communication improvements discussed.',
            'Reviewed health and safety compliance checklist.',
        ];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $baseDate = Carbon::now()->subMonths($monthsAgo);
            $count = $monthsAgo === 0 ? 6 : rand(3, 8);

            for ($i = 0; $i < $count; $i++) {
                $empId = $staffIds[array_rand($staffIds)];
                $sessionDate = $baseDate->copy()->addDays(rand(1, 27));
                $superId = rand(0, 1) ? $managerId : $hrId;

                HrSupervisionNote::firstOrCreate([
                    'tenant_id' => $tenantId,
                    'employee_user_id' => $empId,
                    'session_date' => $sessionDate->toDateString(),
                ], [
                    'supervisor_user_id' => $superId,
                    'session_type' => $sessionTypes[array_rand($sessionTypes)],
                    'duration_minutes' => [30, 45, 60][array_rand([30, 45, 60])],
                    'topics_discussed' => $topics[array_rand($topics)],
                    'actions_agreed' => ['Follow up on training', 'Update care plans by Friday'],
                    'next_session_date' => $sessionDate->copy()->addWeeks(2)->toDateString(),
                    'is_visible_to_employee' => true,
                    'created_by' => $superId,
                ]);
            }
        }

        // ── Development Goals (with competency gaps) ──────────
        $goalData = [
            ['title' => 'Medication Management', 'area' => 'Clinical', 'current' => 2, 'target' => 4],
            ['title' => 'Crisis Intervention', 'area' => 'Safety', 'current' => 1, 'target' => 3],
            ['title' => 'Documentation Standards', 'area' => 'Admin', 'current' => 3, 'target' => 5],
            ['title' => 'Client Communication', 'area' => 'Communication', 'current' => 2, 'target' => 4],
            ['title' => 'Health & Safety Compliance', 'area' => 'Compliance', 'current' => 3, 'target' => 4],
            ['title' => 'Team Leadership', 'area' => 'Leadership', 'current' => 1, 'target' => 4],
            ['title' => 'Cultural Competency', 'area' => 'Cultural', 'current' => 2, 'target' => 3],
        ];

        foreach ($goalData as $idx => $goal) {
            $empId = $staffIds[$idx % count($staffIds)];
            HrDevelopmentGoal::firstOrCreate([
                'tenant_id' => $tenantId,
                'employee_user_id' => $empId,
                'title' => $goal['title'],
            ], [
                'manager_user_id' => $managerId,
                'description' => "Improve {$goal['title']} skills to meet target proficiency.",
                'category' => 'competency',
                'competency_area' => $goal['area'],
                'current_level' => $goal['current'],
                'target_level' => $goal['target'],
                'status' => ['not_started', 'in_progress', 'in_progress', 'blocked'][rand(0, 3)],
                'progress_percent' => rand(10, 70),
                'start_date' => Carbon::now()->subMonths(2)->toDateString(),
                'due_date' => Carbon::now()->addMonths(rand(1, 4))->toDateString(),
                'created_by' => $managerId,
            ]);
        }

        // ── PIPs ──────────
        // Ensure employee profiles exist for staff
        foreach ($staffIds as $sid) {
            $user = $allUsers->firstWhere('id', $sid);
            HrEmployeeProfile::firstOrCreate(
                ['user_id' => $sid, 'tenant_id' => $tenantId],
                [
                    'employee_number' => 'EMP-' . $sid,
                    'work_email' => $user?->email ?? "staff{$sid}@demo.test",
                    'position_title' => 'Support Worker',
                    'position_role' => 'support_worker',
                    'department' => 'Operations',
                    'employment_type' => 'full_time',
                    'start_date' => now()->subYear()->toDateString(),
                    'is_active' => true,
                    'created_by' => $managerId,
                ],
            );
        }
        $profiles = HrEmployeeProfile::where('tenant_id', $tenantId)->pluck('id', 'user_id');

        if ($profiles->isNotEmpty()) {
            $pipData = [
                ['status' => 'active', 'title' => 'Attendance Improvement Plan'],
                ['status' => 'in_progress', 'title' => 'Documentation Quality Plan'],
                ['status' => 'completed', 'title' => 'Client Interaction Improvement'],
                ['status' => 'completed', 'title' => 'Health & Safety Compliance Plan'],
                ['status' => 'cancelled', 'title' => 'Shift Coverage Commitment'],
            ];

            foreach ($pipData as $idx => $pip) {
                $empId = $staffIds[$idx % count($staffIds)];
                if (! ($profiles[$empId] ?? null)) continue;

                HrPerformanceImprovementPlan::firstOrCreate([
                    'tenant_id' => $tenantId,
                    'employee_user_id' => $empId,
                    'title' => $pip['title'],
                ], [
                    'manager_user_id' => $managerId,
                    'reason' => "Staff member needs support to meet expected standards in this area.",
                    'expectations' => "Meet targets consistently over 30-day review period.",
                    'support_offered' => "Additional training, mentoring, and weekly check-ins.",
                    'consequences' => "Further performance review if the plan outcomes are not met.",
                    'start_date' => Carbon::now()->subMonths(rand(1, 3))->toDateString(),
                    'end_date' => Carbon::now()->addMonths(rand(1, 2))->toDateString(),
                    'review_date' => Carbon::now()->addWeeks(2)->toDateString(),
                    'status' => $pip['status'],
                    'outcome_notes' => $pip['status'] === 'completed' ? 'Targets met. PIP closed successfully.' : null,
                    'created_by' => $managerId,
                ]);
            }
        }

        // ── Feedback Requests ──────────
        $reviewTypes = ['peer', 'manager', 'direct_report', 'self'];
        $fbStatuses = ['pending', 'pending', 'completed', 'completed', 'completed', 'pending', 'completed', 'declined'];

        foreach ($staffIds as $idx => $empId) {
            $reviewerIds = array_values(array_diff($staffIds, [$empId]));
            $reviewerId = $reviewerIds[array_rand($reviewerIds)];

            HrFeedbackRequest::firstOrCreate([
                'tenant_id' => $tenantId,
                'subject_user_id' => $empId,
                'reviewer_user_id' => $reviewerId,
            ], [
                'requester_user_id' => $managerId,
                'review_type' => $reviewTypes[array_rand($reviewTypes)],
                'status' => $fbStatuses[$idx % count($fbStatuses)],
                'due_date' => Carbon::now()->addDays(rand(-5, 14))->toDateString(),
                'completed_at' => $fbStatuses[$idx % count($fbStatuses)] === 'completed' ? Carbon::now()->subDays(rand(1, 10)) : null,
            ]);
        }

        // A few more feedback with manager as reviewer
        foreach (array_slice($staffIds, 0, 3) as $empId) {
            HrFeedbackRequest::firstOrCreate([
                'tenant_id' => $tenantId,
                'subject_user_id' => $empId,
                'reviewer_user_id' => $managerId,
            ], [
                'requester_user_id' => $hrId,
                'review_type' => 'manager',
                'status' => ['pending', 'completed'][rand(0, 1)],
                'due_date' => Carbon::now()->addDays(rand(-3, 10))->toDateString(),
                'completed_at' => rand(0, 1) ? Carbon::now()->subDays(rand(1, 5)) : null,
            ]);
        }

        $this->command->info('HR Performance data seeded successfully.');
    }
}
