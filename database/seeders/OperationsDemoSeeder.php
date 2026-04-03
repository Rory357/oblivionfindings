<?php

namespace Database\Seeders;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Client;
use App\Models\ProgressNote;
use App\Models\ServiceAgreement;
use App\Models\User;
use Illuminate\Database\Seeder;

class OperationsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('role', 'admin')->first() ?? User::first();
        if (!$user) {
            $this->command->error("No users found - run SystemUsersSeeder first");
            return;
        }
        $userId = $user->id;

        $clients = Client::take(10)->get();
        $staffIds = User::pluck('id')->toArray();

        if ($clients->isEmpty()) {
            $this->command->error('No clients found');
            return;
        }

        $this->command->info("Seeding operations data with {$clients->count()} clients...");

        $planTypes = ['support_plan', 'behaviour_plan', 'health_plan', 'transition_plan'];
        $statuses = ['active', 'active', 'active', 'draft', 'review'];
        $goalCategories = ['health', 'social', 'independence', 'skills', 'wellbeing'];
        $goalStatuses = ['completed', 'in_progress', 'in_progress', 'not_started', 'on_hold'];

        $goalTitles = [
            'Attend community group weekly',
            'Manage morning routine independently',
            'Walk to the local shop unassisted',
            'Prepare a simple meal twice a week',
            'Use public transport independently',
            'Maintain personal hygiene schedule',
            'Attend medical appointments on time',
            'Build social connections at day programme',
            'Learn to budget weekly spending',
            'Practice conflict resolution skills',
        ];

        $noteContents = [
            'Great session today. Made good progress towards this goal. Engaged well with activities.',
            'Some challenges today but showed resilience. Needed prompting to start but completed the task.',
            'Excellent day! Completed the activity independently for the first time. Very proud moment.',
            'Discussed strategies for managing anxiety. Used breathing techniques successfully.',
            'Practiced skills in the community. Growing in confidence each week.',
            'Review meeting held with family. All happy with progress. Adjusted approach slightly.',
        ];

        foreach ($clients->take(8) as $i => $client) {
            $planType = $planTypes[$i % count($planTypes)];
            $status = $statuses[$i % count($statuses)];
            $startsAt = now()->subMonths(rand(1, 6));

            $plan = CarePlan::create([
                'organization_id' => null,
                'client_id' => $client->id,
                'title' => $client->first_name . "'s " . str_replace('_', ' ', ucwords($planType, '_')),
                'status' => $status,
                'plan_type' => $planType,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addYear(),
                'next_review_at' => $status === 'active' ? now()->addDays(rand(-10, 30)) : null,
                'created_by' => $userId,
                'version' => 1,
                'content' => [
                    'about_me' => [
                        'dreams' => 'I want to live independently and have my own flat.',
                        'important_to_me' => 'My family, my music, going to the movies on Fridays.',
                        'important_for_me' => 'Taking medication on time, eating well, staying active.',
                        'ideal_day' => 'Wake up at 8, have breakfast, go for a walk, lunch with friends, afternoon activity, dinner, watch TV.',
                        'likes' => 'Pizza, rugby, the colour blue, swimming, dogs.',
                        'dislikes' => 'Loud sudden noises, being rushed, cold weather.',
                        'how_to_support' => 'Speak calmly and give time to process. Use visual schedules. Praise achievements.',
                    ],
                    'support_needs' => [
                        'daily_living' => true,
                        'personal_care' => $i % 2 === 0,
                        'community_access' => true,
                        'health_management' => $i % 3 === 0,
                        'communication' => $i % 2 !== 0,
                        'social_participation' => true,
                    ],
                    'risk_factors' => 'History of falls. Allergic to penicillin. Can become anxious in crowded spaces.',
                    'support_strategies' => 'Person-centred approach with positive behaviour support. Use visual aids and social stories.',
                    'communication_preferences' => 'Prefers simple, clear sentences. Responds well to visual prompts.',
                    'review_schedule' => ['frequency_months' => 3],
                ],
            ]);

            $numGoals = rand(3, 6);
            for ($g = 0; $g < $numGoals; $g++) {
                $goalStatus = $goalStatuses[$g % count($goalStatuses)];
                $progress = match ($goalStatus) {
                    'completed' => 100,
                    'in_progress' => rand(20, 80),
                    'not_started' => 0,
                    'on_hold' => rand(10, 50),
                    default => 0,
                };

                $goal = CarePlanGoal::create([
                    'organization_id' => null,
                    'care_plan_id' => $plan->id,
                    'client_id' => $client->id,
                    'title' => $goalTitles[($i + $g) % count($goalTitles)],
                    'description' => 'Work towards this goal with structured support and regular check-ins.',
                    'category' => $goalCategories[$g % count($goalCategories)],
                    'status' => $goalStatus,
                    'priority' => ['high', 'medium', 'medium', 'low', 'critical'][$g % 5],
                    'progress_percentage' => $progress,
                    'target_date' => now()->addDays(rand(14, 90)),
                    'created_by' => $userId,
                ]);

                $numNotes = rand(1, 3);
                for ($n = 0; $n < $numNotes; $n++) {
                    ProgressNote::create([
                        'organization_id' => null,
                        'client_id' => $client->id,
                        'care_plan_goal_id' => $goal->id,
                        'author_id' => $staffIds[array_rand($staffIds)],
                        'note_type' => ['general', 'goal_update', 'observation'][$n % 3],
                        'content' => $noteContents[($i + $g + $n) % count($noteContents)],
                        'mood_rating' => rand(4, 10),
                        'visibility' => ['staff_only', 'staff_only', 'include_family'][$n % 3],
                        'is_flagged' => $n === 0 && $g === 0 && $i === 0,
                        'created_at' => now()->subDays(rand(1, 30)),
                        'updated_at' => now()->subDays(rand(0, 15)),
                    ]);
                }
            }

            $this->command->info("  {$client->first_name}: {$numGoals} goals");
        }

        // Service Agreements
        foreach ($clients->take(5) as $i => $client) {
            ServiceAgreement::create([
                'organization_id' => null,
                'client_id' => $client->id,
                'title' => $client->first_name . "'s Funding Agreement " . now()->year,
                'reference_number' => 'SA-' . now()->format('Ym') . '-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                'agreement_type' => ['ndis', 'msd', 'private', 'dss', 'acc'][$i % 5],
                'funding_body' => ['NDIS', 'MSD', 'Private', 'DSS', 'ACC'][$i % 5],
                'status' => $i < 3 ? 'active' : 'draft',
                'starts_at' => now()->subMonths(rand(0, 3)),
                'ends_at' => now()->addMonths(rand(6, 12)),
                'total_budget' => rand(20000, 80000),
                'hourly_rate' => rand(35, 65),
                'created_by' => $userId,
            ]);
        }

        $this->command->info('Done! Care plans: ' . CarePlan::count() . ', Goals: ' . CarePlanGoal::count() . ', Notes: ' . ProgressNote::count());
    }
}
