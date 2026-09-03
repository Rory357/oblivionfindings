<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAgreementLifecycleAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_agreement_transitions_atomically(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $manager = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'employee_number' => 'EMP-' . $manager->id,
            'work_email' => $manager->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(['key' => 'service_agreements.update'], ['description' => 'Update service agreements', 'group' => 'test', 'module' => 'Test']);
        $manager->permissionOverrides()->attach($permission, ['allowed' => true]);

        $agreement = ServiceAgreement::create([
            'client_id' => $client->id,
            'title' => 'Residential Support Agreement',
            'agreement_type' => 'ndis',
            'status' => 'draft',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'created_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)
            ->post("/operations/service-agreements/{$agreement->id}/transition", [
                'status' => 'active',
                'reason' => 'Client signed agreement',
            ]);

        $response->assertRedirect();
        $agreement->refresh();
        $this->assertSame('active', $agreement->status);

        $this->assertDatabaseHas('service_agreement_status_changes', [
            'service_agreement_id' => $agreement->id,
            'from_status' => 'draft',
            'to_status' => 'active',
            'changed_by' => $manager->id,
            'reason' => 'Client signed agreement',
        ]);
    }

    public function test_submit_for_approval_and_approve_are_atomic(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);

        $manager = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'employee_number' => 'EMP-' . $manager->id,
            'work_email' => $manager->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(['key' => 'service_agreements.update'], ['description' => 'Update service agreements', 'group' => 'test', 'module' => 'Test']);
        $manager->permissionOverrides()->attach($permission, ['allowed' => true]);

        $agreement = ServiceAgreement::create([
            'client_id' => $client->id,
            'title' => 'Day Program Agreement',
            'agreement_type' => 'ndis',
            'status' => 'draft',
            'start_date' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        // Submit for approval
        $this->actingAs($manager)
            ->post("/operations/service-agreements/{$agreement->id}/submit-for-approval")
            ->assertRedirect();

        $agreement->refresh();
        $this->assertSame('pending_approval', $agreement->status);
        $this->assertNotNull($agreement->submitted_for_approval_at);

        // Approve
        $this->actingAs($manager)
            ->post("/operations/service-agreements/{$agreement->id}/approve", [
                'notes' => 'Executive review complete',
            ])
            ->assertRedirect();

        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertNotNull($agreement->approved_at);

        $this->assertDatabaseHas('service_agreement_status_changes', [
            'service_agreement_id' => $agreement->id,
            'from_status' => 'pending_approval',
            'to_status' => 'active',
            'notes' => 'Executive review complete',
        ]);
    }
}
