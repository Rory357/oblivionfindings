<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\ComplianceObligation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceComplianceTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_obligation(): void
    {
        $admin = $this->createAdminUser();

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
}
