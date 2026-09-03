<?php

namespace Tests\Feature\Privacy;

use App\Models\DataBreachLog;
use App\Models\DataRetentionPolicy;
use App\Models\DataSubjectRequest;
use App\Models\LegalHold;
use App\Models\Permission;
use App\Models\PrivacyImpactAssessment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyReportDomainRbacTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $auditor;

    protected User $supportWorker;

    protected User $breachExporter;

    protected User $retentionExporter;

    protected User $legalHoldsExporter;

    protected User $privacyExporter;

    protected DataSubjectRequest $dsr;

    protected DataBreachLog $breach;

    protected DataRetentionPolicy $retentionPolicy;

    protected LegalHold $legalHold;

    protected PrivacyImpactAssessment $dpia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // Auditor has generic privacy.viewRequests, but NO export permissions
        $this->auditor = User::factory()->create(['role' => 'auditor', 'approved_at' => now()]);
        $this->auditor->roles()->attach(Role::where('name', 'auditor')->first());

        // Support worker has no privacy permissions
        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        // Dedicated single-capability users
        $this->breachExporter = $this->createUserWithPermissions(['privacy.breach.export']);
        $this->retentionExporter = $this->createUserWithPermissions(['privacy.retention.export']);
        $this->legalHoldsExporter = $this->createUserWithPermissions(['privacy.legal_holds.export']);
        $this->privacyExporter = $this->createUserWithPermissions(['privacy.export']);

        // Seed domain test records
        $this->dsr = DataSubjectRequest::create([
            'request_type' => 'access',
            'subject_name' => 'John Doe',
            'subject_email' => 'john.doe@example.com',
            'request_details' => 'Please provide a copy of all my data.',
            'status' => 'under_review',
            'received_at' => now()->subDays(2),
            'due_date' => now()->addDays(18),
            'created_by' => $this->admin->id,
        ]);

        $this->breach = DataBreachLog::create([
            'breach_reference' => 'BR-'.now()->year.'-0001',
            'nature_of_breach' => 'Test unauthorized record disclosure',
            'discovered_at' => now()->subDay(),
            'status' => 'under_investigation',
            'likely_consequences' => 'Minimal risk',
            'measures_taken' => 'Contained immediately',
            'approximate_individuals_affected' => 5,
            'requires_authority_notification' => false,
            'requires_subject_notification' => false,
            'discovered_by_user_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);

        $this->retentionPolicy = DataRetentionPolicy::create([
            'policy_name' => 'Client Case Notes Retention',
            'model_type' => 'App\\Models\\Client',
            'retention_period_years' => 10,
            'archive_after_years' => 7,
            'hard_delete_after_years' => 10,
            'active' => true,
            'legal_basis' => 'Health (Retention of Health Information) Regulations 1996',
        ]);

        $this->legalHold = LegalHold::create([
            'hold_reference' => 'LH-'.now()->year.'-0001',
            'hold_type' => 'litigation',
            'reason' => 'Pending employment tribunal inquiry',
            'status' => 'active',
            'imposed_at' => now()->subDays(5),
            'imposed_by_user_id' => $this->admin->id,
        ]);

        $this->dpia = PrivacyImpactAssessment::create([
            'assessment_name' => 'Biometric Door Access Assessment',
            'project_or_process' => 'Head Office Access Control',
            'description' => 'Assessment of biometric door access system',
            'assessment_type' => 'system_upgrade',
            'processing_purpose' => 'Security and access auditing',
            'legal_basis' => 'Legitimate interests',
            'overall_risk_level' => 'high',
            'residual_risk_level' => 'medium',
            'assessment_date' => now()->subDays(10),
            'assessor_id' => $this->admin->id,
        ]);
    }

    private function createUserWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
        $permissions = Permission::whereIn('key', $permissionKeys)->get();
        $user->permissionOverrides()->sync($permissions->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');

        return $user;
    }

    public function test_unauthenticated_user_redirected_from_exports(): void
    {
        $this->get('/privacy/reports/export?type=opc_register')->assertRedirect('/login');
        $this->get('/privacy/reports/export?type=sla')->assertRedirect('/login');
        $this->get('/privacy/reports/export?type=retention')->assertRedirect('/login');
        $this->get('/privacy/reports/export?type=legal_holds')->assertRedirect('/login');
        $this->get('/privacy/reports/export?type=full')->assertRedirect('/login');
        $this->get("/privacy/requests/{$this->dsr->id}/export")->assertRedirect('/login');
    }

    public function test_generic_viewer_forbidden_from_all_compliance_report_exports(): void
    {
        // Auditor has privacy.viewRequests, but MUST be denied export access
        $this->actingAs($this->auditor)
            ->get('/privacy/reports/export?type=opc_register')
            ->assertForbidden();

        $this->actingAs($this->auditor)
            ->get('/privacy/reports/export?type=sla')
            ->assertForbidden();

        $this->actingAs($this->auditor)
            ->get('/privacy/reports/export?type=retention')
            ->assertForbidden();

        $this->actingAs($this->auditor)
            ->get('/privacy/reports/export?type=legal_holds')
            ->assertForbidden();

        $this->actingAs($this->auditor)
            ->get('/privacy/reports/export?type=full')
            ->assertForbidden();
    }

    public function test_generic_viewer_forbidden_from_dsr_subject_export(): void
    {
        $this->actingAs($this->auditor)
            ->get("/privacy/requests/{$this->dsr->id}/export")
            ->assertForbidden();
    }

    public function test_breach_export_capability_isolation(): void
    {
        // Allowed to export breach register
        $this->actingAs($this->breachExporter)
            ->get('/privacy/reports/export?type=opc_register')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Denied all other exports
        $this->actingAs($this->breachExporter)
            ->get('/privacy/reports/export?type=sla')
            ->assertForbidden();

        $this->actingAs($this->breachExporter)
            ->get('/privacy/reports/export?type=retention')
            ->assertForbidden();

        $this->actingAs($this->breachExporter)
            ->get('/privacy/reports/export?type=legal_holds')
            ->assertForbidden();

        $this->actingAs($this->breachExporter)
            ->get('/privacy/reports/export?type=full')
            ->assertForbidden();

        $this->actingAs($this->breachExporter)
            ->get("/privacy/requests/{$this->dsr->id}/export")
            ->assertForbidden();
    }

    public function test_retention_export_capability_isolation(): void
    {
        // Allowed to export retention report
        $this->actingAs($this->retentionExporter)
            ->get('/privacy/reports/export?type=retention')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Denied other exports
        $this->actingAs($this->retentionExporter)
            ->get('/privacy/reports/export?type=opc_register')
            ->assertForbidden();

        $this->actingAs($this->retentionExporter)
            ->get('/privacy/reports/export?type=sla')
            ->assertForbidden();

        $this->actingAs($this->retentionExporter)
            ->get('/privacy/reports/export?type=legal_holds')
            ->assertForbidden();

        $this->actingAs($this->retentionExporter)
            ->get('/privacy/reports/export?type=full')
            ->assertForbidden();
    }

    public function test_legal_holds_export_capability_isolation(): void
    {
        // Allowed to export legal holds report
        $this->actingAs($this->legalHoldsExporter)
            ->get('/privacy/reports/export?type=legal_holds')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Denied other exports
        $this->actingAs($this->legalHoldsExporter)
            ->get('/privacy/reports/export?type=opc_register')
            ->assertForbidden();

        $this->actingAs($this->legalHoldsExporter)
            ->get('/privacy/reports/export?type=sla')
            ->assertForbidden();

        $this->actingAs($this->legalHoldsExporter)
            ->get('/privacy/reports/export?type=retention')
            ->assertForbidden();

        $this->actingAs($this->legalHoldsExporter)
            ->get('/privacy/reports/export?type=full')
            ->assertForbidden();
    }

    public function test_privacy_export_capability_allows_dsr_and_sla_and_scopes_full_report(): void
    {
        // Allowed to export SLA report
        $this->actingAs($this->privacyExporter)
            ->get('/privacy/reports/export?type=sla')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Allowed to export DSR subject data
        $this->actingAs($this->privacyExporter)
            ->get("/privacy/requests/{$this->dsr->id}/export")
            ->assertRedirect();

        // Denied direct breach, retention, legal holds registers
        $this->actingAs($this->privacyExporter)
            ->get('/privacy/reports/export?type=opc_register')
            ->assertForbidden();

        $this->actingAs($this->privacyExporter)
            ->get('/privacy/reports/export?type=retention')
            ->assertForbidden();

        $this->actingAs($this->privacyExporter)
            ->get('/privacy/reports/export?type=legal_holds')
            ->assertForbidden();

        // Allowed to export full compliance report, but content only exposes authorized domains
        $response = $this->actingAs($this->privacyExporter)
            ->get('/privacy/reports/export?type=full')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Access & correction requests', $content);
        $this->assertStringNotContainsString('Notifiable breaches', $content);
        $this->assertStringNotContainsString('Retention policies', $content);
        $this->assertStringNotContainsString('Legal holds', $content);
    }

    public function test_admin_and_provider_manager_can_export_all_reports(): void
    {
        $providerManager = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $providerManager->roles()->attach(Role::where('name', 'provider_manager')->first());

        foreach ([$this->admin, $providerManager] as $user) {
            $this->actingAs($user)
                ->get('/privacy/reports/export?type=opc_register')
                ->assertOk();

            $this->actingAs($user)
                ->get('/privacy/reports/export?type=sla')
                ->assertOk();

            $this->actingAs($user)
                ->get('/privacy/reports/export?type=retention')
                ->assertOk();

            $this->actingAs($user)
                ->get('/privacy/reports/export?type=legal_holds')
                ->assertOk();

            $response = $this->actingAs($user)
                ->get('/privacy/reports/export?type=full')
                ->assertOk();

            $content = $response->streamedContent();
            $this->assertStringContainsString('Access & correction requests', $content);
            $this->assertStringContainsString('Notifiable breaches', $content);
            $this->assertStringContainsString('Retention policies', $content);
            $this->assertStringContainsString('Legal holds', $content);
            $this->assertStringContainsString('DPIAs', $content);
        }
    }

    public function test_compliance_report_scopes_domain_metrics_by_permission(): void
    {
        // Auditor with only privacy.viewRequests can access compliance page,
        // but breach, retention, legal-hold and DPIA counts are not disclosed (zeroed)
        $this->actingAs($this->auditor)
            ->get('/privacy/reports/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/reports/compliance')
                ->where('dsrStats.total', 1)
                ->where('breachStats.total', 0)
                ->where('retentionStats.total_policies', 0)
                ->where('legalHoldStats.total', 0)
                ->where('dpiaStats.total', 0)
            );

        // Admin with all domain permissions sees all counts
        $this->actingAs($this->admin)
            ->get('/privacy/reports/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('privacy/reports/compliance')
                ->where('dsrStats.total', 1)
                ->where('breachStats.total', 1)
                ->where('retentionStats.total_policies', 1)
                ->where('legalHoldStats.total', 1)
                ->where('dpiaStats.total', 1)
            );
    }
}
