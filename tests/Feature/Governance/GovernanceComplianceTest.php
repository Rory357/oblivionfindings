<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceComplianceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_obligation(): void
    {
        $admin = $this->createAdminUser();
        $this->makeCurrentStaff($admin);

        $response = $this->actingAs($admin)->post('/governance/compliance', [
            'framework' => 'privacy_act',
            'obligation_reference' => 'PRIV-TEST',
            'title' => 'Privacy check',
            'description' => 'Maintain privacy controls',
            'due_date' => now()->addDays(30)->toDateString(),
            'owner_id' => $admin->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('compliance_obligations', [
            'framework' => 'privacy_act',
            'obligation_code' => 'PRIV-TEST',
            'obligation_title' => 'Privacy check',
            'owner_id' => $admin->id,
        ]);
    }

    public function test_admin_can_update_and_complete_obligation(): void
    {
        $admin = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($admin);

        $updateResponse = $this->actingAs($admin)->put("/governance/compliance/{$obligation->id}", [
            'title' => 'Updated Obligation',
            'notes' => 'Updated notes',
        ]);

        $updateResponse->assertRedirect();
        $this->assertDatabaseHas('compliance_obligations', [
            'id' => $obligation->id,
            'obligation_title' => 'Updated Obligation',
        ]);

        \Illuminate\Support\Facades\Storage::disk('local')->put('docs/filing.pdf', 'evidence content');
        ComplianceEvidence::create([
            'compliance_obligation_id' => $obligation->id,
            'evidence_type' => 'document',
            'title' => 'Valid annual filing document',
            'file_path' => 'docs/filing.pdf',
            'valid_until' => today()->addMonths(6),
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $completeResponse = $this->actingAs($admin)->post("/governance/compliance/{$obligation->id}/complete", [
            'completion_notes' => 'Filed with registry successfully.',
            'expected_version' => 1,
        ]);
        $completeResponse->assertRedirect();

        $this->assertDatabaseHas('compliance_obligations', [
            'id' => $obligation->id,
            'status' => 'complete',
            'completed_by' => $admin->id,
            'completion_notes' => 'Filed with registry successfully.',
        ]);
    }

    public function test_can_upload_evidence(): void
    {
        Storage::fake('local');

        $admin = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($admin);

        $response = $this->actingAs($admin)->post("/governance/compliance/{$obligation->id}/evidence", [
            'evidence_type' => 'document',
            'title' => 'Evidence Doc',
            'description' => 'Test evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 120, 'application/pdf'),
            'valid_until' => now()->addDays(30)->toDateString(),
        ]);

        $response->assertRedirect();

        $evidence = ComplianceEvidence::first();
        $this->assertNotNull($evidence);
        Storage::disk('local')->assertExists($evidence->file_path);

        $obligation->refresh();
        $this->assertTrue($obligation->evidence_provided);
    }

    public function test_calendar_renders_events(): void
    {
        $admin = $this->createAdminUser();
        $this->createComplianceObligation($admin, [
            'due_date' => now()->addDays(14)->toDateString(),
            'next_due_date' => now()->addDays(14)->toDateString(),
        ]);

        $response = $this->actingAs($admin)->get('/governance/compliance/calendar');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Compliance/Calendar')
        );
    }

    public function test_modal_create_persists_priority_requirements_and_frequency(): void
    {
        $admin = $this->createAdminUser();
        $this->makeCurrentStaff($admin);

        // The /compliance command-centre wizard posts the extra fields with _modal:true.
        $response = $this->actingAs($admin)->post('/governance/compliance', [
            'framework' => 'hswa',
            'title' => 'Quarterly H&S committee review',
            'description' => 'Workplace H&S committee meets and minutes are filed',
            'requirements' => 'Signed minutes uploaded as evidence each quarter',
            'frequency' => 'quarterly',
            'priority' => 'high',
            'due_date' => now()->addDays(20)->toDateString(),
            'owner_id' => $admin->id,
            '_modal' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('compliance_obligations', [
            'framework' => 'hswa',
            'obligation_title' => 'Quarterly H&S committee review',
            'requirements' => 'Signed minutes uploaded as evidence each quarter',
            'frequency' => 'quarterly',
            'priority' => 'high',
            'owner_id' => $admin->id,
        ]);
    }

    public function test_command_centre_exposes_wizard_reference_data_to_managers(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->get('/compliance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.manage', true)
                ->has('owners')
                ->has('obligations')
                ->has('relatedIncidents')
                ->has('frameworks')
            );
    }

    public function test_complete_obligation_fails_validation_when_evidence_required_and_missing(): void
    {
        $admin = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($admin, [
            'evidence_required' => true,
        ]);

        $response = $this->actingAs($admin)->post("/governance/compliance/{$obligation->id}/complete", []);
        $response->assertSessionHasErrors('evidence');

        $obligation->refresh();
        $this->assertNotEquals('complete', $obligation->status);
    }

    public function test_complete_obligation_fails_validation_when_borrowing_foreign_evidence(): void
    {
        $admin = $this->createAdminUser();
        $obligationA = $this->createComplianceObligation($admin, ['obligation_code' => 'PRIV-F-A']);
        $obligationB = $this->createComplianceObligation($admin, ['obligation_code' => 'PRIV-F-B']);

        $evidenceB = ComplianceEvidence::create([
            'compliance_obligation_id' => $obligationB->id,
            'evidence_type' => 'document',
            'title' => 'Evidence of Obligation B',
            'file_path' => 'docs/b.pdf',
            'valid_until' => today()->addMonths(6),
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post("/governance/compliance/{$obligationA->id}/complete", [
            'evidence_ids' => [$evidenceB->id],
        ]);
        $response->assertSessionHasErrors('evidence_ids');

        // Verify evidence B is still owned by obligation B
        $evidenceB->refresh();
        $this->assertEquals($obligationB->id, $evidenceB->compliance_obligation_id);
    }

    public function test_complete_obligation_fails_validation_when_evidence_expired(): void
    {
        $admin = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($admin);

        $expired = ComplianceEvidence::create([
            'compliance_obligation_id' => $obligation->id,
            'evidence_type' => 'certification',
            'title' => 'Expired ISO Audit',
            'file_path' => 'docs/expired.pdf',
            'valid_until' => today()->subDays(10),
            'uploaded_by' => $admin->id,
            'uploaded_at' => now()->subYear(),
        ]);

        $response = $this->actingAs($admin)->post("/governance/compliance/{$obligation->id}/complete", [
            'evidence_ids' => [$expired->id],
        ]);
        $response->assertSessionHasErrors('evidence_ids');
    }

    public function test_complete_obligation_fails_with_409_on_stale_expected_version(): void
    {
        $admin = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($admin, ['version_number' => 2]);

        ComplianceEvidence::create([
            'compliance_obligation_id' => $obligation->id,
            'evidence_type' => 'document',
            'title' => 'Evidence file',
            'file_path' => 'docs/f.pdf',
            'valid_until' => today()->addMonths(6),
            'uploaded_by' => $admin->id,
            'uploaded_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post("/governance/compliance/{$obligation->id}/complete", [
            'expected_version' => 1, // Stale version
        ]);
        $response->assertStatus(409);
    }

    private function makeCurrentStaff(User $user): void
    {
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
