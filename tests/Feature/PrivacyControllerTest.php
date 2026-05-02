<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\PrivacyImpactAssessment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

    protected User $hr;

    protected User $auditor;

    protected User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->coordinator = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $this->coordinator->roles()->attach(Role::where('name', 'coordinator')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $this->hr->roles()->attach(Role::where('name', 'hr')->first());

        $this->auditor = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->first());

        $this->finance = User::factory()->create(['role' => 'finance', 'approved_at' => now()]);
        $this->finance->roles()->attach(Role::where('name', 'finance')->first());
    }

    // ──────────────────────────────────────────────────
    // Helper: create a DataSubjectRequest directly
    // ──────────────────────────────────────────────────

    private function createDSR(array $overrides = []): DataSubjectRequest
    {
        return DataSubjectRequest::create(array_merge([
            'request_type' => 'access',
            'subject_name' => 'Jane Doe',
            'subject_email' => 'jane@example.com',
            'request_details' => 'I would like a copy of all my personal data.',
            'status' => 'identity_verification',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function createBreach(array $overrides = []): DataBreachLog
    {
        return DataBreachLog::create(array_merge([
            'breach_reference' => 'BR-'.now()->year.'-'.str_pad(
                DataBreachLog::whereYear('created_at', now()->year)->count() + 1,
                4, '0', STR_PAD_LEFT
            ),
            'nature_of_breach' => 'Unauthorized access to client records.',
            'discovered_at' => now()->subHours(2),
            'likely_consequences' => 'Potential exposure of personal data.',
            'measures_taken' => 'Access revoked immediately.',
            'affected_data_categories' => ['personal_details', 'health_records'],
            'approximate_individuals_affected' => 15,
            'requires_authority_notification' => true,
            'requires_subject_notification' => false,
            'status' => 'discovered',
            'discovered_by_user_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function createRetentionPolicy(array $overrides = []): DataRetentionPolicy
    {
        return DataRetentionPolicy::create(array_merge([
            'model_type' => 'App\\Models\\Client',
            'policy_name' => 'Client Record Retention',
            'description' => 'Retain client records for 7 years after service ends.',
            'retention_period_years' => 7,
            'archive_after_years' => 5,
            'hard_delete_after_years' => 10,
            'applies_to_soft_deleted' => true,
            'legal_hold_exemption' => true,
            'active_case_exemption' => true,
            'legal_basis' => 'Care Act 2014 s.42',
            'business_justification' => 'Regulatory requirement for care records.',
            'active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function createLegalHold(array $overrides = []): LegalHold
    {
        $client = Client::factory()->create();

        return LegalHold::create(array_merge([
            'hold_reference' => 'LH-'.now()->year.'-'.str_pad(
                LegalHold::whereYear('created_at', now()->year)->count() + 1,
                4, '0', STR_PAD_LEFT
            ),
            'hold_type' => 'litigation',
            'reason' => 'Pending litigation regarding service delivery.',
            'holdable_type' => 'App\\Models\\Client',
            'holdable_id' => $client->id,
            'status' => 'active',
            'imposed_at' => now(),
            'imposed_by_user_id' => $this->admin->id,
            'review_date' => now()->addMonths(3)->toDateString(),
        ], $overrides));
    }

    private function createDPIA(array $overrides = []): PrivacyImpactAssessment
    {
        return PrivacyImpactAssessment::create(array_merge([
            'assessment_name' => 'New Client Portal Assessment',
            'project_or_process' => 'Client Portal Launch',
            'description' => 'Assessment of data protection impact for the new client portal.',
            'assessment_type' => 'new_project',
            'assessor_id' => $this->admin->id,
            'assessment_date' => now(),
            'personal_data_types' => ['name', 'email', 'health_records'],
            'data_subjects' => ['clients', 'staff'],
            'processing_purpose' => 'Provide online access to care records.',
            'legal_basis' => 'Legitimate interest and consent.',
            'identified_risks' => [
                ['risk' => 'Unauthorized access', 'likelihood' => 'medium', 'impact' => 'high'],
            ],
            'overall_risk_level' => 'medium',
            'mitigation_measures' => [
                ['measure' => 'Two-factor authentication', 'status' => 'planned'],
            ],
            'residual_risk_level' => 'low',
        ], $overrides));
    }

    // ══════════════════════════════════════════════════
    //  SECTION 1: Authentication Requirements
    // ══════════════════════════════════════════════════

    public function test_dsr_index_requires_authentication(): void
    {
        $this->get('/privacy/requests')->assertRedirect('/login');
    }

    public function test_dsr_create_requires_authentication(): void
    {
        $this->get('/privacy/requests/create')->assertRedirect('/login');
    }

    public function test_dsr_store_requires_authentication(): void
    {
        $this->post('/privacy/requests')->assertRedirect('/login');
    }

    public function test_dsr_show_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->get("/privacy/requests/{$dsr->id}")->assertRedirect('/login');
    }

    public function test_dsr_update_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->put("/privacy/requests/{$dsr->id}")->assertRedirect('/login');
    }

    public function test_dsr_export_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->get("/privacy/requests/{$dsr->id}/export")->assertRedirect('/login');
    }

    public function test_dsr_verify_identity_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->post("/privacy/requests/{$dsr->id}/verify-identity")->assertRedirect('/login');
    }

    public function test_dsr_extend_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->post("/privacy/requests/{$dsr->id}/extend")->assertRedirect('/login');
    }

    public function test_dsr_complete_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->post("/privacy/requests/{$dsr->id}/complete")->assertRedirect('/login');
    }

    public function test_dsr_refuse_requires_authentication(): void
    {
        $dsr = $this->createDSR();
        $this->post("/privacy/requests/{$dsr->id}/refuse")->assertRedirect('/login');
    }

    public function test_retention_index_requires_authentication(): void
    {
        $this->get('/privacy/retention')->assertRedirect('/login');
    }

    public function test_retention_create_requires_authentication(): void
    {
        $this->get('/privacy/retention/create')->assertRedirect('/login');
    }

    public function test_retention_store_requires_authentication(): void
    {
        $this->post('/privacy/retention')->assertRedirect('/login');
    }

    public function test_retention_edit_requires_authentication(): void
    {
        $policy = $this->createRetentionPolicy();
        $this->get("/privacy/retention/{$policy->id}/edit")->assertRedirect('/login');
    }

    public function test_retention_update_requires_authentication(): void
    {
        $policy = $this->createRetentionPolicy();
        $this->put("/privacy/retention/{$policy->id}")->assertRedirect('/login');
    }

    public function test_retention_review_requires_authentication(): void
    {
        $this->get('/privacy/retention/review')->assertRedirect('/login');
    }

    public function test_deletion_logs_index_requires_authentication(): void
    {
        $this->get('/privacy/deletion-logs')->assertRedirect('/login');
    }

    public function test_deletion_execute_requires_authentication(): void
    {
        $this->post('/privacy/deletion/execute')->assertRedirect('/login');
    }

    public function test_legal_holds_index_requires_authentication(): void
    {
        $this->get('/privacy/legal-holds')->assertRedirect('/login');
    }

    public function test_legal_holds_create_requires_authentication(): void
    {
        $this->get('/privacy/legal-holds/create')->assertRedirect('/login');
    }

    public function test_legal_holds_store_requires_authentication(): void
    {
        $this->post('/privacy/legal-holds')->assertRedirect('/login');
    }

    public function test_legal_holds_edit_requires_authentication(): void
    {
        $hold = $this->createLegalHold();
        $this->get("/privacy/legal-holds/{$hold->id}/edit")->assertRedirect('/login');
    }

    public function test_legal_holds_update_requires_authentication(): void
    {
        $hold = $this->createLegalHold();
        $this->put("/privacy/legal-holds/{$hold->id}")->assertRedirect('/login');
    }

    public function test_legal_holds_release_requires_authentication(): void
    {
        $hold = $this->createLegalHold();
        $this->post("/privacy/legal-holds/{$hold->id}/release")->assertRedirect('/login');
    }

    public function test_breaches_index_requires_authentication(): void
    {
        $this->get('/privacy/breaches')->assertRedirect('/login');
    }

    public function test_breaches_create_requires_authentication(): void
    {
        $this->get('/privacy/breaches/create')->assertRedirect('/login');
    }

    public function test_breaches_store_requires_authentication(): void
    {
        $this->post('/privacy/breaches')->assertRedirect('/login');
    }

    public function test_breaches_show_requires_authentication(): void
    {
        $breach = $this->createBreach();
        $this->get("/privacy/breaches/{$breach->id}")->assertRedirect('/login');
    }

    public function test_breaches_update_requires_authentication(): void
    {
        $breach = $this->createBreach();
        $this->put("/privacy/breaches/{$breach->id}")->assertRedirect('/login');
    }

    public function test_breaches_notify_ico_requires_authentication(): void
    {
        $breach = $this->createBreach();
        $this->post("/privacy/breaches/{$breach->id}/notify-ico")->assertRedirect('/login');
    }

    public function test_breaches_notify_subjects_requires_authentication(): void
    {
        $breach = $this->createBreach();
        $this->post("/privacy/breaches/{$breach->id}/notify-subjects")->assertRedirect('/login');
    }

    public function test_breaches_resolve_requires_authentication(): void
    {
        $breach = $this->createBreach();
        $this->post("/privacy/breaches/{$breach->id}/resolve")->assertRedirect('/login');
    }

    public function test_dpia_index_requires_authentication(): void
    {
        $this->get('/privacy/dpia')->assertRedirect('/login');
    }

    public function test_dpia_create_requires_authentication(): void
    {
        $this->get('/privacy/dpia/create')->assertRedirect('/login');
    }

    public function test_dpia_store_requires_authentication(): void
    {
        $this->post('/privacy/dpia')->assertRedirect('/login');
    }

    public function test_dpia_show_requires_authentication(): void
    {
        $dpia = $this->createDPIA();
        $this->get("/privacy/dpia/{$dpia->id}")->assertRedirect('/login');
    }

    public function test_dpia_edit_requires_authentication(): void
    {
        $dpia = $this->createDPIA();
        $this->get("/privacy/dpia/{$dpia->id}/edit")->assertRedirect('/login');
    }

    public function test_dpia_update_requires_authentication(): void
    {
        $dpia = $this->createDPIA();
        $this->put("/privacy/dpia/{$dpia->id}")->assertRedirect('/login');
    }

    public function test_dpia_approve_requires_authentication(): void
    {
        $dpia = $this->createDPIA();
        $this->post("/privacy/dpia/{$dpia->id}/approve")->assertRedirect('/login');
    }

    public function test_dpia_review_requires_authentication(): void
    {
        $dpia = $this->createDPIA();
        $this->post("/privacy/dpia/{$dpia->id}/review")->assertRedirect('/login');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/privacy/dashboard')->assertRedirect('/login');
    }

    public function test_reports_compliance_requires_authentication(): void
    {
        $this->get('/privacy/reports/compliance')->assertRedirect('/login');
    }

    public function test_reports_export_requires_authentication(): void
    {
        $this->get('/privacy/reports/export')->assertRedirect('/login');
    }

    // ══════════════════════════════════════════════════
    //  SECTION 2: Permission-Based Access Control
    // ══════════════════════════════════════════════════

    public function test_dsr_index_forbidden_without_view_requests_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/requests')
            ->assertForbidden();
    }

    public function test_dsr_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/requests')
            ->assertOk();
    }

    public function test_dsr_index_accessible_by_auditor_with_view_requests(): void
    {
        $this->actingAs($this->auditor)
            ->get('/privacy/requests')
            ->assertOk();
    }

    public function test_dsr_create_forbidden_without_process_requests_permission(): void
    {
        $this->actingAs($this->auditor)
            ->get('/privacy/requests/create')
            ->assertForbidden();
    }

    public function test_dsr_create_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/requests/create')
            ->assertOk();
    }

    public function test_dsr_store_forbidden_without_process_requests_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Test',
                'subject_email' => 'test@example.com',
                'request_details' => 'Details',
            ])
            ->assertForbidden();
    }

    public function test_retention_index_forbidden_without_manage_retention_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/retention')
            ->assertForbidden();
    }

    public function test_retention_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/retention')
            ->assertOk();
    }

    public function test_legal_holds_index_forbidden_without_manage_legal_holds_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/legal-holds')
            ->assertForbidden();
    }

    public function test_legal_holds_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds')
            ->assertOk();
    }

    public function test_breaches_index_forbidden_without_report_breaches_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/breaches')
            ->assertForbidden();
    }

    public function test_breaches_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/breaches')
            ->assertOk();
    }

    public function test_dpia_index_forbidden_without_conduct_dpi_a_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/dpia')
            ->assertForbidden();
    }

    public function test_dpia_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/dpia')
            ->assertOk();
    }

    public function test_dashboard_forbidden_without_view_requests_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk();
    }

    public function test_compliance_report_forbidden_without_view_requests_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/reports/compliance')
            ->assertForbidden();
    }

    public function test_finance_user_cannot_access_privacy_routes(): void
    {
        $this->actingAs($this->finance)
            ->get('/privacy/requests')
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════
    //  SECTION 3: DataSubjectRequest Full Lifecycle
    // ══════════════════════════════════════════════════

    public function test_dsr_store_creates_request_with_identity_verification_status(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Jane Doe',
                'subject_email' => 'jane@example.com',
                'request_details' => 'Please provide all personal data held about me.',
                'specific_data_requested' => ['personal_details', 'health_records'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('data_subject_requests', [
            'request_type' => 'access',
            'subject_name' => 'Jane Doe',
            'subject_email' => 'jane@example.com',
            'status' => 'identity_verification',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_dsr_store_redirects_to_show_with_success_message(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/privacy/requests', [
                'request_type' => 'erasure',
                'subject_name' => 'John Smith',
                'subject_email' => 'john@example.com',
                'request_details' => 'Please delete all my data.',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_dsr_verify_identity_updates_request(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/verify-identity", [
                'verification_method' => 'Government-issued photo ID verified in person.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dsr->refresh();
        $this->assertEquals('in_progress', $dsr->status);
        $this->assertNotNull($dsr->identity_verified_at);
        $this->assertEquals($this->admin->id, $dsr->verified_by_user_id);
        $this->assertEquals('Government-issued photo ID verified in person.', $dsr->verification_method);
    }

    public function test_dsr_complete_marks_request_completed(): void
    {
        $dsr = $this->createDSR(['status' => 'in_progress']);

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/complete", [
                'completion_notes' => 'All requested data has been provided to the subject.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dsr->refresh();
        $this->assertEquals('completed', $dsr->status);
        $this->assertNotNull($dsr->completed_at);
        $this->assertEquals($this->admin->id, $dsr->completed_by_user_id);
        $this->assertEquals('All requested data has been provided to the subject.', $dsr->completion_notes);
    }

    public function test_dsr_full_lifecycle_create_verify_complete(): void
    {
        // Step 1: Create
        $this->actingAs($this->admin)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Alice Brown',
                'subject_email' => 'alice@example.com',
                'request_details' => 'Full data access request.',
                'assigned_to_user_id' => $this->admin->id,
            ])
            ->assertRedirect();

        $dsr = DataSubjectRequest::where('subject_email', 'alice@example.com')->first();
        $this->assertNotNull($dsr);
        $this->assertEquals('identity_verification', $dsr->status);

        // Step 2: Verify identity
        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/verify-identity", [
                'verification_method' => 'Passport verified.',
            ])
            ->assertRedirect();

        $dsr->refresh();
        $this->assertEquals('in_progress', $dsr->status);

        // Step 3: Complete
        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/complete", [
                'completion_notes' => 'Data exported and sent to subject.',
            ])
            ->assertRedirect();

        $dsr->refresh();
        $this->assertEquals('completed', $dsr->status);
        $this->assertNotNull($dsr->completed_at);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 4: DSR Rejection with Legal Basis
    // ══════════════════════════════════════════════════

    public function test_dsr_refuse_requires_rejection_reason_and_legal_basis(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/refuse", [])
            ->assertSessionHasErrors(['rejection_reason', 'rejection_legal_basis']);
    }

    public function test_dsr_refuse_sets_rejected_status(): void
    {
        $dsr = $this->createDSR(['status' => 'in_progress']);

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/refuse", [
                'rejection_reason' => 'Request is manifestly unfounded and excessive.',
                'rejection_legal_basis' => 'GDPR Article 12(5) - manifestly unfounded or excessive requests.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dsr->refresh();
        $this->assertEquals('rejected', $dsr->status);
        $this->assertEquals('GDPR Article 12(5) - manifestly unfounded or excessive requests.', $dsr->rejection_legal_basis);
        $this->assertEquals('Request is manifestly unfounded and excessive.', $dsr->rejection_reason);
    }

    public function test_dsr_refuse_validates_rejection_reason_is_string(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/refuse", [
                'rejection_reason' => '',
                'rejection_legal_basis' => 'Art 12(5)',
            ])
            ->assertSessionHasErrors(['rejection_reason']);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 5: Deadline Extension (GDPR Provision)
    // ══════════════════════════════════════════════════

    public function test_dsr_extend_requires_reason_and_date(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/extend", [])
            ->assertSessionHasErrors(['extension_reason', 'extended_due_date']);
    }

    public function test_dsr_extend_sets_extension_fields(): void
    {
        $dsr = $this->createDSR();
        $extendedDate = now()->addDays(60)->toDateString();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/extend", [
                'extension_reason' => 'Complex request requiring coordination across multiple departments.',
                'extended_due_date' => $extendedDate,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dsr->refresh();
        $this->assertTrue((bool) $dsr->extension_requested);
        $this->assertEquals($extendedDate, $dsr->extended_due_date->toDateString());
        $this->assertEquals('Complex request requiring coordination across multiple departments.', $dsr->extension_reason);
    }

    public function test_dsr_extend_date_must_be_after_today(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/extend", [
                'extension_reason' => 'Need more time.',
                'extended_due_date' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors(['extended_due_date']);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 6: Auto-Generated Reference Numbers
    // ══════════════════════════════════════════════════

    public function test_dsr_auto_generates_reference_number(): void
    {
        $dsr = $this->createDSR();

        $this->assertNotNull($dsr->reference_number);
        $this->assertStringStartsWith('DSR-'.now()->year.'-', $dsr->reference_number);
    }

    public function test_dsr_reference_numbers_increment_sequentially(): void
    {
        $dsr1 = $this->createDSR(['subject_name' => 'First']);
        $dsr2 = $this->createDSR(['subject_name' => 'Second']);

        $this->assertMatchesRegularExpression('/DSR-\d{4}-0001/', $dsr1->reference_number);
        $this->assertMatchesRegularExpression('/DSR-\d{4}-0002/', $dsr2->reference_number);
    }

    public function test_dsr_auto_generates_received_at_and_due_date(): void
    {
        $dsr = $this->createDSR();

        $this->assertNotNull($dsr->received_at);
        $this->assertNotNull($dsr->due_date);
        // Due date should be approximately 30 days from now
        $this->assertTrue($dsr->due_date->isSameDay(now()->addDays(30)));
    }

    public function test_breach_auto_generates_reference_number(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/breaches', [
                'nature_of_breach' => 'Lost USB stick containing client data.',
                'discovered_at' => now()->toDateTimeString(),
                'likely_consequences' => 'Potential data exposure.',
                'measures_taken' => 'Remote wipe initiated.',
                'requires_authority_notification' => true,
                'requires_subject_notification' => false,
            ])
            ->assertRedirect();

        $breach = DataBreachLog::latest()->first();
        $this->assertNotNull($breach->breach_reference);
        $this->assertStringStartsWith('BR-'.now()->year.'-', $breach->breach_reference);
    }

    public function test_legal_hold_auto_generates_reference_number(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post('/privacy/legal-holds', [
                'hold_type' => 'litigation',
                'reason' => 'Legal proceedings initiated.',
                'holdable_type' => 'App\\Models\\Client',
                'holdable_id' => $client->id,
            ])
            ->assertRedirect();

        $hold = LegalHold::latest()->first();
        $this->assertNotNull($hold->hold_reference);
        $this->assertStringStartsWith('LH-'.now()->year.'-', $hold->hold_reference);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 7: Overdue Request Detection
    // ══════════════════════════════════════════════════

    public function test_dsr_is_overdue_returns_true_when_past_due_date(): void
    {
        $dsr = $this->createDSR([
            'status' => 'in_progress',
            'due_date' => now()->subDay(),
        ]);

        $this->assertTrue($dsr->isOverdue());
    }

    public function test_dsr_is_overdue_returns_false_when_before_due_date(): void
    {
        $dsr = $this->createDSR([
            'status' => 'in_progress',
            'due_date' => now()->addDays(10),
        ]);

        $this->assertFalse($dsr->isOverdue());
    }

    public function test_dsr_is_overdue_returns_false_for_completed_requests(): void
    {
        $dsr = $this->createDSR([
            'status' => 'completed',
            'due_date' => now()->subDays(5),
        ]);

        $this->assertFalse($dsr->isOverdue());
    }

    public function test_dsr_is_overdue_returns_false_for_rejected_requests(): void
    {
        $dsr = $this->createDSR([
            'status' => 'rejected',
            'due_date' => now()->subDays(5),
        ]);

        $this->assertFalse($dsr->isOverdue());
    }

    public function test_dsr_is_overdue_uses_extended_due_date_when_set(): void
    {
        $dsr = $this->createDSR([
            'status' => 'in_progress',
            'due_date' => now()->subDay(),
            'extension_requested' => true,
            'extended_due_date' => now()->addDays(30),
        ]);

        $this->assertFalse($dsr->isOverdue());
    }

    public function test_dsr_overdue_scope_returns_correct_records(): void
    {
        $this->createDSR(['status' => 'in_progress', 'due_date' => now()->subDay()]);
        $this->createDSR(['status' => 'in_progress', 'due_date' => now()->addDay()]);
        $this->createDSR(['status' => 'completed', 'due_date' => now()->subDay()]);

        $overdueCount = DataSubjectRequest::overdue()->count();
        $this->assertEquals(1, $overdueCount);
    }

    public function test_dsr_open_scope_excludes_terminal_statuses(): void
    {
        $this->createDSR(['status' => 'in_progress']);
        $this->createDSR(['status' => 'identity_verification']);
        $this->createDSR(['status' => 'completed']);
        $this->createDSR(['status' => 'rejected']);
        $this->createDSR(['status' => 'withdrawn']);

        $openCount = DataSubjectRequest::open()->count();
        $this->assertEquals(2, $openCount);
    }

    public function test_dsr_days_remaining_calculation(): void
    {
        $dsr = $this->createDSR([
            'status' => 'in_progress',
            'due_date' => now()->addDays(15),
        ]);

        $this->assertEquals(15, $dsr->daysRemaining());
    }

    // ══════════════════════════════════════════════════
    //  SECTION 8: Data Breach Lifecycle
    // ══════════════════════════════════════════════════

    public function test_breach_store_creates_record_with_discovered_status(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/breaches', [
                'nature_of_breach' => 'Phishing email compromised credentials.',
                'discovered_at' => now()->toDateTimeString(),
                'affected_data_categories' => ['email', 'login_credentials'],
                'approximate_individuals_affected' => 3,
                'likely_consequences' => 'Unauthorized account access.',
                'measures_taken' => 'Password resets forced.',
                'requires_authority_notification' => false,
                'requires_subject_notification' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('data_breach_logs', [
            'nature_of_breach' => 'Phishing email compromised credentials.',
            'status' => 'discovered',
            'approximate_individuals_affected' => 3,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_breach_update_changes_status_to_under_investigation(): void
    {
        $breach = $this->createBreach();

        $this->actingAs($this->admin)
            ->put("/privacy/breaches/{$breach->id}", [
                'status' => 'under_investigation',
                'measures_taken' => 'Full security audit underway.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $breach->refresh();
        $this->assertEquals('under_investigation', $breach->status);
    }

    public function test_breach_notify_ico_records_notification(): void
    {
        $breach = $this->createBreach();

        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/notify-ico", [
                'authority_reference' => 'ICO-2026-REF-12345',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $breach->refresh();
        $this->assertNotNull($breach->authority_notified_at);
        $this->assertEquals('ICO-2026-REF-12345', $breach->authority_reference);
    }

    public function test_breach_notify_subjects_records_notification(): void
    {
        $breach = $this->createBreach(['requires_subject_notification' => true]);

        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/notify-subjects", [
                'notification_method' => 'Individual letters sent via recorded delivery.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $breach->refresh();
        $this->assertNotNull($breach->subjects_notified_at);
        $this->assertEquals('Individual letters sent via recorded delivery.', $breach->notification_method);
    }

    public function test_breach_resolve_sets_resolved_status(): void
    {
        $breach = $this->createBreach(['status' => 'under_investigation']);

        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/resolve", [
                'resolution_notes' => 'Root cause identified and patched. All affected users notified.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $breach->refresh();
        $this->assertEquals('resolved', $breach->status);
        $this->assertNotNull($breach->resolved_at);
        $this->assertEquals('Root cause identified and patched. All affected users notified.', $breach->resolution_notes);
    }

    public function test_breach_resolve_requires_resolution_notes(): void
    {
        $breach = $this->createBreach();

        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/resolve", [])
            ->assertSessionHasErrors(['resolution_notes']);
    }

    public function test_breach_full_lifecycle_report_investigate_notify_resolve(): void
    {
        // Step 1: Report breach
        $this->actingAs($this->admin)
            ->post('/privacy/breaches', [
                'nature_of_breach' => 'Laptop stolen from office.',
                'discovered_at' => now()->toDateTimeString(),
                'likely_consequences' => 'Client data exposure.',
                'measures_taken' => 'Reported to police. Remote wipe.',
                'requires_authority_notification' => true,
                'requires_subject_notification' => true,
            ])
            ->assertRedirect();

        $breach = DataBreachLog::latest()->first();
        $this->assertEquals('discovered', $breach->status);

        // Step 2: Update to under investigation
        $this->actingAs($this->admin)
            ->put("/privacy/breaches/{$breach->id}", [
                'status' => 'under_investigation',
            ])
            ->assertRedirect();

        $breach->refresh();
        $this->assertEquals('under_investigation', $breach->status);

        // Step 3: Notify ICO
        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/notify-ico", [
                'authority_reference' => 'ICO-REF-001',
            ])
            ->assertRedirect();

        $breach->refresh();
        $this->assertNotNull($breach->authority_notified_at);

        // Step 4: Notify subjects
        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/notify-subjects", [
                'notification_method' => 'Email notification sent to all affected.',
            ])
            ->assertRedirect();

        $breach->refresh();
        $this->assertNotNull($breach->subjects_notified_at);

        // Step 5: Resolve
        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/resolve", [
                'resolution_notes' => 'Laptop recovered. Data confirmed not accessed.',
            ])
            ->assertRedirect();

        $breach->refresh();
        $this->assertEquals('resolved', $breach->status);
        $this->assertNotNull($breach->resolved_at);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 9: 72-Hour ICO Notification Tracking
    // ══════════════════════════════════════════════════

    public function test_breach_requiring_notification_appears_in_stats(): void
    {
        $this->createBreach([
            'requires_authority_notification' => true,
            'authority_notified_at' => null,
        ]);
        $this->createBreach([
            'requires_authority_notification' => true,
            'authority_notified_at' => now(),
        ]);
        $this->createBreach([
            'requires_authority_notification' => false,
        ]);

        $this->actingAs($this->admin)
            ->get('/privacy/breaches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.requiring_notification', 1)
            );
    }

    public function test_breach_index_filters_by_requires_notification(): void
    {
        $this->createBreach([
            'requires_authority_notification' => true,
            'authority_notified_at' => null,
        ]);
        $this->createBreach([
            'requires_authority_notification' => false,
        ]);

        $this->actingAs($this->admin)
            ->get('/privacy/breaches?requires_notification=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('breaches.data', 1)
            );
    }

    public function test_breach_ico_notification_timestamp_is_tracked(): void
    {
        $breach = $this->createBreach([
            'requires_authority_notification' => true,
            'discovered_at' => now()->subHours(48),
        ]);

        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/notify-ico", [
                'authority_reference' => 'ICO-2026-001',
            ])
            ->assertRedirect();

        $breach->refresh();
        // Notification timestamp should be set
        $this->assertNotNull($breach->authority_notified_at);
        // Can verify the gap between discovery and notification
        $hoursSinceDiscovery = $breach->discovered_at->diffInHours($breach->authority_notified_at);
        $this->assertGreaterThanOrEqual(0, $hoursSinceDiscovery);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 10: Legal Hold Lifecycle
    // ══════════════════════════════════════════════════

    public function test_legal_hold_store_creates_active_hold(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($this->admin)
            ->post('/privacy/legal-holds', [
                'hold_type' => 'investigation',
                'reason' => 'Internal investigation into data handling.',
                'holdable_type' => 'App\\Models\\Client',
                'holdable_id' => $client->id,
                'legal_authority' => 'Internal governance policy.',
                'review_date' => now()->addMonths(6)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('legal_holds', [
            'hold_type' => 'investigation',
            'status' => 'active',
            'imposed_by_user_id' => $this->admin->id,
            'holdable_type' => 'App\\Models\\Client',
            'holdable_id' => $client->id,
        ]);
    }

    public function test_legal_hold_edit_returns_inertia_page(): void
    {
        $hold = $this->createLegalHold();

        $this->actingAs($this->admin)
            ->get("/privacy/legal-holds/{$hold->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/legal-holds/edit')
                ->has('hold')
            );
    }

    public function test_legal_hold_update_modifies_hold(): void
    {
        $hold = $this->createLegalHold();

        $this->actingAs($this->admin)
            ->put("/privacy/legal-holds/{$hold->id}", [
                'reason' => 'Updated reason due to new information.',
                'legal_authority' => 'Court Order #1234',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $hold->refresh();
        $this->assertEquals('Updated reason due to new information.', $hold->reason);
        $this->assertEquals('Court Order #1234', $hold->legal_authority);
    }

    public function test_legal_hold_release_sets_released_status(): void
    {
        $hold = $this->createLegalHold();

        $this->actingAs($this->admin)
            ->post("/privacy/legal-holds/{$hold->id}/release", [
                'release_reason' => 'Litigation concluded. No further hold required.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $hold->refresh();
        $this->assertEquals('released', $hold->status);
        $this->assertNotNull($hold->released_at);
        $this->assertEquals($this->admin->id, $hold->released_by_user_id);
        $this->assertEquals('Litigation concluded. No further hold required.', $hold->release_reason);
    }

    public function test_legal_hold_release_requires_reason(): void
    {
        $hold = $this->createLegalHold();

        $this->actingAs($this->admin)
            ->post("/privacy/legal-holds/{$hold->id}/release", [])
            ->assertSessionHasErrors(['release_reason']);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 11: Polymorphic Legal Hold Relationships
    // ══════════════════════════════════════════════════

    public function test_legal_hold_on_client_model(): void
    {
        $client = Client::factory()->create();

        $hold = LegalHold::create([
            'hold_reference' => 'LH-'.now()->year.'-9001',
            'hold_type' => 'regulatory',
            'reason' => 'CQC investigation.',
            'holdable_type' => 'App\\Models\\Client',
            'holdable_id' => $client->id,
            'status' => 'active',
            'imposed_at' => now(),
            'imposed_by_user_id' => $this->admin->id,
        ]);

        $this->assertEquals('App\\Models\\Client', $hold->holdable_type);
        $this->assertEquals($client->id, $hold->holdable_id);
        $this->assertInstanceOf(Client::class, $hold->holdable);
    }

    public function test_legal_hold_on_user_model(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $hold = LegalHold::create([
            'hold_reference' => 'LH-'.now()->year.'-9002',
            'hold_type' => 'litigation',
            'reason' => 'Employment tribunal claim.',
            'holdable_type' => 'App\\Models\\User',
            'holdable_id' => $user->id,
            'status' => 'active',
            'imposed_at' => now(),
            'imposed_by_user_id' => $this->admin->id,
        ]);

        $this->assertEquals('App\\Models\\User', $hold->holdable_type);
        $this->assertInstanceOf(User::class, $hold->holdable);
    }

    public function test_legal_hold_active_scope(): void
    {
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'released', 'released_at' => now(), 'release_reason' => 'Done.']);

        $this->assertEquals(2, LegalHold::active()->count());
    }

    // ══════════════════════════════════════════════════
    //  SECTION 12: DPIA Lifecycle
    // ══════════════════════════════════════════════════

    public function test_dpia_store_creates_assessment(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/dpia', [
                'assessment_name' => 'Cloud Migration Assessment',
                'project_or_process' => 'AWS Cloud Migration',
                'description' => 'Assessment of migrating data to AWS cloud services.',
                'assessment_type' => 'new_project',
                'personal_data_types' => ['name', 'address', 'health_data'],
                'data_subjects' => ['clients', 'staff'],
                'processing_purpose' => 'Improved data access and redundancy.',
                'legal_basis' => 'Legitimate interest.',
                'identified_risks' => [
                    ['risk' => 'Data in transit vulnerability', 'likelihood' => 'low', 'impact' => 'high'],
                ],
                'overall_risk_level' => 'medium',
                'mitigation_measures' => [
                    ['measure' => 'End-to-end encryption', 'status' => 'implemented'],
                ],
                'residual_risk_level' => 'low',
                'review_date' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('privacy_impact_assessments', [
            'assessment_name' => 'Cloud Migration Assessment',
            'project_or_process' => 'AWS Cloud Migration',
            'overall_risk_level' => 'medium',
            'assessor_id' => $this->admin->id,
        ]);
    }

    public function test_dpia_show_returns_inertia_page(): void
    {
        $dpia = $this->createDPIA();

        $this->actingAs($this->admin)
            ->get("/privacy/dpia/{$dpia->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/dpia/show')
                ->has('dpia')
            );
    }

    public function test_dpia_edit_returns_inertia_page(): void
    {
        $dpia = $this->createDPIA();

        $this->actingAs($this->admin)
            ->get("/privacy/dpia/{$dpia->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/dpia/edit')
                ->has('dpia')
                ->has('staff')
            );
    }

    public function test_dpia_update_modifies_assessment(): void
    {
        $dpia = $this->createDPIA();

        $this->actingAs($this->admin)
            ->put("/privacy/dpia/{$dpia->id}", [
                'assessment_name' => 'Updated Assessment Name',
                'overall_risk_level' => 'high',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dpia->refresh();
        $this->assertEquals('Updated Assessment Name', $dpia->assessment_name);
        $this->assertEquals('high', $dpia->overall_risk_level);
    }

    public function test_dpia_approve_sets_outcome_to_approved(): void
    {
        $dpia = $this->createDPIA();

        $this->actingAs($this->admin)
            ->post("/privacy/dpia/{$dpia->id}/approve")
            ->assertRedirect()
            ->assertSessionHas('success');

        $dpia->refresh();
        $this->assertEquals('approved', $dpia->outcome);
        $this->assertEquals($this->admin->id, $dpia->approved_by_user_id);
        $this->assertNotNull($dpia->approved_at);
    }

    public function test_dpia_review_sets_outcome_to_requires_dpo_review(): void
    {
        $dpia = $this->createDPIA();

        $this->actingAs($this->admin)
            ->post("/privacy/dpia/{$dpia->id}/review", [
                'review_notes' => 'Additional safeguards needed for health data processing.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dpia->refresh();
        $this->assertEquals('requires_dpo_review', $dpia->outcome);
    }

    public function test_dpia_review_requires_notes(): void
    {
        $dpia = $this->createDPIA();

        $this->actingAs($this->admin)
            ->post("/privacy/dpia/{$dpia->id}/review", [])
            ->assertSessionHasErrors(['review_notes']);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 13: Risk Level Assessment
    // ══════════════════════════════════════════════════

    public function test_dpia_risk_levels_tracked_in_stats(): void
    {
        $this->createDPIA(['overall_risk_level' => 'low']);
        $this->createDPIA(['overall_risk_level' => 'medium']);
        $this->createDPIA(['overall_risk_level' => 'high']);
        $this->createDPIA(['overall_risk_level' => 'high']);

        $this->actingAs($this->admin)
            ->get('/privacy/dpia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.high_risk', 2)
                ->where('stats.total', 4)
            );
    }

    public function test_dpia_index_filters_by_risk_level(): void
    {
        $this->createDPIA(['overall_risk_level' => 'low']);
        $this->createDPIA(['overall_risk_level' => 'high']);
        $this->createDPIA(['overall_risk_level' => 'high']);

        $this->actingAs($this->admin)
            ->get('/privacy/dpia?risk_level=high')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('dpias.data', 2)
            );
    }

    public function test_dpia_index_filters_by_outcome(): void
    {
        $this->createDPIA(['outcome' => 'approved']);
        $this->createDPIA(['outcome' => 'approved']);
        $this->createDPIA(['outcome' => 'requires_dpo_review']);

        $this->actingAs($this->admin)
            ->get('/privacy/dpia?outcome=approved')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('dpias.data', 2)
            );
    }

    public function test_dpia_store_validates_risk_level_values(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/dpia', [
                'assessment_name' => 'Test',
                'project_or_process' => 'Test',
                'assessment_type' => 'new_project',
                'processing_purpose' => 'Test',
                'legal_basis' => 'Test',
                'overall_risk_level' => 'invalid_level',
            ])
            ->assertSessionHasErrors(['overall_risk_level']);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 14: Data Retention Policy CRUD
    // ══════════════════════════════════════════════════

    public function test_retention_index_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/retention')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/retention')
                ->has('policies')
                ->has('filters')
                ->has('stats')
            );
    }

    public function test_retention_create_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/retention/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/retention/create')
            );
    }

    public function test_retention_store_creates_policy(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/retention', [
                'model_type' => 'App\\Models\\Shift',
                'policy_name' => 'Shift Record Retention',
                'description' => 'Retain shift records for 3 years.',
                'retention_period_years' => 3,
                'archive_after_years' => 2,
                'hard_delete_after_years' => 5,
                'applies_to_soft_deleted' => true,
                'legal_hold_exemption' => true,
                'active_case_exemption' => false,
                'legal_basis' => 'Working Time Regulations.',
                'business_justification' => 'Payroll audit trail.',
                'active' => true,
            ])
            ->assertRedirect(route('privacy.retention.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('data_retention_policies', [
            'model_type' => 'App\\Models\\Shift',
            'policy_name' => 'Shift Record Retention',
            'retention_period_years' => 3,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_retention_edit_returns_inertia_page(): void
    {
        $policy = $this->createRetentionPolicy();

        $this->actingAs($this->admin)
            ->get("/privacy/retention/{$policy->id}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/retention/edit')
                ->has('policy')
            );
    }

    public function test_retention_update_modifies_policy(): void
    {
        $policy = $this->createRetentionPolicy();

        $this->actingAs($this->admin)
            ->put("/privacy/retention/{$policy->id}", [
                'policy_name' => 'Updated Client Record Retention',
                'retention_period_years' => 10,
                'active' => false,
            ])
            ->assertRedirect(route('privacy.retention.index'))
            ->assertSessionHas('success');

        $policy->refresh();
        $this->assertEquals('Updated Client Record Retention', $policy->policy_name);
        $this->assertEquals(10, $policy->retention_period_years);
        $this->assertFalse((bool) $policy->active);
        $this->assertEquals($this->admin->id, $policy->updated_by);
    }

    public function test_retention_review_returns_active_policies(): void
    {
        $this->createRetentionPolicy(['active' => true]);
        $this->createRetentionPolicy(['active' => false, 'policy_name' => 'Inactive Policy']);

        $this->actingAs($this->admin)
            ->get('/privacy/retention/review')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/retention/review')
                ->has('policies')
            );
    }

    public function test_retention_index_shows_stats(): void
    {
        $this->createRetentionPolicy(['active' => true]);
        $this->createRetentionPolicy(['active' => true]);
        $this->createRetentionPolicy(['active' => false]);

        $this->actingAs($this->admin)
            ->get('/privacy/retention')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 3)
                ->where('stats.active', 2)
            );
    }

    public function test_retention_index_filters_by_active_status(): void
    {
        $this->createRetentionPolicy(['active' => true]);
        $this->createRetentionPolicy(['active' => false, 'policy_name' => 'Inactive']);

        $this->actingAs($this->admin)
            ->get('/privacy/retention?active=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('policies.data', 1)
            );
    }

    public function test_retention_index_searches_by_query(): void
    {
        $this->createRetentionPolicy(['policy_name' => 'Unique Policy Name']);
        $this->createRetentionPolicy(['policy_name' => 'Other Policy']);

        $this->actingAs($this->admin)
            ->get('/privacy/retention?q=Unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('policies.data', 1)
            );
    }

    // ══════════════════════════════════════════════════
    //  SECTION 15: Privacy Dashboard Stats Verification
    // ══════════════════════════════════════════════════

    public function test_dashboard_returns_inertia_page_with_all_stats(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/dashboard')
                ->has('dsrStats')
                ->has('recentRequests')
                ->has('breachStats')
                ->has('activeHolds')
                ->has('retentionStats')
                ->has('dpiaStats')
            );
    }

    public function test_dashboard_reflects_dsr_stats(): void
    {
        $this->createDSR(['status' => 'in_progress']);
        $this->createDSR(['status' => 'completed', 'completed_at' => now()]);
        $this->createDSR([
            'status' => 'in_progress',
            'due_date' => now()->subDays(5),
        ]);

        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dsrStats.total', 3)
            );
    }

    public function test_dashboard_shows_recent_requests(): void
    {
        $this->createDSR(['subject_name' => 'Recent 1']);
        $this->createDSR(['subject_name' => 'Recent 2']);

        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('recentRequests', 2)
            );
    }

    public function test_dashboard_reflects_breach_stats(): void
    {
        $this->createBreach(['status' => 'discovered']);
        $this->createBreach(['status' => 'under_investigation']);
        $this->createBreach([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_notes' => 'Done.',
        ]);

        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('breachStats.total', 3)
            );
    }

    public function test_dashboard_reflects_active_legal_holds(): void
    {
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'released', 'released_at' => now(), 'release_reason' => 'Done.']);

        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeHolds', 2)
            );
    }

    public function test_dashboard_reflects_dpia_stats(): void
    {
        $this->createDPIA(['overall_risk_level' => 'high', 'outcome' => null]);
        $this->createDPIA(['overall_risk_level' => 'low', 'outcome' => 'approved']);

        $this->actingAs($this->admin)
            ->get('/privacy/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dpiaStats.total', 2)
                ->where('dpiaStats.pending_review', 1)
            );
    }

    // ══════════════════════════════════════════════════
    //  SECTION 16: Compliance Report Generation
    // ══════════════════════════════════════════════════

    public function test_compliance_report_returns_inertia_page(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/reports/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/reports/compliance')
                ->has('dsrStats')
                ->has('breachStats')
                ->has('dpiaStats')
                ->has('retentionStats')
                ->has('legalHoldStats')
                ->has('period')
            );
    }

    public function test_compliance_report_defaults_to_yearly_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/reports/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'year')
            );
    }

    public function test_compliance_report_accepts_monthly_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/reports/compliance?period=month')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'month')
            );
    }

    public function test_compliance_report_accepts_quarterly_period(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/reports/compliance?period=quarter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('period', 'quarter')
            );
    }

    public function test_compliance_report_includes_dsr_breakdown_by_type(): void
    {
        $this->createDSR(['request_type' => 'access']);
        $this->createDSR(['request_type' => 'access']);
        $this->createDSR(['request_type' => 'erasure']);

        $this->actingAs($this->admin)
            ->get('/privacy/reports/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('dsrStats.by_type')
                ->where('dsrStats.total', 3)
            );
    }

    public function test_reports_export_responds(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/reports/export')
            ->assertRedirect()
            ->assertSessionHas('info');
    }

    // ══════════════════════════════════════════════════
    //  SECTION 17: Input Validation
    // ══════════════════════════════════════════════════

    public function test_dsr_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/requests', [])
            ->assertSessionHasErrors(['request_type', 'subject_name', 'subject_email']);
    }

    public function test_dsr_store_validates_request_type_enum(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/requests', [
                'request_type' => 'invalid_type',
                'subject_name' => 'Test',
                'subject_email' => 'test@example.com',
                'request_details' => 'Details',
            ])
            ->assertSessionHasErrors(['request_type']);
    }

    public function test_dsr_store_validates_email_format(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Test',
                'subject_email' => 'not-an-email',
                'request_details' => 'Details',
            ])
            ->assertSessionHasErrors(['subject_email']);
    }

    public function test_dsr_store_validates_assigned_user_exists(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Test',
                'subject_email' => 'test@example.com',
                'request_details' => 'Details',
                'assigned_to_user_id' => 99999,
            ])
            ->assertSessionHasErrors(['assigned_to_user_id']);
    }

    public function test_dsr_verify_identity_requires_verification_method(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->post("/privacy/requests/{$dsr->id}/verify-identity", [])
            ->assertSessionHasErrors(['verification_method']);
    }

    public function test_breach_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/breaches', [])
            ->assertSessionHasErrors(['nature_of_breach', 'discovered_at']);
    }

    public function test_breach_store_validates_discovered_at_is_date(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/breaches', [
                'nature_of_breach' => 'Test breach.',
                'discovered_at' => 'not-a-date',
                'likely_consequences' => 'Test.',
                'measures_taken' => 'Test.',
            ])
            ->assertSessionHasErrors(['discovered_at']);
    }

    public function test_breach_store_validates_individuals_affected_is_integer(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/breaches', [
                'nature_of_breach' => 'Test breach.',
                'discovered_at' => now()->toDateTimeString(),
                'likely_consequences' => 'Test.',
                'measures_taken' => 'Test.',
                'approximate_individuals_affected' => -5,
            ])
            ->assertSessionHasErrors(['approximate_individuals_affected']);
    }

    public function test_breach_notify_subjects_requires_notification_method(): void
    {
        $breach = $this->createBreach();

        $this->actingAs($this->admin)
            ->post("/privacy/breaches/{$breach->id}/notify-subjects", [])
            ->assertSessionHasErrors(['notification_method']);
    }

    public function test_retention_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/retention', [])
            ->assertSessionHasErrors(['model_type', 'policy_name', 'retention_period_years']);
    }

    public function test_retention_store_validates_retention_period_min(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/retention', [
                'model_type' => 'App\\Models\\Client',
                'policy_name' => 'Test',
                'retention_period_years' => 0,
            ])
            ->assertSessionHasErrors(['retention_period_years']);
    }

    public function test_retention_store_validates_retention_period_max(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/retention', [
                'model_type' => 'App\\Models\\Client',
                'policy_name' => 'Test',
                'retention_period_years' => 101,
            ])
            ->assertSessionHasErrors(['retention_period_years']);
    }

    public function test_legal_hold_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/legal-holds', [])
            ->assertSessionHasErrors(['hold_type', 'reason']);
    }

    public function test_legal_hold_store_validates_hold_type_enum(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/legal-holds', [
                'hold_type' => 'invalid_type',
                'reason' => 'Test reason.',
            ])
            ->assertSessionHasErrors(['hold_type']);
    }

    public function test_dpia_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/dpia', [])
            ->assertSessionHasErrors([
                'assessment_name',
                'project_or_process',
                'assessment_type',
                'processing_purpose',
                'legal_basis',
                'overall_risk_level',
            ]);
    }

    public function test_dpia_store_validates_assessment_type_enum(): void
    {
        $this->actingAs($this->admin)
            ->post('/privacy/dpia', [
                'assessment_name' => 'Test',
                'project_or_process' => 'Test',
                'assessment_type' => 'invalid_type',
                'processing_purpose' => 'Test',
                'legal_basis' => 'Test',
                'overall_risk_level' => 'medium',
            ])
            ->assertSessionHasErrors(['assessment_type']);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 18: Status Transition Guards
    // ══════════════════════════════════════════════════

    public function test_dsr_update_validates_status_enum(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->put("/privacy/requests/{$dsr->id}", [
                'status' => 'nonexistent_status',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_breach_update_validates_status_enum(): void
    {
        $breach = $this->createBreach();

        $this->actingAs($this->admin)
            ->put("/privacy/breaches/{$breach->id}", [
                'status' => 'nonexistent_status',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_dsr_update_allows_valid_status_values(): void
    {
        $validStatuses = ['received', 'under_review', 'identity_verification', 'in_progress', 'completed', 'rejected', 'withdrawn'];

        foreach ($validStatuses as $status) {
            $dsr = $this->createDSR();

            $this->actingAs($this->admin)
                ->put("/privacy/requests/{$dsr->id}", [
                    'status' => $status,
                ])
                ->assertRedirect();
        }
    }

    public function test_breach_update_allows_valid_status_values(): void
    {
        $validStatuses = ['discovered', 'under_investigation', 'contained', 'notified', 'resolved'];

        foreach ($validStatuses as $status) {
            $breach = $this->createBreach();

            $this->actingAs($this->admin)
                ->put("/privacy/breaches/{$breach->id}", [
                    'status' => $status,
                ])
                ->assertRedirect();
        }
    }

    public function test_dsr_update_can_assign_user(): void
    {
        $dsr = $this->createDSR();
        $assignee = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->admin)
            ->put("/privacy/requests/{$dsr->id}", [
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertRedirect();

        $dsr->refresh();
        $this->assertEquals($assignee->id, $dsr->assigned_to_user_id);
        $this->assertNotNull($dsr->assigned_at);
    }

    // ══════════════════════════════════════════════════
    //  SECTION 19: Date Filtering on List Endpoints
    // ══════════════════════════════════════════════════

    public function test_dsr_index_filters_by_status(): void
    {
        $this->createDSR(['status' => 'identity_verification']);
        $this->createDSR(['status' => 'in_progress']);
        $this->createDSR(['status' => 'completed']);

        $this->actingAs($this->admin)
            ->get('/privacy/requests?status=in_progress')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
            );
    }

    public function test_dsr_index_filters_by_request_type(): void
    {
        $this->createDSR(['request_type' => 'access']);
        $this->createDSR(['request_type' => 'access']);
        $this->createDSR(['request_type' => 'erasure']);

        $this->actingAs($this->admin)
            ->get('/privacy/requests?request_type=access')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 2)
            );
    }

    public function test_dsr_index_filters_by_overdue(): void
    {
        $this->createDSR(['status' => 'in_progress', 'due_date' => now()->subDay()]);
        $this->createDSR(['status' => 'in_progress', 'due_date' => now()->addDays(10)]);

        $this->actingAs($this->admin)
            ->get('/privacy/requests?overdue=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
            );
    }

    public function test_dsr_index_search_by_query(): void
    {
        $this->createDSR(['subject_name' => 'Unique Subject Name']);
        $this->createDSR(['subject_name' => 'Other Subject']);

        $this->actingAs($this->admin)
            ->get('/privacy/requests?q=Unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
            );
    }

    public function test_dsr_index_returns_stats(): void
    {
        $this->createDSR(['status' => 'identity_verification']);
        $this->createDSR(['status' => 'in_progress']);
        $this->createDSR(['status' => 'in_progress', 'due_date' => now()->subDay()]);
        $this->createDSR([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/privacy/requests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stats.open')
                ->has('stats.overdue')
                ->has('stats.completed_30_days')
                ->has('stats.pending_verification')
            );
    }

    public function test_breach_index_filters_by_status(): void
    {
        $this->createBreach(['status' => 'discovered']);
        $this->createBreach(['status' => 'under_investigation']);

        $this->actingAs($this->admin)
            ->get('/privacy/breaches?status=discovered')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('breaches.data', 1)
            );
    }

    public function test_breach_index_search_by_query(): void
    {
        $this->createBreach(['nature_of_breach' => 'Unique breach description.']);
        $this->createBreach(['nature_of_breach' => 'Other breach.']);

        $this->actingAs($this->admin)
            ->get('/privacy/breaches?q=Unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('breaches.data', 1)
            );
    }

    public function test_legal_holds_index_filters_by_status(): void
    {
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'released', 'released_at' => now(), 'release_reason' => 'Done.']);

        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('holds.data', 1)
            );
    }

    public function test_legal_holds_index_filters_by_hold_type(): void
    {
        $this->createLegalHold(['hold_type' => 'litigation']);
        $this->createLegalHold(['hold_type' => 'regulatory']);
        $this->createLegalHold(['hold_type' => 'litigation']);

        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds?hold_type=litigation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('holds.data', 2)
            );
    }

    public function test_legal_holds_index_search_by_query(): void
    {
        $this->createLegalHold(['reason' => 'Very specific unique reason for hold.']);
        $this->createLegalHold(['reason' => 'Generic reason.']);

        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds?q=unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('holds.data', 1)
            );
    }

    public function test_legal_holds_index_shows_stats(): void
    {
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'active']);
        $this->createLegalHold(['status' => 'released', 'released_at' => now(), 'release_reason' => 'Done.']);

        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 3)
                ->where('stats.active', 2)
            );
    }

    public function test_dpia_index_search_by_query(): void
    {
        $this->createDPIA(['assessment_name' => 'Unique Assessment']);
        $this->createDPIA(['assessment_name' => 'Other Assessment']);

        $this->actingAs($this->admin)
            ->get('/privacy/dpia?q=Unique')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('dpias.data', 1)
            );
    }

    public function test_dpia_index_shows_stats(): void
    {
        $this->createDPIA(['outcome' => null, 'overall_risk_level' => 'high']);
        $this->createDPIA(['outcome' => 'approved', 'overall_risk_level' => 'low']);
        $this->createDPIA(['outcome' => null, 'overall_risk_level' => 'medium']);

        $this->actingAs($this->admin)
            ->get('/privacy/dpia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 3)
                ->where('stats.pending_review', 2)
                ->where('stats.high_risk', 1)
                ->where('stats.approved', 1)
            );
    }

    // ══════════════════════════════════════════════════
    //  SECTION 20: Inertia Page & Component Assertions
    // ══════════════════════════════════════════════════

    public function test_dsr_index_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/requests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/requests')
                ->has('requests')
                ->has('filters')
                ->has('stats')
            );
    }

    public function test_dsr_create_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/requests/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/requests/create')
                ->has('staff')
            );
    }

    public function test_dsr_show_returns_correct_inertia_component(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->get("/privacy/requests/{$dsr->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/requests/show')
                ->has('request')
                ->has('staff')
            );
    }

    public function test_breach_index_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/breaches')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/breaches')
                ->has('breaches')
                ->has('filters')
                ->has('stats')
            );
    }

    public function test_breach_create_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/breaches/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/breaches/create')
                ->has('staff')
            );
    }

    public function test_breach_show_returns_correct_inertia_component(): void
    {
        $breach = $this->createBreach();

        $this->actingAs($this->admin)
            ->get("/privacy/breaches/{$breach->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/breaches/show')
                ->has('breach')
            );
    }

    public function test_legal_holds_create_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/legal-holds/create')
            );
    }

    public function test_legal_holds_index_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/legal-holds')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/legal-holds')
                ->has('holds')
                ->has('filters')
                ->has('stats')
            );
    }

    public function test_dpia_index_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/dpia')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/dpia')
                ->has('dpias')
                ->has('filters')
                ->has('stats')
            );
    }

    public function test_dpia_create_returns_correct_inertia_component(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/dpia/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/dpia/create')
                ->has('staff')
            );
    }

    // ══════════════════════════════════════════════════
    //  SECTION 21: Deletion Logs
    // ══════════════════════════════════════════════════

    public function test_deletion_logs_index_accessible_by_admin(): void
    {
        $this->actingAs($this->admin)
            ->get('/privacy/deletion-logs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/deletion-logs')
                ->has('logs')
                ->has('filters')
            );
    }

    public function test_deletion_logs_index_forbidden_without_manage_retention_permission(): void
    {
        $this->actingAs($this->supportWorker)
            ->get('/privacy/deletion-logs')
            ->assertForbidden();
    }

    public function test_deletion_execute_responds(): void
    {
        $policy = $this->createRetentionPolicy([
            'model_type' => Client::class,
            'retention_period_years' => 1,
            'retention_conditions' => ['status' => 'retired'],
        ]);

        $this->actingAs($this->admin)
            ->post('/privacy/deletion/execute', [
                'policy_id' => $policy->id,
                'confirm' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');
    }

    // ══════════════════════════════════════════════════
    //  SECTION 22: DSR Request Types
    // ══════════════════════════════════════════════════

    public function test_dsr_store_accepts_all_valid_request_types(): void
    {
        $validTypes = ['access', 'rectification', 'erasure', 'restriction', 'portability', 'objection', 'automated_decision'];

        foreach ($validTypes as $type) {
            $this->actingAs($this->admin)
                ->post('/privacy/requests', [
                    'request_type' => $type,
                    'subject_name' => 'Test Subject for '.$type,
                    'subject_email' => $type.'@example.com',
                    'request_details' => "Testing {$type} request.",
                ])
                ->assertRedirect();

            $this->assertDatabaseHas('data_subject_requests', [
                'request_type' => $type,
                'subject_email' => $type.'@example.com',
            ]);
        }
    }

    // ══════════════════════════════════════════════════
    //  SECTION 23: DSR Export
    // ══════════════════════════════════════════════════

    public function test_dsr_export_responds(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->admin)
            ->get("/privacy/requests/{$dsr->id}/export")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($dsr->fresh()->export_path);
    }

    public function test_dsr_export_forbidden_without_view_requests_permission(): void
    {
        $dsr = $this->createDSR();

        $this->actingAs($this->supportWorker)
            ->get("/privacy/requests/{$dsr->id}/export")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════
    //  SECTION 24: Additional Model Relationship Tests
    // ══════════════════════════════════════════════════

    public function test_dsr_belongs_to_assigned_user(): void
    {
        $dsr = $this->createDSR([
            'assigned_to_user_id' => $this->admin->id,
            'assigned_at' => now(),
        ]);

        $this->assertInstanceOf(User::class, $dsr->assignedTo);
        $this->assertEquals($this->admin->id, $dsr->assignedTo->id);
    }

    public function test_dsr_belongs_to_verified_by_user(): void
    {
        $dsr = $this->createDSR([
            'verified_by_user_id' => $this->admin->id,
            'identity_verified_at' => now(),
            'verification_method' => 'Passport',
        ]);

        $this->assertInstanceOf(User::class, $dsr->verifiedBy);
    }

    public function test_breach_belongs_to_discovered_by_user(): void
    {
        $breach = $this->createBreach();

        $this->assertInstanceOf(User::class, $breach->discoveredBy);
        $this->assertEquals($this->admin->id, $breach->discoveredBy->id);
    }

    public function test_legal_hold_belongs_to_imposed_by_user(): void
    {
        $hold = $this->createLegalHold();

        $this->assertInstanceOf(User::class, $hold->imposedBy);
        $this->assertEquals($this->admin->id, $hold->imposedBy->id);
    }

    public function test_dpia_belongs_to_assessor(): void
    {
        $dpia = $this->createDPIA();

        $this->assertInstanceOf(User::class, $dpia->assessor);
        $this->assertEquals($this->admin->id, $dpia->assessor->id);
    }

    public function test_retention_policy_belongs_to_creator(): void
    {
        $policy = $this->createRetentionPolicy();

        $this->assertInstanceOf(User::class, $policy->creator);
        $this->assertEquals($this->admin->id, $policy->creator->id);
    }
}
