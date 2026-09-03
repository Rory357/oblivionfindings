<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentReviewSiteScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_approver_can_review_same_site_incident(): void
    {
        $siteA = Site::factory()->create();
        $clientA = Client::factory()->create(['site_id' => $siteA->id]);

        $approver = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $approver->id,
            'employee_number' => 'EMP-' . $approver->id,
            'work_email' => $approver->email,
            'primary_site_id' => $siteA->id,
            'is_active' => true,
        ]);

        $permission = Permission::query()->firstOrCreate(['key' => 'incidents.approve'], ['description' => 'Approve incidents', 'group' => 'test', 'module' => 'Test']);
        $approver->permissionOverrides()->attach($permission, ['allowed' => true]);

        $incident = ClientIncident::factory()->create([
            'client_id' => $clientA->id,
            'site_id' => $siteA->id,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($approver)
            ->post("/incidents/{$incident->id}/review", [
                'review_notes' => 'Approved after review.',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('client_incidents', [
            'id' => $incident->id,
            'status' => 'reviewed',
            'reviewed_by' => $approver->id,
        ]);
    }

    public function test_site_approver_cannot_review_foreign_site_incident(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $clientB = Client::factory()->create(['site_id' => $siteB->id]);

        $approver = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $approver->id,
            'employee_number' => 'EMP-' . $approver->id,
            'work_email' => $approver->email,
            'primary_site_id' => $siteA->id, // only Site A
            'is_active' => true,
        ]);

        $permission = Permission::query()->firstOrCreate(['key' => 'incidents.approve'], ['description' => 'Approve incidents', 'group' => 'test', 'module' => 'Test']);
        $approver->permissionOverrides()->attach($permission, ['allowed' => true]);

        $incident = ClientIncident::factory()->create([
            'client_id' => $clientB->id,
            'site_id' => $siteB->id,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($approver)
            ->post("/incidents/{$incident->id}/review", [
                'review_notes' => 'Attempting unauthorized foreign site approval.',
            ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('client_incidents', [
            'id' => $incident->id,
            'status' => 'submitted',
            'reviewed_by' => null,
        ]);
    }
}
