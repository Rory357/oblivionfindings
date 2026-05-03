<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingConcern;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $supportWorkerA;
    protected User $supportWorkerB;
    protected User $nextOfKin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $supportRole = Role::where('name', 'support_worker')->firstOrFail();
        $nokRole = Role::where('name', 'next_of_kin')->firstOrFail();

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach($adminRole);

        $this->supportWorkerA = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorkerA->roles()->attach($supportRole);

        $this->supportWorkerB = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorkerB->roles()->attach($supportRole);

        $this->nextOfKin = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now()]);
        $this->nextOfKin->roles()->attach($nokRole);
    }

    public function test_unrelated_support_worker_cannot_view_safeguarding_concern(): void
    {
        $concern = SafeguardingConcern::factory()->create([
            'reported_by_user_id' => $this->supportWorkerA->id,
            'assigned_to_user_id' => null,
        ]);

        $this->actingAs($this->supportWorkerB)
            ->get("/safeguarding/{$concern->id}")
            ->assertForbidden();
    }

    public function test_support_worker_cannot_view_other_staff_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->supportWorkerA->id,
        ]);

        $this->actingAs($this->supportWorkerB)
            ->get("/operations/timesheets/{$timesheet->id}")
            ->assertForbidden();
    }

    public function test_support_worker_cannot_update_other_staff_timesheet(): void
    {
        $timesheet = Timesheet::factory()->create([
            'shift_id' => null,
            'user_id' => $this->supportWorkerA->id,
            'status' => 'draft',
            'notes' => 'Original note',
        ]);

        $this->actingAs($this->supportWorkerB)
            ->put("/operations/timesheets/{$timesheet->id}", [
                'client_id' => $timesheet->client_id,
                'work_date' => $timesheet->work_date->toDateString(),
                'starts_at' => $timesheet->starts_at->format('Y-m-d H:i:s'),
                'ends_at' => $timesheet->ends_at->format('Y-m-d H:i:s'),
                'break_minutes' => $timesheet->break_minutes,
                'notes' => 'Tampered',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('timesheets', [
            'id' => $timesheet->id,
            'notes' => 'Original note',
        ]);
    }

    public function test_incident_update_cannot_escalate_portal_visibility_without_permission(): void
    {
        $client = Client::factory()->create();
        $client->supportWorkers()->attach($this->supportWorkerA->id);

        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'reported_by' => $this->supportWorkerA->id,
            'status' => 'draft',
            'portal_visible' => false,
        ]);

        // Force a deny override so this user can update incident content
        // but cannot toggle portal visibility.
        $portalManage = Permission::where('key', 'incidents.portal.manage')->firstOrFail();
        $this->supportWorkerA->permissionOverrides()->syncWithoutDetaching([
            $portalManage->id => ['allowed' => false],
        ]);

        $this->actingAs($this->supportWorkerA)
            ->put("/incidents/{$incident->id}", [
                'type' => $incident->type,
                'severity' => $incident->severity,
                'occurred_at' => $incident->occurred_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'description' => $incident->description,
                'portal_visible' => true,
            ])
            ->assertRedirect();

        $incident->refresh();
        $this->assertFalse((bool) $incident->portal_visible);
    }

    public function test_portal_attachment_download_denied_for_unlinked_next_of_kin(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        // User is only linked to a different client.
        $this->nextOfKin->portalClients()->attach($clientB->id, ['relation' => 'next_of_kin']);

        $incident = ClientIncident::factory()->create([
            'client_id' => $clientA->id,
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'portal_visible' => true,
        ]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'original_name' => 'evidence.pdf',
            'path' => 'incidents/evidence.pdf',
            'portal_visible' => true,
            'disk' => 'public',
        ]);

        $this->actingAs($this->nextOfKin)
            ->get("/portal/clients/{$clientA->id}/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }

    public function test_portal_attachment_download_denied_when_incident_not_portal_visible(): void
    {
        $client = Client::factory()->create();
        $this->nextOfKin->portalClients()->attach($client->id, ['relation' => 'next_of_kin']);

        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'status' => 'reviewed',
            'reviewed_at' => now(),
            'portal_visible' => false,
        ]);

        $attachment = ClientIncidentAttachment::create([
            'incident_id' => $incident->id,
            'uploaded_by' => $this->admin->id,
            'original_name' => 'restricted.pdf',
            'path' => 'incidents/restricted.pdf',
            'portal_visible' => true,
            'disk' => 'public',
        ]);

        $this->actingAs($this->nextOfKin)
            ->get("/portal/clients/{$client->id}/incidents/{$incident->id}/attachments/{$attachment->id}/download")
            ->assertForbidden();
    }
}

