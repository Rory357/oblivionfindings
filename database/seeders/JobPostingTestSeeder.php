<?php

namespace Database\Seeders;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCandidateDocument;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JobPostingTestSeeder extends Seeder
{
    private int $tenantId = 1;
    private ?User $admin = null;

    public function run(): void
    {
        $this->admin = User::first();
        if (! $this->admin) {
            $this->command->warn('No user found — skipping seeder.');
            return;
        }

        $this->tenantId = $this->admin->tenant_id ?? 1;

        $this->command->info('Seeding job postings & recruitment pipeline test data...');

        $postings = $this->seedJobPostings();
        $this->seedCandidatesAndApplications($postings);
        $this->seedCandidateDocuments();

        $this->command->info('Done! Created job postings with candidates at every pipeline stage.');
    }

    /* ------------------------------------------------------------------ */
    /*  Job Postings                                                       */
    /* ------------------------------------------------------------------ */

    private function seedJobPostings(): array
    {
        $postings = [];

        // 1. Published posting with screening questions and salary
        $postings['support_worker'] = HrJobPosting::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'slug' => 'support-worker-day-services'],
            [
                'title' => 'Support Worker - Day Services',
                'department' => 'Day Services',
                'location' => 'Christchurch',
                'employment_type' => 'full_time',
                'is_remote' => false,
                'is_internal' => false,
                'summary' => 'Join our day services team providing community-based support to adults with intellectual disabilities.',
                'description' => "We are looking for compassionate Support Workers to join our Day Services team in Christchurch.\n\nYou will work alongside people with intellectual disabilities, supporting them to:\n- Participate in community activities and recreation\n- Develop independent living skills\n- Build and maintain social connections\n- Achieve their personal goals\n\nThis is a rewarding role where you can make a real difference in people's lives every day.",
                'responsibilities' => "- Deliver person-centred support aligned with individual support plans\n- Facilitate community participation and social inclusion activities\n- Support daily living skills development\n- Maintain accurate and timely documentation\n- Participate in team meetings and professional development\n- Follow health and safety procedures at all times",
                'requirements' => "- Experience in disability support or a related field (preferred)\n- Valid NZ driver's licence (full)\n- First Aid certificate (or willingness to obtain)\n- Clear Police vetting\n- Strong communication and interpersonal skills\n- Commitment to the principles of Te Tiriti o Waitangi",
                'salary_range_min' => 24.00,
                'salary_range_max' => 28.50,
                'show_salary' => true,
                'status' => 'published',
                'published_at' => now()->subDays(14),
                'closes_at' => now()->addDays(16),
                'views_count' => 187,
                'applications_count' => 0, // Will be updated
                'notification_emails' => ['recruitment@example.co.nz', 'dayservices.manager@example.co.nz'],
                'hiring_manager_id' => $this->admin->id,
                'screening_questions' => [
                    ['id' => 'q1', 'question' => 'Do you have a valid full NZ driver\'s licence?', 'type' => 'yes_no', 'required' => true],
                    ['id' => 'q2', 'question' => 'How many years of experience do you have in disability support?', 'type' => 'number', 'required' => true],
                    ['id' => 'q3', 'question' => 'Are you eligible to work in New Zealand?', 'type' => 'yes_no', 'required' => true],
                    ['id' => 'q4', 'question' => 'What interests you about this role?', 'type' => 'text', 'required' => false],
                ],
                'created_by' => $this->admin->id,
            ]
        );

        // 2. Published posting - closing soon (2 days)
        $postings['registered_nurse'] = HrJobPosting::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'slug' => 'registered-nurse-residential'],
            [
                'title' => 'Registered Nurse - Residential Care',
                'department' => 'Residential Services',
                'location' => 'Hamilton',
                'employment_type' => 'full_time',
                'is_remote' => false,
                'is_internal' => false,
                'summary' => 'Experienced RN needed for our residential care facility providing 24/7 support.',
                'description' => "We are seeking a Registered Nurse to join our residential care team in Hamilton.\n\nYou will provide clinical leadership and nursing care to residents with complex health needs in a supported living environment.\n\nThis role involves working across day and evening shifts with a focus on holistic, person-centred care.",
                'requirements' => "- Current NZ Nursing Council registration\n- Minimum 2 years post-registration experience\n- Experience in disability or aged care (preferred)\n- Medication management competency\n- Current First Aid certificate",
                'salary_range_min' => 75000,
                'salary_range_max' => 90000,
                'show_salary' => true,
                'status' => 'published',
                'published_at' => now()->subDays(25),
                'closes_at' => now()->addDays(2),
                'views_count' => 342,
                'applications_count' => 0,
                'notification_emails' => ['recruitment@example.co.nz'],
                'hiring_manager_id' => $this->admin->id,
                'screening_questions' => [
                    ['id' => 'q1', 'question' => 'Do you hold current NZ Nursing Council registration?', 'type' => 'yes_no', 'required' => true],
                    ['id' => 'q2', 'question' => 'Years of post-registration experience?', 'type' => 'number', 'required' => true],
                ],
                'created_by' => $this->admin->id,
            ]
        );

        // 3. Draft posting
        $postings['coordinator'] = HrJobPosting::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'slug' => 'service-coordinator-auckland'],
            [
                'title' => 'Service Coordinator',
                'department' => 'Operations',
                'location' => 'Auckland',
                'employment_type' => 'full_time',
                'is_remote' => false,
                'is_internal' => false,
                'summary' => 'Coordinate service delivery across Auckland residential and community services.',
                'description' => "We are looking for an organised and proactive Service Coordinator to manage service delivery across our Auckland operations.\n\nYou will coordinate rosters, manage client relationships, and ensure compliance with funding agreements and quality standards.",
                'requirements' => "- Experience in service coordination or operations management\n- Strong organisational and time management skills\n- Knowledge of NZ disability sector (preferred)\n- Proficient in Microsoft Office and rostering systems",
                'salary_range_min' => 60000,
                'salary_range_max' => 72000,
                'show_salary' => false,
                'status' => 'draft',
                'views_count' => 0,
                'applications_count' => 0,
                'created_by' => $this->admin->id,
            ]
        );

        // 4. Internal-only posting
        $postings['team_lead_internal'] = HrJobPosting::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'slug' => 'team-leader-internal-promotion'],
            [
                'title' => 'Team Leader - Internal Promotion',
                'department' => 'Community Living Services',
                'location' => 'Wellington',
                'employment_type' => 'full_time',
                'is_remote' => false,
                'is_internal' => true,
                'summary' => 'Internal opportunity for experienced support workers to step into a leadership role.',
                'description' => "This is an internal-only opportunity for current staff members looking to advance into a Team Leader position.\n\nYou will lead a small team of support workers, manage day-to-day operations, and ensure quality service delivery.",
                'salary_range_min' => 58000,
                'salary_range_max' => 65000,
                'show_salary' => true,
                'status' => 'published',
                'published_at' => now()->subDays(5),
                'closes_at' => now()->addDays(10),
                'views_count' => 23,
                'applications_count' => 0,
                'created_by' => $this->admin->id,
            ]
        );

        // 5. Closed posting
        $postings['admin_closed'] = HrJobPosting::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'slug' => 'administrator-closed'],
            [
                'title' => 'Office Administrator',
                'department' => 'Administration',
                'location' => 'Auckland',
                'employment_type' => 'part_time',
                'is_remote' => true,
                'is_internal' => false,
                'summary' => 'Part-time admin role supporting our Auckland office operations.',
                'description' => 'General office administration including reception, filing, correspondence, and supporting the management team.',
                'salary_range_min' => 23.50,
                'salary_range_max' => 26.00,
                'show_salary' => true,
                'status' => 'closed',
                'published_at' => now()->subDays(45),
                'closes_at' => now()->subDays(5),
                'views_count' => 256,
                'applications_count' => 0,
                'created_by' => $this->admin->id,
            ]
        );

        // 6. Pending approval posting
        $postings['pending'] = HrJobPosting::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'slug' => 'quality-assurance-lead'],
            [
                'title' => 'Quality Assurance Lead',
                'department' => 'Quality & Compliance',
                'location' => 'Wellington',
                'employment_type' => 'full_time',
                'is_remote' => false,
                'is_internal' => false,
                'requires_approval' => true,
                'summary' => 'Lead quality improvement initiatives across all service lines.',
                'description' => "Oversee quality assurance and continuous improvement across our residential and community services.\n\nEnsure compliance with NZ Health and Disability Services Standards and HealthCERT and Te Whatu Ora requirements.",
                'status' => 'pending_approval',
                'views_count' => 0,
                'applications_count' => 0,
                'created_by' => $this->admin->id,
            ]
        );

        return $postings;
    }

    /* ------------------------------------------------------------------ */
    /*  Candidates at every pipeline stage                                 */
    /* ------------------------------------------------------------------ */

    private function seedCandidatesAndApplications(array $postings): void
    {
        $posting = $postings['support_worker'];
        $appCount = 0;

        // --- Stage: NEW ---
        $this->createCandidateWithApplication($posting, [
            'first_name' => 'Tane', 'last_name' => 'Mahuta', 'email' => 'tane.mahuta@example.co.nz',
            'phone' => '021 555 0101', 'source' => 'website', 'stage' => 'new',
            'screening_answers' => ['q1' => 'yes', 'q2' => '0', 'q3' => 'yes', 'q4' => 'I love helping people.'],
        ]);
        $appCount++;

        // --- Stage: SCREENING ---
        $this->createCandidateWithApplication($posting, [
            'first_name' => 'Maia', 'last_name' => 'Tui', 'email' => 'maia.tui@example.co.nz',
            'phone' => '021 555 0102', 'source' => 'referral', 'stage' => 'screening',
        ]);
        $appCount++;

        // --- Stage: INTERVIEW_SCHEDULED (with scheduled interview) ---
        $candidate3 = $this->createCandidateWithApplication($posting, [
            'first_name' => 'Wiremu', 'last_name' => 'Kahu', 'email' => 'wiremu.kahu@example.co.nz',
            'phone' => '027 555 0103', 'source' => 'website', 'stage' => 'interview_scheduled',
        ]);
        if ($candidate3) {
            $app = $candidate3->applications()->latest()->first();
            if ($app) {
                HrInterview::firstOrCreate(
                    ['application_id' => $app->id, 'interview_type' => 'in_person'],
                    [
                        'scheduled_at' => now()->addDays(3)->setHour(10),
                        'duration_minutes' => 45,
                        'location' => 'Christchurch Office - Meeting Room 2',
                        'interview_type' => 'in_person',
                        'interviewers' => [['id' => $this->admin->id, 'name' => $this->admin->name]],
                        'status' => 'scheduled',
                    ]
                );
            }
        }
        $appCount++;

        // --- Stage: INTERVIEW_COMPLETED (with completed interview + scores) ---
        $candidate4 = $this->createCandidateWithApplication($posting, [
            'first_name' => 'Aroha', 'last_name' => 'Ngata', 'email' => 'aroha.ngata@example.co.nz',
            'phone' => '022 555 0104', 'source' => 'website', 'stage' => 'interview_completed',
        ]);
        if ($candidate4) {
            $app = $candidate4->applications()->latest()->first();
            if ($app) {
                HrInterview::firstOrCreate(
                    ['application_id' => $app->id, 'interview_type' => 'in_person'],
                    [
                        'scheduled_at' => now()->subDays(2)->setHour(14),
                        'duration_minutes' => 45,
                        'location' => 'Christchurch Office',
                        'interview_type' => 'in_person',
                        'interviewers' => [['id' => $this->admin->id, 'name' => $this->admin->name]],
                        'status' => 'completed',
                        'rating' => 4,
                        'outcome' => 'pass',
                        'notes' => 'Strong candidate. Excellent communication skills and genuine passion for support work. Recommended for reference checks.',
                        'completed_by' => $this->admin->id,
                    ]
                );
            }
        }
        $appCount++;

        // --- Stage: REFERENCE_CHECK (with references requested) ---
        $candidate5 = $this->createCandidateWithApplication($posting, [
            'first_name' => 'Nikau', 'last_name' => 'Rata', 'email' => 'nikau.rata@example.co.nz',
            'phone' => '021 555 0105', 'source' => 'agency', 'stage' => 'reference_check',
        ]);
        if ($candidate5) {
            $app = $candidate5->applications()->latest()->first();
            if ($app) {
                HrInterview::firstOrCreate(
                    ['application_id' => $app->id, 'interview_type' => 'virtual'],
                    [
                        'scheduled_at' => now()->subDays(5),
                        'duration_minutes' => 30,
                        'location' => 'Teams',
                        'interview_type' => 'virtual',
                        'status' => 'completed',
                        'rating' => 5,
                        'outcome' => 'pass',
                        'completed_by' => $this->admin->id,
                    ]
                );
                HrReferenceCheck::firstOrCreate(
                    ['application_id' => $app->id, 'referee_name' => 'Sarah Williams'],
                    [
                        'referee_email' => 'sarah.williams@prevemployer.co.nz',
                        'referee_phone' => '09 555 1234',
                        'referee_relationship' => 'Previous Manager',
                        'status' => 'received',
                        'requested_at' => now()->subDays(3),
                        'received_at' => now()->subDay(),
                        'reference_notes' => 'Positive reference. Nikau was reliable, punctual, and well-liked by clients.',
                    ]
                );
                HrReferenceCheck::firstOrCreate(
                    ['application_id' => $app->id, 'referee_name' => 'David Chen'],
                    [
                        'referee_email' => 'david.chen@communitytrust.co.nz',
                        'referee_relationship' => 'Colleague',
                        'status' => 'requested',
                        'requested_at' => now()->subDays(2),
                    ]
                );
            }
        }
        $appCount++;

        // --- Stage: OFFER_PENDING (all references complete, preparing offer) ---
        $candidate6 = $this->createCandidateWithApplication($posting, [
            'first_name' => 'Kiri', 'last_name' => 'Pounamu', 'email' => 'kiri.pounamu@example.co.nz',
            'phone' => '027 555 0106', 'source' => 'website', 'stage' => 'offer_pending',
        ]);
        if ($candidate6) {
            $app = $candidate6->applications()->latest()->first();
            if ($app) {
                HrInterview::create([
                    'application_id' => $app->id, 'scheduled_at' => now()->subDays(10),
                    'duration_minutes' => 45, 'interview_type' => 'in_person', 'status' => 'completed',
                    'rating' => 5, 'outcome' => 'pass', 'completed_by' => $this->admin->id,
                ]);
                HrReferenceCheck::create([
                    'application_id' => $app->id, 'referee_name' => 'Lisa Thompson',
                    'referee_relationship' => 'Manager', 'status' => 'completed',
                    'requested_at' => now()->subDays(7), 'received_at' => now()->subDays(4),
                    'verified_at' => now()->subDays(3), 'verified_by' => $this->admin->id,
                    'reference_notes' => 'Outstanding candidate. Highly recommended.',
                ]);
            }
        }
        $appCount++;

        // --- Stage: OFFER_SENT (offer created, approved, sent) ---
        $candidate7 = $this->createCandidateWithApplication($posting, [
            'first_name' => 'Rawiri', 'last_name' => 'Harawira', 'email' => 'rawiri.h@example.co.nz',
            'phone' => '022 555 0107', 'source' => 'referral', 'stage' => 'offer_sent',
            'application_status' => 'offered',
        ]);
        if ($candidate7) {
            $app = $candidate7->applications()->latest()->first();
            if ($app) {
                HrInterview::create([
                    'application_id' => $app->id, 'scheduled_at' => now()->subDays(15),
                    'duration_minutes' => 45, 'interview_type' => 'in_person', 'status' => 'completed',
                    'rating' => 4, 'outcome' => 'pass', 'completed_by' => $this->admin->id,
                ]);
                HrReferenceCheck::create([
                    'application_id' => $app->id, 'referee_name' => 'James Moana',
                    'referee_relationship' => 'Supervisor', 'status' => 'completed',
                    'requested_at' => now()->subDays(12), 'received_at' => now()->subDays(9),
                    'verified_at' => now()->subDays(8), 'verified_by' => $this->admin->id,
                ]);
                HrOffer::firstOrCreate(
                    ['application_id' => $app->id],
                    [
                        'position_title' => 'Support Worker - Day Services',
                        'position_role' => 'support_worker',
                        'proposed_start_date' => now()->addWeeks(3)->startOfWeek(),
                        'employment_type' => 'full_time',
                        'hours_per_week' => 40,
                        'hourly_rate' => 27.00,
                        'annual_salary' => 56160,
                        'conditions' => "- Subject to satisfactory Police vetting\n- 90-day trial period applies\n- First Aid certification required within 3 months",
                        'approval_status' => 'approved',
                        'approved_by' => $this->admin->id,
                        'approved_at' => now()->subDays(4),
                        'sent_at' => now()->subDays(3),
                        'candidate_portal_token' => Str::random(64),
                        'portal_expires_at' => now()->addDays(7),
                        'created_by' => $this->admin->id,
                        'updated_by' => $this->admin->id,
                    ]
                );
            }
        }
        $appCount++;

        // --- REJECTED candidate ---
        $this->createCandidateWithApplication($posting, [
            'first_name' => 'Sam', 'last_name' => 'Baker', 'email' => 'sam.baker@example.co.nz',
            'phone' => '021 555 0108', 'source' => 'website', 'stage' => 'rejected',
            'application_status' => 'rejected',
            'rejection_reason' => 'Candidate did not meet minimum experience requirements.',
        ]);
        $appCount++;

        // Update applications_count on the posting
        $posting->update(['applications_count' => $appCount]);

        // Also add a couple candidates to the RN posting
        $rnPosting = $postings['registered_nurse'];
        $rnCount = 0;

        $this->createCandidateWithApplication($rnPosting, [
            'first_name' => 'Emma', 'last_name' => 'Wilson', 'email' => 'emma.wilson@example.co.nz',
            'phone' => '021 555 0201', 'source' => 'website', 'stage' => 'screening',
            'screening_answers' => ['q1' => 'yes', 'q2' => '5'],
        ]);
        $rnCount++;

        $this->createCandidateWithApplication($rnPosting, [
            'first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'priya.sharma@example.co.nz',
            'phone' => '022 555 0202', 'source' => 'agency', 'stage' => 'new',
        ]);
        $rnCount++;

        $rnPosting->update(['applications_count' => $rnCount]);

        // Closed posting had some applications
        $closedPosting = $postings['admin_closed'];
        $this->createCandidateWithApplication($closedPosting, [
            'first_name' => 'Lucy', 'last_name' => 'Brown', 'email' => 'lucy.brown@example.co.nz',
            'source' => 'website', 'stage' => 'hired', 'application_status' => 'hired',
        ]);
        $closedPosting->update(['applications_count' => 1]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helper                                                             */
    /* ------------------------------------------------------------------ */

    private function createCandidateWithApplication(HrJobPosting $posting, array $data): ?HrCandidate
    {
        $candidate = HrCandidate::firstOrCreate(
            ['tenant_id' => $this->tenantId, 'personal_email' => $data['email']],
            [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'personal_phone' => $data['phone'] ?? null,
                'source' => $data['source'] ?? 'website',
                'status' => $data['stage'],
                'current_stage_entered_at' => now(),
                'privacy_consent_given_at' => now(),
                'privacy_consent_ip' => '127.0.0.1',
                'created_by' => $this->admin->id,
            ]
        );

        $token = Str::random(48);

        HrApplication::firstOrCreate(
            ['candidate_id' => $candidate->id, 'job_posting_id' => $posting->id],
            [
                'tenant_id' => $this->tenantId,
                'position_title' => $posting->title,
                'position_role' => $posting->department ?? 'general',
                'job_posting_id' => $posting->id,
                'cover_letter' => "I am writing to express my interest in the {$posting->title} position.",
                'screening_answers' => $data['screening_answers'] ?? null,
                'candidate_tracking_token' => $token,
                'status' => $data['application_status'] ?? 'active',
                'rejection_reason' => $data['rejection_reason'] ?? null,
            ]
        );

        return $candidate;
    }

    /* ------------------------------------------------------------------ */
    /*  Seed Documents for Candidates                                      */
    /* ------------------------------------------------------------------ */

    private function seedCandidateDocuments(): void
    {
        $disk = Storage::disk('private');

        // Create a minimal dummy PDF content for seeding
        $dummyPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\nxref\n0 3\ntrailer\n<< /Root 1 0 R /Size 3 >>\nstartxref\n0\n%%EOF";

        $candidates = HrCandidate::where('tenant_id', $this->tenantId)->get();

        $documentSets = [
            'tane.mahuta@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Tane_Mahuta_CV.pdf'],
                ['category' => 'driver_licence', 'name' => 'Tane_Mahuta_Drivers_Licence.pdf'],
            ],
            'maia.tui@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Maia_Tui_Resume.pdf'],
                ['category' => 'qualification', 'name' => 'Maia_Tui_NZ_Certificate_Health_Wellbeing_L4.pdf', 'notes' => 'NZ Certificate in Health & Wellbeing Level 4'],
                ['category' => 'first_aid', 'name' => 'Maia_Tui_First_Aid_Certificate.pdf', 'expires_at' => now()->addMonths(18)->toDateString()],
            ],
            'wiremu.kahu@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Wiremu_Kahu_CV.pdf'],
                ['category' => 'police_vetting', 'name' => 'Wiremu_Kahu_Police_Vet_Result.pdf', 'notes' => 'Clean result - issued March 2026'],
                ['category' => 'driver_licence', 'name' => 'Wiremu_Kahu_Full_Licence.pdf'],
                ['category' => 'first_aid', 'name' => 'Wiremu_Kahu_First_Aid.pdf', 'expires_at' => now()->addYear()->toDateString()],
            ],
            'aroha.ngata@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Aroha_Ngata_Resume.pdf'],
                ['category' => 'qualification', 'name' => 'Aroha_Ngata_NZ_Diploma_Health_Disability.pdf', 'notes' => 'NZ Diploma in Health & Disability Practice'],
                ['category' => 'police_vetting', 'name' => 'Aroha_Ngata_Police_Vetting.pdf'],
                ['category' => 'reference_letter', 'name' => 'Aroha_Ngata_Reference_Letter_PreviousManager.pdf', 'notes' => 'Reference from previous support role at Care NZ'],
            ],
            'nikau.rata@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Nikau_Rata_CV.pdf'],
                ['category' => 'qualification', 'name' => 'Nikau_Rata_NZ_Certificate_L3.pdf'],
                ['category' => 'police_vetting', 'name' => 'Nikau_Rata_Police_Vet.pdf'],
                ['category' => 'driver_licence', 'name' => 'Nikau_Rata_Licence.pdf'],
                ['category' => 'first_aid', 'name' => 'Nikau_Rata_FirstAid.pdf', 'expires_at' => now()->addMonths(6)->toDateString()],
                ['category' => 'certification', 'name' => 'Nikau_Rata_Manual_Handling_Cert.pdf', 'notes' => 'Manual handling and safe lifting certification'],
            ],
            'kiri.pounamu@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Kiri_Pounamu_Resume.pdf'],
                ['category' => 'qualification', 'name' => 'Kiri_Pounamu_Bachelor_Social_Work.pdf', 'notes' => 'Bachelor of Social Work - Massey University'],
                ['category' => 'police_vetting', 'name' => 'Kiri_Pounamu_Police_Vet.pdf'],
                ['category' => 'portfolio', 'name' => 'Kiri_Pounamu_Support_Plan_Portfolio.pdf', 'notes' => 'Portfolio of support plans and case studies'],
            ],
            'rawiri.h@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Rawiri_Harawira_CV.pdf'],
                ['category' => 'police_vetting', 'name' => 'Rawiri_Harawira_Police_Vet.pdf'],
                ['category' => 'first_aid', 'name' => 'Rawiri_Harawira_FirstAid.pdf', 'expires_at' => now()->subMonth()->toDateString(), 'notes' => 'EXPIRED - needs renewal'],
            ],
            'emma.wilson@example.co.nz' => [
                ['category' => 'cv', 'name' => 'Emma_Wilson_RN_Resume.pdf'],
                ['category' => 'certification', 'name' => 'Emma_Wilson_Nursing_Council_APC.pdf', 'notes' => 'Annual Practising Certificate - NZ Nursing Council', 'expires_at' => now()->addMonths(9)->toDateString()],
            ],
        ];

        foreach ($candidates as $candidate) {
            $docs = $documentSets[$candidate->personal_email] ?? null;
            if (! $docs) {
                continue;
            }

            foreach ($docs as $doc) {
                $storagePath = "candidates/{$candidate->id}/documents/{$doc['name']}";

                // Write dummy file if it doesn't exist
                if (! $disk->exists($storagePath)) {
                    $disk->put($storagePath, $dummyPdf);
                }

                $categoryLabel = HrCandidateDocument::CATEGORIES[$doc['category']] ?? $doc['category'];

                HrCandidateDocument::firstOrCreate(
                    ['candidate_id' => $candidate->id, 'original_name' => $doc['name']],
                    [
                        'tenant_id' => $this->tenantId,
                        'category' => $doc['category'],
                        'title' => $categoryLabel . ' - ' . $doc['name'],
                        'storage_path' => $storagePath,
                        'original_name' => $doc['name'],
                        'mime_type' => 'application/pdf',
                        'size_bytes' => strlen($dummyPdf),
                        'uploaded_by' => $this->admin->id,
                        'notes' => $doc['notes'] ?? null,
                        'expires_at' => $doc['expires_at'] ?? null,
                    ]
                );
            }
        }

        $this->command->info('Seeded candidate documents.');
    }
}
