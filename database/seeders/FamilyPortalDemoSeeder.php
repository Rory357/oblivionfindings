<?php

namespace Database\Seeders;

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\FamilyVisitRequest;
use App\Models\Shift;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\TimelineEventReaction;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class FamilyPortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Use Amelia Wilson (client 10) or first active client
        $client = Client::find(10) ?? Client::where('status', 'active')->first();

        if (!$client) {
            $this->command->warn('No active client found. Skipping portal demo seeder.');
            return;
        }

        $this->command->info("Seeding family portal demo data for {$client->first_name} {$client->last_name} (ID: {$client->id})");

        // Add personality data to client
        $client->update([
            'interests_hobbies' => 'Painting, baking, listening to music, going for walks in the park',
            'dietary_requirements' => 'Gluten-free, prefers warm meals',
            'mobility_needs' => 'Uses a walker for longer distances',
        ]);

        // Get support workers assigned to this client
        $workers = $client->supportWorkers()->get();
        if ($workers->isEmpty()) {
            $workers = User::where('role', 'support_worker')->limit(3)->get();
        }

        // Ensure a portal user exists for this client
        $portalUser = $client->portalUsers()
            ->wherePivot('relation', '!=', 'self')
            ->first();

        if (!$portalUser) {
            $portalUser = $client->portalUsers()->first();
        }

        if (!$portalUser) {
            // Create a guardian portal user for this client
            $nokRole = Role::where('name', 'next_of_kin')->first();

            $portalUser = User::updateOrCreate(
                ['email' => 'family.' . strtolower($client->first_name) . '@demo.test'],
                [
                    'name' => 'Guardian of ' . $client->first_name,
                    'password' => Hash::make('password'),
                    'role' => 'next_of_kin',
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ]
            );

            if ($nokRole) {
                $portalUser->roles()->syncWithoutDetaching([$nokRole->id]);
            }
            $portalUser->portalClients()->syncWithoutDetaching([$client->id => ['relation' => 'guardian']]);
        }

        // Ensure a predictable login email for demo purposes
        if (!str_contains($portalUser->email, 'family.')) {
            $portalUser->update(['email' => 'family.' . strtolower($client->first_name) . '@demo.test']);
        }

        $this->command->info("Portal login: {$portalUser->email} / password");

        // ── Shifts: Today ──────────────────────────────
        $today = Carbon::today();

        $morningWorker = $workers->get(0);
        $afternoonWorker = $workers->get(1) ?? $workers->get(0);
        $eveningWorker = $workers->get(2) ?? $workers->get(0);

        // Completed morning shift
        Shift::updateOrCreate(
            ['client_id' => $client->id, 'starts_at' => $today->copy()->setTime(7, 0)],
            [
                'user_id' => $morningWorker->id,
                'ends_at' => $today->copy()->setTime(11, 0),
                'actual_starts_at' => $today->copy()->setTime(6, 58),
                'actual_ends_at' => $today->copy()->setTime(11, 5),
                'status' => 'completed',
                'notes' => 'Great morning! Amelia had a lovely breakfast and we went for a short walk.',
            ]
        );

        // In-progress afternoon shift
        Shift::updateOrCreate(
            ['client_id' => $client->id, 'starts_at' => $today->copy()->setTime(12, 0)],
            [
                'user_id' => $afternoonWorker->id,
                'ends_at' => $today->copy()->setTime(16, 0),
                'actual_starts_at' => $today->copy()->setTime(12, 2),
                'status' => 'in_progress',
                'notes' => 'Afternoon activities planned: painting and music.',
            ]
        );

        // Scheduled evening shift
        Shift::updateOrCreate(
            ['client_id' => $client->id, 'starts_at' => $today->copy()->setTime(17, 0)],
            [
                'user_id' => $eveningWorker->id,
                'ends_at' => $today->copy()->setTime(21, 0),
                'status' => 'scheduled',
            ]
        );

        // ── Shifts: Rest of week ───────────────────────
        for ($d = 1; $d <= 6; $d++) {
            $day = $today->copy()->addDays($d);

            Shift::updateOrCreate(
                ['client_id' => $client->id, 'starts_at' => $day->copy()->setTime(8, 0)],
                [
                    'user_id' => $workers->random()->id,
                    'ends_at' => $day->copy()->setTime(14, 0),
                    'status' => 'scheduled',
                ]
            );

            if ($d % 2 === 0) {
                Shift::updateOrCreate(
                    ['client_id' => $client->id, 'starts_at' => $day->copy()->setTime(16, 0)],
                    [
                        'user_id' => $workers->random()->id,
                        'ends_at' => $day->copy()->setTime(20, 0),
                        'status' => 'scheduled',
                    ]
                );
            }
        }

        // ── Care Plan ──────────────────────────────────
        $carePlan = CarePlan::updateOrCreate(
            ['client_id' => $client->id, 'status' => 'active'],
            [
                'title' => "{$client->first_name}'s Support Plan",
                'plan_type' => 'individual',
                'starts_at' => now()->subMonths(3),
                'next_review_at' => now()->addMonths(3),
                'content' => [
                    'about_me' => [
                        'important_to_me' => "Being around people I trust, having time for my hobbies, and feeling safe. I love painting and listening to music — it helps me feel calm and happy.",
                        'ideal_day' => "I like to wake up slowly with a warm drink. In the morning I enjoy a walk or some time in the garden. Afternoons are for creative activities like painting. Evenings are quiet time with music or TV.",
                        'how_to_support' => "Give me choices rather than making decisions for me. Speak calmly and give me time to process. If I'm feeling anxious, music usually helps.",
                        'likes' => "Painting, baking, walks in nature, warm drinks, music (especially classical), visiting the local café",
                        'dislikes' => "Loud sudden noises, being rushed, crowds, cold weather",
                    ],
                ],
                'version' => 1,
            ]
        );

        // Goals
        $goals = [
            ['title' => 'Join a weekly art class', 'status' => 'completed', 'category' => 'social', 'priority' => 'high'],
            ['title' => 'Walk 20 minutes daily', 'status' => 'in_progress', 'category' => 'health', 'priority' => 'medium'],
            ['title' => 'Try baking something new each week', 'status' => 'in_progress', 'category' => 'independence', 'priority' => 'low'],
            ['title' => 'Attend community coffee mornings', 'status' => 'completed', 'category' => 'social', 'priority' => 'medium'],
            ['title' => 'Manage morning routine independently', 'status' => 'completed', 'category' => 'independence', 'priority' => 'high'],
        ];

        foreach ($goals as $goal) {
            CarePlanGoal::updateOrCreate(
                ['care_plan_id' => $carePlan->id, 'title' => $goal['title']],
                [
                    'client_id' => $client->id,
                    'description' => 'Goal set as part of care plan.',
                    'category' => $goal['category'],
                    'status' => $goal['status'],
                    'priority' => $goal['priority'],
                    'target_date' => now()->addMonths(2)->toDateString(),
                ]
            );
        }

        // ── Timeline Events ────────────────────────────
        $events = [
            [
                'subject' => "{$client->first_name} painted a beautiful sunset today!",
                'body' => "She was so proud of her painting. The art teacher said it was her best work yet. She's really blossoming in the weekly class.",
                'type' => 'care',
                'occurred_at' => now()->subHours(3),
                'actor' => $morningWorker,
            ],
            [
                'subject' => 'Morning walk completed',
                'body' => "Walked to the park and back — about 25 minutes. {$client->first_name} was in great spirits and stopped to chat with a neighbour.",
                'type' => 'care',
                'occurred_at' => now()->subHours(6),
                'actor' => $morningWorker,
            ],
            [
                'subject' => 'Visit from family approved',
                'body' => 'Saturday afternoon visit confirmed. Looking forward to seeing everyone!',
                'type' => 'visits',
                'occurred_at' => now()->subDay(),
                'actor' => null,
            ],
            [
                'subject' => 'Tried a new banana bread recipe',
                'body' => "{$client->first_name} baked banana bread with the afternoon team. She measured all the ingredients herself and the result was delicious!",
                'type' => 'care',
                'occurred_at' => now()->subDays(2),
                'actor' => $afternoonWorker,
            ],
            [
                'subject' => 'Weekly art class attended',
                'body' => "Great session today. The group painted spring flowers. {$client->first_name} helped a new member feel welcome.",
                'type' => 'care',
                'occurred_at' => now()->subDays(3),
                'actor' => $morningWorker,
            ],
            [
                'subject' => 'GP check-up completed',
                'body' => 'Routine check-up went well. No concerns raised. Next appointment in 6 months.',
                'type' => 'other',
                'occurred_at' => now()->subDays(4),
                'actor' => $afternoonWorker,
            ],
            [
                'subject' => 'Community coffee morning',
                'body' => "{$client->first_name} attended the community coffee morning at the local hall. She had a wonderful time and made a new friend.",
                'type' => 'care',
                'occurred_at' => now()->subDays(5),
                'actor' => $eveningWorker,
            ],
            [
                'subject' => 'New painting supplies arrived',
                'body' => "Received a lovely set of watercolour paints. {$client->first_name} was very excited to try them out.",
                'type' => 'other',
                'occurred_at' => now()->subDays(6),
                'actor' => null,
            ],
            [
                'subject' => 'Enjoyed a movie afternoon',
                'body' => "Watched a favourite film with popcorn. {$client->first_name} sang along to some of the songs — it was wonderful to see her so happy.",
                'type' => 'care',
                'occurred_at' => now()->subDays(7),
                'actor' => $eveningWorker,
            ],
            [
                'subject' => 'Garden time',
                'body' => 'Spent time in the garden watering plants and planting some new herbs. Really enjoyed being outdoors.',
                'type' => 'care',
                'occurred_at' => now()->subDays(8),
                'actor' => $morningWorker,
            ],
            // ── New event types ────────────────────────
            [
                'subject' => 'Given: Paracetamol 500mg',
                'body' => 'Administered as scheduled. No adverse effects noted.',
                'type' => 'medication_given',
                'occurred_at' => now()->subHours(2),
                'actor' => $morningWorker,
            ],
            [
                'subject' => 'Given: Fluoxetine 20mg',
                'body' => 'Morning dose administered with breakfast.',
                'type' => 'medication_given',
                'occurred_at' => now()->subHours(5),
                'actor' => $morningWorker,
            ],
            [
                'subject' => 'Refused: Ibuprofen 400mg',
                'body' => "{$client->first_name} declined — said she wasn't in pain today.",
                'type' => 'medication_refused',
                'occurred_at' => now()->subDays(1)->subHours(3),
                'actor' => $afternoonWorker,
            ],
            [
                'subject' => 'Document uploaded: GP Letter',
                'body' => 'Letter from Dr. Patel regarding latest check-up results.',
                'type' => 'document_uploaded',
                'occurred_at' => now()->subDays(3)->subHours(2),
                'actor' => $afternoonWorker,
            ],
            [
                'subject' => 'Condition added: Mild Anxiety',
                'body' => 'Manageable with current support plan. Review in 3 months.',
                'type' => 'condition_added',
                'occurred_at' => now()->subDays(5)->subHours(4),
                'actor' => $morningWorker,
            ],
            [
                'subject' => "Care plan created: {$client->first_name}'s Support Plan",
                'body' => 'Individual support plan established with goals for social engagement, health, and independence.',
                'type' => 'care_plan_created',
                'occurred_at' => now()->subDays(10),
                'actor' => $morningWorker,
            ],
            [
                'subject' => 'Visit request: In Person on ' . now()->addDays(3)->format('j M'),
                'body' => 'Family requested an in-person visit to bake together.',
                'type' => 'visit_requested',
                'occurred_at' => now()->subDays(2),
                'actor' => null,
            ],
            [
                'subject' => 'Medication added: Vitamin D 1000IU',
                'body' => 'Prescribed by GP — daily supplement.',
                'type' => 'medication_prescribed',
                'occurred_at' => now()->subDays(4)->subHours(2),
                'actor' => $afternoonWorker,
            ],
        ];

        $createdEvents = collect();

        foreach ($events as $idx => $e) {
            $event = TimelineEvent::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'source_type' => 'portal_demo',
                    'source_id' => $client->id * 100 + $idx,
                    'type' => $e['type'],
                ],
                [
                    'subject' => $e['subject'],
                    'body' => $e['body'],
                    'occurred_at' => $e['occurred_at'],
                    'visibility' => 'portal',
                    'actor_user_id' => $e['actor']?->id,
                ]
            );
            $createdEvents->push($event);
        }

        // ── Reactions on timeline events ────────────────
        $reactionEmojis = ['❤️', '👍', '😊', '🎉', '🙏', '💛'];
        $allPortalUsers = $client->portalUsers()->get();

        if ($allPortalUsers->isNotEmpty()) {
            // Clear all existing reactions for these events
            TimelineEventReaction::whereIn('timeline_event_id', $createdEvents->pluck('id'))->delete();

            // Add reactions to the first few events
            foreach ($createdEvents->take(6) as $idx => $event) {
                foreach ($allPortalUsers as $user) {
                    // Each user reacts to some events with 1-2 emojis
                    if (rand(0, 1) === 0) continue;

                    $emojisToReact = collect($reactionEmojis)->shuffle()->take(rand(1, 2))->all();
                    foreach ($emojisToReact as $emoji) {
                        TimelineEventReaction::firstOrCreate([
                            'timeline_event_id' => $event->id,
                            'user_id' => $user->id,
                            'emoji' => $emoji,
                        ]);
                    }
                }
            }
        }

        // ── Comments on timeline events ─────────────────
        if ($portalUser) {
            $comments = [
                0 => ['So proud of her! Can\'t wait to see the painting 💜', 'That sounds amazing, thank you for sharing!'],
                1 => ['Lovely to hear she\'s getting out and about!'],
                3 => ['She\'s always loved baking! Thank you for encouraging her.'],
                4 => ['So glad the art class is going well, it means so much to her.'],
                6 => ['Making new friends is wonderful news!'],
            ];

            foreach ($comments as $eventIdx => $commentBodies) {
                if (isset($createdEvents[$eventIdx])) {
                    // Clear existing comments for this event
                    TimelineEventComment::where('timeline_event_id', $createdEvents[$eventIdx]->id)->delete();

                    foreach ($commentBodies as $bodyIdx => $body) {
                        TimelineEventComment::create([
                            'timeline_event_id' => $createdEvents[$eventIdx]->id,
                            'user_id' => $portalUser->id,
                            'body' => $body,
                            'created_at' => $createdEvents[$eventIdx]->occurred_at->copy()->addHours($bodyIdx + 1),
                            'updated_at' => $createdEvents[$eventIdx]->occurred_at->copy()->addHours($bodyIdx + 1),
                        ]);
                    }
                }
            }
        }

        // ── Visit Requests ─────────────────────────────
        if ($portalUser) {
            FamilyVisitRequest::updateOrCreate(
                ['user_id' => $portalUser->id, 'client_id' => $client->id, 'requested_date' => $today->copy()->addDays(3)->toDateString()],
                [
                    'preferred_time_start' => '14:00',
                    'preferred_time_end' => '16:00',
                    'visit_type' => 'in_person',
                    'notes' => 'Would love to bring some baking supplies and bake together!',
                    'status' => 'approved',
                    'reviewed_at' => now()->subDay(),
                    'review_notes' => 'Approved! The kitchen will be available.',
                ]
            );

            FamilyVisitRequest::updateOrCreate(
                ['user_id' => $portalUser->id, 'client_id' => $client->id, 'requested_date' => $today->copy()->addDays(7)->toDateString()],
                [
                    'preferred_time_start' => '10:00',
                    'preferred_time_end' => '12:00',
                    'visit_type' => 'outing',
                    'notes' => 'Planning to take her to the botanical gardens if weather is nice.',
                    'status' => 'pending',
                ]
            );

            FamilyVisitRequest::updateOrCreate(
                ['user_id' => $portalUser->id, 'client_id' => $client->id, 'requested_date' => $today->copy()->addDays(14)->toDateString()],
                [
                    'preferred_time_start' => '15:00',
                    'preferred_time_end' => '16:00',
                    'visit_type' => 'video_call',
                    'notes' => 'Video call with extended family in Wellington.',
                    'status' => 'pending',
                ]
            );
        }

        // ── Client Appointments ─────────────────────
        $appointments = [
            [
                'title' => 'GP Visit - Dr. Patel',
                'appointment_type' => 'gp_visit',
                'starts_at' => $today->copy()->addDays(5)->setTime(10, 0),
                'ends_at' => $today->copy()->addDays(5)->setTime(10, 30),
                'location' => 'Riverside Medical Centre',
                'provider_name' => 'Dr. Patel',
                'description' => 'Routine check-up and medication review.',
                'share_with_family' => true,
            ],
            [
                'title' => 'Physiotherapy Session',
                'appointment_type' => 'therapy',
                'starts_at' => $today->copy()->addDays(2)->setTime(14, 0),
                'ends_at' => $today->copy()->addDays(2)->setTime(15, 0),
                'location' => 'Active Health Physio',
                'provider_name' => 'Sarah Thompson',
                'description' => 'Weekly physio session — mobility exercises.',
                'share_with_family' => true,
            ],
            [
                'title' => 'Art Class',
                'appointment_type' => 'activity',
                'starts_at' => $today->copy()->addDays(3)->setTime(10, 0),
                'ends_at' => $today->copy()->addDays(3)->setTime(12, 0),
                'location' => 'Community Hall',
                'description' => 'Weekly watercolour painting class.',
                'share_with_family' => true,
            ],
            [
                'title' => 'Dentist Appointment',
                'appointment_type' => 'specialist',
                'starts_at' => $today->copy()->addDays(10)->setTime(9, 0),
                'ends_at' => $today->copy()->addDays(10)->setTime(9, 45),
                'location' => 'Smile Dental',
                'provider_name' => 'Dr. Chen',
                'description' => 'Routine dental check-up.',
                'share_with_family' => true,
            ],
            [
                'title' => 'Staff Review Meeting',
                'appointment_type' => 'other',
                'starts_at' => $today->copy()->addDays(4)->setTime(15, 0),
                'ends_at' => $today->copy()->addDays(4)->setTime(15, 30),
                'description' => 'Internal care plan review with team.',
                'share_with_family' => false,
            ],
        ];

        foreach ($appointments as $appt) {
            ClientAppointment::updateOrCreate(
                ['client_id' => $client->id, 'title' => $appt['title']],
                [
                    ...$appt,
                    'client_id' => $client->id,
                    'created_by' => $workers->first()?->id,
                ]
            );
        }

        $this->command->info('Family portal demo data seeded successfully!');
    }
}
