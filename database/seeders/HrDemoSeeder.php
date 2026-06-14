<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Models\HrSurvey;
use App\Domain\Hr\Models\HrSurveyQuestion;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\FeedService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class HrDemoSeeder extends Seeder
{
    private const DEFAULT_TENANT_ID = 1;

    public function run(): void
    {
        $tenantId = $this->resolveTenantId();
        $admin = $this->demoUser('admin@demo.test', 'Demo Admin', 'admin');
        $manager = $this->demoUser('manager@demo.test', 'Demo Manager', 'provider_manager');
        $profiles = $this->profiles($tenantId, $admin, $manager);

        $this->seedLeaveRequests($tenantId, $admin, $manager, $profiles);
        $this->seedRecruitment($tenantId, $admin, $manager);
        $this->seedCase($tenantId, $admin, $manager, $profiles);
        $this->seedTimeEntries($tenantId, $admin, $manager, $profiles);
        $this->seedExpenseClaims($tenantId, $admin, $manager, $profiles);
        $this->seedPayrollRun($tenantId, $admin, $profiles);
        $this->seedDocuments($tenantId, $admin, $profiles);
        $this->seedPerformance($tenantId, $admin, $manager, $profiles);
        $this->seedAssets($tenantId, $manager, $profiles);
        $this->seedTraining($tenantId, $profiles);
        $this->seedAnnouncementsAndSurveys($tenantId, $admin);
        $this->seedRecognitionFeed($tenantId, $admin, $manager, $profiles);
    }

    /**
     * Seed the community feed: a couple of posts + a handful of peer kudos so
     * the recognition feed isn't empty in demo. Idempotent — skips if the feed
     * already has posts for this tenant.
     *
     * @param  Collection<int, HrEmployeeProfile>  $profiles
     */
    private function seedRecognitionFeed(int $tenantId, User $admin, User $manager, Collection $profiles): void
    {
        if (HrFeedPost::query()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $workers = $profiles->map(fn (HrEmployeeProfile $p) => $p->user)->filter()->values();
        if ($workers->count() < 1) {
            return;
        }

        // A couple of general feed posts.
        HrFeedPost::create([
            'tenant_id' => $tenantId,
            'user_id' => $admin->id,
            'post_type' => 'announcement',
            'content' => 'Welcome to the community feed — share wins and recognise your teammates here!',
            'is_pinned' => true,
        ]);
        HrFeedPost::create([
            'tenant_id' => $tenantId,
            'user_id' => $manager->id,
            'post_type' => 'update',
            'content' => 'Great effort covering the weekend roster, everyone. The new intake settled in smoothly.',
            'is_pinned' => false,
        ]);

        // A handful of peer kudos (each also creates a kudos feed post).
        $feed = app(FeedService::class);
        $givers = collect([$manager, $admin])->merge($workers)->values();
        $kudos = [
            ['category' => 'teamwork', 'message' => 'Stepped in to cover a short-notice sleepover — huge help to the team.'],
            ['category' => 'going_above', 'message' => 'Stayed back to support a client through a tough evening. Above and beyond.'],
            ['category' => 'customer_focus', 'message' => 'The family specifically called out how supported they felt this week.'],
            ['category' => 'innovation', 'message' => 'Reworked the handover checklist and it has saved everyone time.'],
            ['category' => 'leadership', 'message' => 'Calmly led the team through a busy shift and kept everyone on track.'],
        ];

        foreach ($kudos as $index => $entry) {
            $from = $givers[$index % $givers->count()];
            $to = $workers[$index % $workers->count()];
            if ($from->id === $to->id) {
                $to = $workers[($index + 1) % $workers->count()];
            }
            if ($from->id === $to->id) {
                continue; // not enough distinct users
            }

            $feed->sendKudos($from, $to->id, $entry['category'], $entry['message'], $tenantId);
        }
    }

    private function resolveTenantId(): int
    {
        return (int) (
            HrEmployeeProfile::query()->whereNotNull('tenant_id')->value('tenant_id')
            ?? User::query()->whereNotNull('organization_id')->value('organization_id')
            ?? self::DEFAULT_TENANT_ID
        );
    }

    private function demoUser(string $email, string $name, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'organization_id' => self::DEFAULT_TENANT_ID,
                'role' => $role,
                'password' => Hash::make('password'),
                'approved_at' => now(),
            ],
        );
    }

    /**
     * @return Collection<int, HrEmployeeProfile>
     */
    private function profiles(int $tenantId, User $admin, User $manager): Collection
    {
        $profiles = HrEmployeeProfile::query()
            ->with('user')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (HrEmployeeProfile $profile) => $profile->user !== null)
            ->values();

        for ($index = $profiles->count() + 1; $index <= 3; $index++) {
            $user = $this->demoUser("hrdemo.worker{$index}@example.test", "HR Demo Worker {$index}", 'support_worker');

            $profile = HrEmployeeProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'tenant_id' => $tenantId,
                    'employee_number' => sprintf('HR-DEMO-%03d', $index),
                    'personal_email' => $user->email,
                    'work_email' => $user->email,
                    'position_title' => 'Support Worker',
                    'position_role' => 'support_worker',
                    'employment_type' => 'full_time',
                    'contract_type' => 'permanent',
                    'hours_per_week' => 40,
                    'hourly_rate' => '32.50',
                    'pay_frequency' => 'fortnightly',
                    'start_date' => '2025-11-03',
                    'is_active' => true,
                    'manager_user_id' => $manager->id,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );

            $profile->setRelation('user', $user);
            $profiles->push($profile);
        }

        return $profiles->take(3)->values();
    }

    private function seedLeaveRequests(int $tenantId, User $admin, User $manager, Collection $profiles): void
    {
        $requests = [
            ['profile' => 0, 'type' => 'annual', 'start' => '2026-06-18', 'days' => 2, 'status' => 'pending', 'reason' => 'Family visit'],
            ['profile' => 1, 'type' => 'sick', 'start' => '2026-06-05', 'days' => 1, 'status' => 'approved', 'reason' => 'Medical appointment'],
            ['profile' => 2, 'type' => 'annual', 'start' => '2026-07-02', 'days' => 3, 'status' => 'approved', 'reason' => 'School holidays'],
            ['profile' => 0, 'type' => 'bereavement', 'start' => '2026-05-21', 'days' => 1, 'status' => 'rejected', 'reason' => 'Roster coverage unavailable'],
            ['profile' => 1, 'type' => 'annual', 'start' => '2026-08-12', 'days' => 1, 'status' => 'pending', 'reason' => 'Personal day'],
            ['profile' => 2, 'type' => 'sick', 'start' => '2026-05-30', 'days' => 1, 'status' => 'cancelled', 'reason' => 'Recovered before shift'],
        ];

        foreach ($requests as $request) {
            $profile = $profiles[$request['profile']];
            $startsAt = Carbon::parse($request['start'].' 00:00:00');
            $endsAt = $startsAt->copy()->addDays($request['days'] - 1)->endOfDay();
            $reviewed = in_array($request['status'], ['approved', 'rejected'], true);

            HrLeaveRequest::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $profile->user_id,
                    'leave_type' => $request['type'],
                    'starts_at' => $startsAt,
                ],
                [
                    'period' => $request['days'] === 1 ? 'full_day' : 'multi_day',
                    'ends_at' => $endsAt,
                    'hours_requested' => $request['days'] * 8,
                    'reason' => $request['reason'],
                    'status' => $request['status'],
                    'submitted_at' => $startsAt->copy()->subDays(12),
                    'approval_due_at' => $startsAt->copy()->subDays(5),
                    'reviewed_by' => $reviewed ? $manager->id : null,
                    'reviewed_at' => $reviewed ? $startsAt->copy()->subDays(8) : null,
                    'review_notes' => $reviewed ? 'Seeded HR demo review outcome.' : null,
                    'created_by' => $profile->user_id,
                ],
            );
        }
    }

    private function seedRecruitment(int $tenantId, User $admin, User $manager): void
    {
        $requisitions = collect([
            [
                'slug' => 'support-worker-demo',
                'title' => 'Support Worker',
                'role' => 'support_worker',
                'employment' => 'full_time',
                'openings' => 3,
                'status' => 'published',
            ],
            [
                'slug' => 'team-lead-demo',
                'title' => 'Team Lead',
                'role' => 'team_lead',
                'employment' => 'part_time',
                'openings' => 1,
                'status' => 'draft',
            ],
        ])->map(fn (array $data) => HrJobRequisition::updateOrCreate(
            ['tenant_id' => $tenantId, 'slug' => $data['slug']],
            [
                'title' => $data['title'],
                'position_role' => $data['role'],
                'employment_type' => $data['employment'],
                'openings' => $data['openings'],
                'status' => $data['status'],
                'summary' => "Demo {$data['title']} vacancy for recruitment workflow testing.",
                'description' => 'Seeded vacancy used to keep the recruitment dashboard populated.',
                'requirements' => 'Relevant care experience and current right to work in New Zealand.',
                'responsibilities' => 'Deliver safe, person-centred support and complete shift records.',
                'hiring_manager_user_id' => $manager->id,
                'posting_channels' => ['internal', 'careers_site'],
                'external_posting_status' => $data['status'] === 'published' ? 'posted' : 'not_posted',
                'external_reference' => $data['status'] === 'published' ? ['seek' => strtoupper($data['slug'])] : null,
                'published_at' => $data['status'] === 'published' ? Carbon::parse('2026-06-03 09:00:00') : null,
                'closing_at' => $data['status'] === 'published' ? '2026-07-03' : null,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        ));

        $candidates = [
            ['Ada', 'Kauri', 'ada.kauri@example.test', 'screening', 0, 'active'],
            ['Ben', 'Rangi', 'ben.rangi@example.test', 'interview_scheduled', 0, 'interview'],
            ['Cleo', 'Morgan', 'cleo.morgan@example.test', 'reference_check', 0, 'reference_check'],
            ['Dev', 'Patel', 'dev.patel@example.test', 'offer_pending', 1, 'offered'],
            ['Ella', 'Ngata', 'ella.ngata@example.test', 'new', 1, 'active'],
        ];

        foreach ($candidates as $candidateIndex => [$firstName, $lastName, $email, $stage, $jobIndex, $applicationStatus]) {
            $candidate = HrCandidate::updateOrCreate(
                ['tenant_id' => $tenantId, 'personal_email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'preferred_name' => $firstName,
                    'personal_phone' => sprintf('021 555 %03d', $candidateIndex + 101),
                    'source' => 'website',
                    'source_detail' => 'HR demo seed',
                    'status' => $stage,
                    'current_stage_entered_at' => Carbon::parse('2026-06-04 10:00:00')->subDays($jobIndex + 1),
                    'privacy_consent_given_at' => Carbon::parse('2026-06-01 12:00:00'),
                    'privacy_consent_ip' => '127.0.0.1',
                    'notes' => 'Seeded candidate for HR recruitment demo.',
                    'tags' => ['demo', $stage],
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );

            $job = $requisitions[$jobIndex];
            HrApplication::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'candidate_id' => $candidate->id,
                    'requisition_id' => $job->id,
                ],
                [
                    'position_title' => $job->title,
                    'position_role' => $job->position_role,
                    'cover_letter' => 'I am interested in this demo vacancy and available for interview.',
                    'answers' => ['availability' => 'Weekdays and alternate weekends'],
                    'screening_answers' => ['right_to_work' => true, 'driver_licence' => true],
                    'candidate_tracking_token' => substr(hash('sha256', $email), 0, 64),
                    'status' => $applicationStatus,
                ],
            );
        }
    }

    private function seedCase(int $tenantId, User $admin, User $manager, Collection $profiles): void
    {
        $profile = $profiles[0];

        HrCase::updateOrCreate(
            ['tenant_id' => $tenantId, 'case_number' => 'HR-DEMO-CASE-001'],
            [
                'user_id' => $profile->user_id,
                'case_type' => 'performance',
                'severity' => 'medium',
                'status' => 'open',
                'title' => 'Attendance follow-up plan',
                'description' => 'Seeded open case for dashboard and case-management review.',
                'reported_by' => $manager->id,
                'assigned_to' => $manager->id,
                'opened_at' => Carbon::parse('2026-06-07 11:30:00'),
                'is_confidential' => true,
                'access_list' => [$manager->id, $admin->id],
                'linked_incident_ids' => [],
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ],
        );
    }

    private function seedTimeEntries(int $tenantId, User $admin, User $manager, Collection $profiles): void
    {
        $start = Carbon::parse('2026-06-01 08:30:00');
        $created = 0;

        for ($day = 0; $day < 14; $day++) {
            $date = $start->copy()->addDays($day);

            if ($date->isWeekend()) {
                continue;
            }

            foreach ($profiles as $profile) {
                HrTimeEntry::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'user_id' => $profile->user_id,
                        'entry_date' => $date->toDateString(),
                        'project_code' => 'HR-DEMO',
                    ],
                    [
                        'clock_in' => $date,
                        'clock_out' => $date->copy()->addHours(8)->addMinutes(30),
                        'break_minutes' => 30,
                        'total_hours' => 8,
                        'entry_type' => 'manual',
                        'status' => $created % 4 === 0 ? 'submitted' : 'approved',
                        'notes' => 'Seeded manual time entry for HR demo payroll flow.',
                        'cost_centre' => 'CARE',
                        'source_type' => 'hr_demo',
                        'source_id' => $profile->id,
                        'pay_type' => 'standard',
                        'is_sleepover' => false,
                        'is_on_call' => false,
                        'is_public_holiday' => false,
                        'mileage_km' => $created % 5 === 0 ? 12.5 : null,
                        'break_compliance_met' => true,
                        'approved_by' => $created % 4 === 0 ? null : $manager->id,
                        'approved_at' => $created % 4 === 0 ? null : $date->copy()->addDay(),
                        'created_by' => $admin->id,
                    ],
                );

                $created++;
            }
        }
    }

    private function seedExpenseClaims(int $tenantId, User $admin, User $manager, Collection $profiles): void
    {
        $claims = [
            ['number' => 'EXP-DEMO-001', 'profile' => 0, 'title' => 'Mileage for community visits', 'status' => 'submitted', 'amount' => 48.50, 'category' => 'mileage'],
            ['number' => 'EXP-DEMO-002', 'profile' => 1, 'title' => 'Training workshop parking', 'status' => 'approved', 'amount' => 18.00, 'category' => 'travel'],
        ];

        foreach ($claims as $claimData) {
            $claim = HrExpenseClaim::updateOrCreate(
                ['tenant_id' => $tenantId, 'claim_number' => $claimData['number']],
                [
                    'user_id' => $profiles[$claimData['profile']]->user_id,
                    'title' => $claimData['title'],
                    'status' => $claimData['status'],
                    'total_amount' => $claimData['amount'],
                    'currency' => 'NZD',
                    'submitted_at' => Carbon::parse('2026-06-08 15:00:00'),
                    'approved_by' => $claimData['status'] === 'approved' ? $manager->id : null,
                    'approved_at' => $claimData['status'] === 'approved' ? Carbon::parse('2026-06-09 09:20:00') : null,
                    'notes' => 'Seeded expense claim for HR demo.',
                    'created_by' => $admin->id,
                ],
            );

            $claim->items()->updateOrCreate(
                ['description' => $claimData['title']],
                [
                    'category' => $claimData['category'],
                    'amount' => $claimData['amount'],
                    'expense_date' => '2026-06-06',
                    'tax_amount' => round($claimData['amount'] * 0.15, 2),
                    'notes' => 'Demo receipt placeholder.',
                ],
            );
        }
    }

    private function seedPayrollRun(int $tenantId, User $admin, Collection $profiles): void
    {
        HrPayrollRun::updateOrCreate(
            ['tenant_id' => $tenantId, 'period_start' => '2026-06-01', 'period_end' => '2026-06-14'],
            [
                'status' => 'locked',
                'locked_at' => Carbon::parse('2026-06-15 09:00:00'),
                'locked_by' => $admin->id,
                'export_format' => 'csv',
                'export_path' => 'exports/payroll/hr-demo-2026-06-14.csv',
                'total_hours' => 240,
                'total_gross' => 7800,
                'total_staff' => $profiles->count(),
                'notes' => 'Seeded locked payroll run for HR demo.',
                'validation_errors' => [],
                'created_by' => $admin->id,
            ],
        );
    }

    private function seedDocuments(int $tenantId, User $admin, Collection $profiles): void
    {
        $documents = [
            ['profile' => 0, 'title' => 'Signed employment agreement', 'category' => 'contract'],
            ['profile' => 1, 'title' => 'First aid certificate', 'category' => 'certification'],
            ['profile' => 2, 'title' => 'Driver licence copy', 'category' => 'credential'],
            ['profile' => 0, 'title' => 'Code of conduct acknowledgement', 'category' => 'policy'],
        ];

        foreach ($documents as $index => $document) {
            $slug = Str::slug($document['title']);

            HrDocument::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_profile_id' => $profiles[$document['profile']]->id,
                    'title' => $document['title'],
                ],
                [
                    'category' => $document['category'],
                    'folder' => 'Demo',
                    'storage_disk' => 'private',
                    'storage_path' => "hr/demo/{$slug}.pdf",
                    'original_name' => "{$slug}.pdf",
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 204800 + ($index * 1024),
                    'is_restricted' => $document['category'] === 'contract',
                    'generated_from_template' => $document['category'] === 'policy',
                    'sent_to_employee' => true,
                    'sent_at' => Carbon::parse('2026-06-04 10:00:00'),
                    'signed_by_employee' => in_array($document['category'], ['contract', 'policy'], true),
                    'signed_at' => in_array($document['category'], ['contract', 'policy'], true) ? Carbon::parse('2026-06-05 13:00:00') : null,
                    'expires_at' => $document['category'] === 'certification' ? '2027-06-05' : null,
                    'expiry_reminder_sent' => false,
                    'created_by' => $admin->id,
                    'uploaded_by' => $admin->id,
                ],
            );
        }
    }

    private function seedPerformance(int $tenantId, User $admin, User $manager, Collection $profiles): void
    {
        foreach ($profiles->take(2)->values() as $index => $profile) {
            HrPerformanceReview::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_user_id' => $profile->user_id,
                    'review_type' => $index === 0 ? 'annual' : 'probation',
                    'review_period_start' => '2026-01-01',
                ],
                [
                    'reviewer_user_id' => $manager->id,
                    'review_period_end' => '2026-06-30',
                    'status' => $index === 0 ? 'completed' : 'draft',
                    'overall_rating' => $index === 0 ? 4 : null,
                    'strengths' => 'Reliable communication and strong person-centred practice.',
                    'development_areas' => 'Continue building confidence with digital case notes.',
                    'goals' => [['title' => 'Complete medication refresher', 'due' => '2026-08-31']],
                    'training_recommendations' => ['Medication refresher', 'Positive behaviour support'],
                    'employee_signed_off' => $index === 0,
                    'employee_signed_off_at' => $index === 0 ? Carbon::parse('2026-06-09 12:00:00') : null,
                    'manager_signed_off' => $index === 0,
                    'manager_signed_off_at' => $index === 0 ? Carbon::parse('2026-06-09 12:15:00') : null,
                    'next_review_date' => '2026-12-09',
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ],
            );
        }

        HrSupervisionNote::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'employee_user_id' => $profiles[0]->user_id,
                'session_date' => '2026-06-10',
            ],
            [
                'supervisor_user_id' => $manager->id,
                'session_type' => 'one_on_one',
                'duration_minutes' => 45,
                'topics_discussed' => 'Roster preferences, wellbeing, and recent client feedback.',
                'actions_agreed' => ['Update availability by Friday', 'Shadow senior worker next sleepover'],
                'employee_comments' => 'Support plan is working well.',
                'employee_acknowledged' => true,
                'employee_acknowledged_at' => Carbon::parse('2026-06-10 14:00:00'),
                'next_session_date' => '2026-07-10',
                'is_visible_to_employee' => true,
                'created_by' => $manager->id,
            ],
        );
    }

    private function seedAssets(int $tenantId, User $manager, Collection $profiles): void
    {
        $assets = [
            ['tag' => 'HR-LAP-001', 'name' => 'Lenovo ThinkPad 14', 'category' => 'laptop', 'status' => 'assigned'],
            ['tag' => 'HR-PHN-001', 'name' => 'Samsung A-series phone', 'category' => 'phone', 'status' => 'available'],
            ['tag' => 'HR-KEY-001', 'name' => 'Office access fob', 'category' => 'key', 'status' => 'available'],
        ];

        $createdAssets = collect($assets)->map(fn (array $asset) => HrAsset::updateOrCreate(
            ['tenant_id' => $tenantId, 'asset_tag' => $asset['tag']],
            [
                'name' => $asset['name'],
                'category' => $asset['category'],
                'serial_number' => $asset['tag'].'-SER',
                'make' => Str::before($asset['name'], ' '),
                'model' => Str::after($asset['name'], ' '),
                'purchase_date' => '2026-01-15',
                'purchase_cost' => $asset['category'] === 'laptop' ? 1450 : 150,
                'warranty_expiry' => '2028-01-15',
                'status' => $asset['status'],
                'notes' => 'Seeded HR asset demo record.',
            ],
        ));

        HrAssetAssignment::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'asset_id' => $createdAssets[0]->id,
                'employee_profile_id' => $profiles[0]->id,
                'returned_at' => null,
            ],
            [
                'assigned_at' => Carbon::parse('2026-06-03 09:30:00'),
                'condition_on_assign' => 'good',
                'assigned_by' => $manager->id,
                'notes' => 'Laptop issued for community documentation.',
            ],
        );
    }

    private function seedTraining(int $tenantId, Collection $profiles): void
    {
        $courses = [
            ['code' => 'HR-DEMO-FIRST-AID', 'title' => 'First Aid Refresher', 'category' => 'safety', 'method' => 'in_person', 'mandatory' => true],
            ['code' => 'HR-DEMO-MEDS', 'title' => 'Medication Administration', 'category' => 'clinical', 'method' => 'blended', 'mandatory' => true],
            ['code' => 'HR-DEMO-PBS', 'title' => 'Positive Behaviour Support', 'category' => 'practice', 'method' => 'online', 'mandatory' => false],
            ['code' => 'HR-DEMO-PRIVACY', 'title' => 'Privacy and Records', 'category' => 'compliance', 'method' => 'self_paced', 'mandatory' => true],
        ];

        foreach ($courses as $index => $courseData) {
            $course = HrCourse::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $courseData['code']],
                [
                    'title' => $courseData['title'],
                    'description' => 'Seeded course for HR training demo coverage.',
                    'category' => $courseData['category'],
                    'delivery_method' => $courseData['method'],
                    'duration_hours' => $index + 2,
                    'provider' => 'Oblivion Findings Demo',
                    'cost' => $index === 0 ? 95 : 0,
                    'is_mandatory' => $courseData['mandatory'],
                    'max_participants' => 20,
                    'is_active' => true,
                ],
            );

            $profile = $profiles[$index % $profiles->count()];
            HrCourseEnrollment::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $profile->user_id,
                    'course_id' => $course->id,
                ],
                [
                    'status' => $index % 2 === 0 ? 'completed' : 'enrolled',
                    'enrolled_at' => Carbon::parse('2026-06-01 09:00:00')->addDays($index),
                    'completed_at' => $index % 2 === 0 ? Carbon::parse('2026-06-04 16:00:00')->addDays($index) : null,
                    'score' => $index % 2 === 0 ? 92 - $index : null,
                    'certificate_path' => $index % 2 === 0 ? "hr/demo/certificates/{$courseData['code']}.pdf" : null,
                    'notes' => 'Seeded enrollment for training compliance demo.',
                ],
            );
        }
    }

    private function seedAnnouncementsAndSurveys(int $tenantId, User $admin): void
    {
        $announcements = [
            ['title' => 'Roster updates for June', 'priority' => 'high', 'pinned' => true, 'ack' => true],
            ['title' => 'New training courses available', 'priority' => 'normal', 'pinned' => false, 'ack' => false],
        ];

        foreach ($announcements as $announcement) {
            HrAnnouncement::updateOrCreate(
                ['tenant_id' => $tenantId, 'title' => $announcement['title']],
                [
                    'content' => 'Seeded HR announcement for employee communications demo.',
                    'priority' => $announcement['priority'],
                    'target_audience' => 'all',
                    'target_value' => null,
                    'published_at' => Carbon::parse('2026-06-06 08:00:00'),
                    'expires_at' => Carbon::parse('2026-07-06 08:00:00'),
                    'is_pinned' => $announcement['pinned'],
                    'requires_acknowledgement' => $announcement['ack'],
                    'created_by' => $admin->id,
                ],
            );
        }

        $survey = HrSurvey::updateOrCreate(
            ['tenant_id' => $tenantId, 'title' => 'Winter wellbeing pulse'],
            [
                'description' => 'Short seeded pulse survey for HR engagement demo.',
                'survey_type' => 'pulse',
                'status' => 'active',
                'is_anonymous' => true,
                'starts_at' => Carbon::parse('2026-06-01 09:00:00'),
                'ends_at' => Carbon::parse('2026-06-30 17:00:00'),
                'created_by' => $admin->id,
            ],
        );

        foreach ([
            ['sort' => 1, 'text' => 'How manageable is your current workload?', 'type' => 'rating', 'options' => ['1', '2', '3', '4', '5']],
            ['sort' => 2, 'text' => 'What support would help this month?', 'type' => 'text', 'options' => null],
        ] as $question) {
            HrSurveyQuestion::updateOrCreate(
                ['survey_id' => $survey->id, 'sort_order' => $question['sort']],
                [
                    'question_text' => $question['text'],
                    'question_type' => $question['type'],
                    'options' => $question['options'],
                    'is_required' => $question['sort'] === 1,
                ],
            );
        }
    }
}
