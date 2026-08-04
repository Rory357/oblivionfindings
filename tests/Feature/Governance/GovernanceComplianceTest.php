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

        $completeResponse = $this->actingAs($admin)->post("/governance/compliance/{$obligation->id}/complete", []);
        $completeResponse->assertRedirect();

        $this->assertDatabaseHas('compliance_obligations', [
            'id' => $obligation->id,
            'status' => 'complete',
            'completed_by' => $admin->id,
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
