<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ControlRoomAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

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
    }

    // ──────────────────────────────────────
    // Show Alert
    // ──────────────────────────────────────

    public function test_show_requires_authentication(): void
    {
        $alert = ControlRoomAlert::factory()->create();
        $this->get("/control-room/alerts/{$alert->id}")->assertRedirect('/login');
    }

    public function test_show_displays_alert(): void
    {
        $alert = ControlRoomAlert::factory()->create();

        $this->actingAs($this->admin)
            ->get("/control-room/alerts/{$alert->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/show')
                ->where('alert.id', $alert->id)
                ->where('alert.severity', $alert->severity)
                ->where('alert.status', $alert->status)
                ->has('audit_logs')
                ->has('can')
                ->has('staff')
            );
    }

    public function test_show_returns_404_for_nonexistent_alert(): void
    {
        $this->actingAs($this->admin)
            ->get('/control-room/alerts/99999')
            ->assertNotFound();
    }

    public function test_show_displays_assigned_user_info(): void
    {
        $alert = ControlRoomAlert::factory()->assignedTo($this->coordinator)->create();

        $this->actingAs($this->admin)
            ->get("/control-room/alerts/{$alert->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('alert.assigned_to.id', $this->coordinator->id)
                ->where('alert.assigned_to.name', $this->coordinator->name)
            );
    }

    public function test_show_blocked_for_user_without_permission(): void
    {
        $alert = ControlRoomAlert::factory()->create();
        $noPermUser = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($noPermUser)
            ->get("/control-room/alerts/{$alert->id}")
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Alert meta (working details)
    // ──────────────────────────────────────

    public function test_update_meta_interprets_due_at_in_the_worker_timezone(): void
    {
        // Regression: the datetime-local value ("2026-07-08T09:00", no zone)
        // was stored verbatim, so Eloquent treated 9:00 am NZ as 9:00 am UTC
        // and the workspace displayed it as 9:00 pm — twelve hours off.
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/meta", [
                'due_at' => '2026-07-08T09:00',
            ])
            ->assertRedirect();

        // 9:00 am 8 Jul NZST (UTC+12, NZ winter) === 9:00 pm 7 Jul UTC.
        $this->assertSame(
            '2026-07-07 21:00:00',
            $alert->fresh()->due_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    // ──────────────────────────────────────
    // Acknowledge Alert
    // ──────────────────────────────────────

    public function test_acknowledge_open_alert(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge", ['notes' => 'Acknowledged'])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'ack',
            'acknowledged_by_user_id' => $this->admin->id,
        ]);

        $alert->refresh();
        $this->assertNotNull($alert->acknowledged_at);
    }

    public function test_acknowledge_without_notes(): void
    {
        $alert = ControlRoomAlert::factory()->open()->withNotes('Original notes')->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $alert->refresh();
        $this->assertEquals('ack', $alert->status);
        $this->assertEquals('Original notes', $alert->notes);
    }

    public function test_cannot_acknowledge_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertSessionHasErrors('alert');
    }

    public function test_cannot_acknowledge_closed_alert(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertSessionHasErrors('alert');
    }

    public function test_acknowledge_requires_manage_permission(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->supportWorker)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Triage Alert
    // ──────────────────────────────────────

    public function test_triage_open_alert(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage")
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'triaging',
        ]);
    }

    public function test_triage_acknowledged_alert(): void
    {
        $alert = ControlRoomAlert::factory()->acknowledged()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage")
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'triaging',
        ]);
    }

    public function test_cannot_triage_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage")
            ->assertSessionHasErrors('alert');
    }

    // ──────────────────────────────────────
    // Resolve Alert
    // ──────────────────────────────────────

    public function test_resolve_alert(): void
    {
        $alert = ControlRoomAlert::factory()->triaging()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Issue resolved successfully',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'resolved',
            'resolved_by_user_id' => $this->admin->id,
            'notes' => 'Issue resolved successfully',
        ]);

        $alert->refresh();
        $this->assertNotNull($alert->resolved_at);
    }

    public function test_resolve_requires_resolution_notes(): void
    {
        $alert = ControlRoomAlert::factory()->triaging()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [])
            ->assertSessionHasErrors('resolution_notes');
    }

    public function test_cannot_resolve_already_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Trying again',
            ])
            ->assertSessionHasErrors('alert');
    }

    public function test_cannot_resolve_closed_alert(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Trying',
            ])
            ->assertSessionHasErrors('alert');
    }

    // ──────────────────────────────────────
    // Close Alert
    // ──────────────────────────────────────

    public function test_close_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/close", [
                'closure_notes' => 'Confirmed closed',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'closed',
            'closed_by_user_id' => $this->admin->id,
        ]);

        $alert->refresh();
        $this->assertNotNull($alert->closed_at);
    }

    public function test_cannot_close_open_alert_directly(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/close")
            ->assertSessionHasErrors('alert');

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'open',
        ]);
    }

    public function test_cannot_close_already_closed_alert(): void
    {
        $alert = ControlRoomAlert::factory()->closed()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/close")
            ->assertSessionHasErrors('alert');
    }

    // ──────────────────────────────────────
    // Assign Alert
    // ──────────────────────────────────────

    public function test_assign_alert_to_user(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => $this->coordinator->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'assigned_to_user_id' => $this->coordinator->id,
            'assigned_by_user_id' => $this->admin->id,
        ]);

        $alert->refresh();
        $this->assertNotNull($alert->assigned_at);
    }

    public function test_assign_requires_valid_user_id(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => 99999,
            ])
            ->assertSessionHasErrors('assigned_to_user_id');
    }

    public function test_assign_requires_assign_permission(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->supportWorker)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => $this->coordinator->id,
            ])
            ->assertForbidden();
    }

    public function test_assign_blocks_out_of_scope_assignee_for_scoped_operator(): void
    {
        $visibleSite = Site::factory()->create(['type' => 'house']);
        $hiddenSite = Site::factory()->create(['type' => 'house']);
        $hiddenAssignee = $this->makeRoleUser('coordinator');

        $this->scopeUserToSite($this->coordinator, $visibleSite);
        $this->scopeUserToSite($hiddenAssignee, $hiddenSite);

        $alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $visibleSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => $hiddenAssignee->id,
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Unassign Alert
    // ──────────────────────────────────────

    public function test_unassign_alert(): void
    {
        $alert = ControlRoomAlert::factory()->assignedTo($this->coordinator)->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/unassign")
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'assigned_to_user_id' => null,
            'assigned_by_user_id' => null,
        ]);
    }

    // ──────────────────────────────────────
    // Escalate Alert
    // ──────────────────────────────────────

    public function test_escalate_alert(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['escalation_level' => 0]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Needs senior attention',
            ])
            ->assertRedirect();

        $alert->refresh();
        $this->assertEquals(1, $alert->escalation_level);
        $this->assertEquals($this->admin->id, $alert->escalated_by_user_id);
        $this->assertNotNull($alert->escalated_at);
    }

    public function test_escalate_with_specific_level(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['escalation_level' => 0]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Critical situation',
                'escalation_level' => 3,
            ])
            ->assertRedirect();

        $alert->refresh();
        $this->assertEquals(3, $alert->escalation_level);
    }

    public function test_escalation_capped_at_5(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['escalation_level' => 4]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Maximum escalation',
                'escalation_level' => 10,
            ])
            ->assertRedirect();

        $alert->refresh();
        $this->assertEquals(5, $alert->escalation_level);
    }

    public function test_cannot_escalate_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Should fail',
            ])
            ->assertSessionHasErrors('alert');
    }

    public function test_escalate_requires_reason(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [])
            ->assertSessionHasErrors('escalation_reason');
    }

    public function test_escalate_requires_permission(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->supportWorker)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Test',
            ])
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Add Note
    // ──────────────────────────────────────

    public function test_add_note_to_alert(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['context' => []]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/note", [
                'note' => 'Test note content',
            ])
            ->assertRedirect();

        $alert->refresh();
        $activityLog = $alert->context['activity_log'] ?? [];
        $this->assertNotEmpty($activityLog);
        $this->assertEquals('Test note content', $activityLog[0]['content']);
        $this->assertEquals($this->admin->id, $activityLog[0]['user_id']);
    }

    public function test_add_note_requires_content(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/note", [])
            ->assertSessionHasErrors('note');
    }

    public function test_add_note_validates_max_length(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/note", [
                'note' => str_repeat('A', 2001),
            ])
            ->assertSessionHasErrors('note');
    }

    // ──────────────────────────────────────
    // Store (Create) Alert
    // ──────────────────────────────────────

    public function test_create_alert(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'Test Alert',
                'severity' => 'high',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'manual',
            'alert_type' => 'Test Alert',
            'severity' => 'high',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_create_alert_accepts_client_site_and_priority(): void
    {
        $client = \App\Models\Client::factory()->create();
        $site = \App\Models\Site::factory()->create();

        $this->actingAs($this->admin)
            ->post('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'welfare_check',
                'severity' => 'medium',
                'client_id' => $client->id,
                'site_id' => $site->id,
                'priority' => 'high',
                'notes' => 'Front door reported open since 6am.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'manual',
            'alert_type' => 'welfare_check',
            'client_id' => $client->id,
            'site_id' => $site->id,
            'priority' => 'high',
        ]);
    }

    public function test_create_alert_inertia_flashes_created_id(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'security',
                'severity' => 'low',
            ], ['X-Inertia' => 'true']);

        $alert = \App\Models\ControlRoomAlert::query()->latest('id')->first();
        $this->assertNotNull($alert);
        $response->assertSessionHas('created_alert_id', $alert->id);
    }

    public function test_create_alert_via_json(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/control-room/alerts', [
                'source' => 'external',
                'alert_type' => 'API Alert',
                'severity' => 'critical',
                'notes' => 'Created via API',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'message',
                'alert' => ['id', 'status'],
            ]);
    }

    public function test_create_alert_validates_source(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/alerts', [
                'source' => 'invalid_source',
                'alert_type' => 'Test',
                'severity' => 'high',
            ])
            ->assertSessionHasErrors('source');
    }

    public function test_create_alert_validates_severity(): void
    {
        $this->actingAs($this->admin)
            ->post('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'Test',
                'severity' => 'ultra_critical',
            ])
            ->assertSessionHasErrors('severity');
    }

    public function test_create_alert_requires_permission(): void
    {
        // Support worker does not have controlRoom.alerts.create
        $this->actingAs($this->supportWorker)
            ->post('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'Test',
                'severity' => 'high',
            ])
            ->assertForbidden();
    }

    public function test_create_alert_with_compliance_source(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/control-room/alerts', [
                'source' => 'compliance',
                'alert_type' => 'Training Expired',
                'severity' => 'high',
                'notes' => 'Staff training expired',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'compliance',
            'alert_type' => 'Training Expired',
        ]);
    }

    // ──────────────────────────────────────
    // Readiness payload-shape regressions
    // ──────────────────────────────────────

    public function test_show_page_payload_shape_resolves_alert(): void
    {
        $alert = ControlRoomAlert::factory()->triaging()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Resolved from show page shape',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'resolved',
            'resolved_by_user_id' => $this->admin->id,
            'notes' => 'Resolved from show page shape',
        ]);
    }

    public function test_show_page_payload_shape_escalates_alert(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['escalation_level' => 0]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Escalated from show page shape',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $alert->refresh();
        $this->assertSame(1, $alert->escalation_level);
        $this->assertSame($this->admin->id, $alert->escalated_by_user_id);
    }

    public function test_show_page_payload_shape_adds_note(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['context' => []]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/note", [
                'note' => 'Show page note payload',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $alert->refresh();
        $activityLog = $alert->context['activity_log'] ?? [];

        $this->assertNotEmpty($activityLog);
        $this->assertSame('Show page note payload', $activityLog[0]['content']);
    }

    public function test_show_page_payload_shape_assigns_alert(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => $this->coordinator->id,
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'assigned_to_user_id' => $this->coordinator->id,
            'assigned_by_user_id' => $this->admin->id,
        ]);
    }

    public function test_show_page_playbook_advance_route_completes_current_step(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create();
        $playbook = Playbook::factory()->create([
            'category' => Playbook::CATEGORY_EMERGENCY,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);

        $firstStep = PlaybookStep::create([
            'playbook_id' => $playbook->id,
            'order' => 1,
            'title' => 'Check first step',
            'type' => 'task',
        ]);

        $secondStep = PlaybookStep::create([
            'playbook_id' => $playbook->id,
            'order' => 2,
            'title' => 'Check next step',
            'type' => 'task',
        ]);

        $run = PlaybookRun::create([
            'playbook_id' => $playbook->id,
            'alert_id' => $alert->id,
            'status' => PlaybookRun::STATUS_PENDING,
        ]);
        $run->start($this->admin);
        $alert->update(['playbook_run_id' => $run->id]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/playbook/advance")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('control_room_playbook_run_steps', [
            'playbook_run_id' => $run->id,
            'playbook_step_id' => $firstStep->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('control_room_playbook_run_steps', [
            'playbook_run_id' => $run->id,
            'playbook_step_id' => $secondStep->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_show_page_evidence_upload_route_stores_file_item(): void
    {
        Storage::fake('local');

        $alert = ControlRoomAlert::factory()->open()->create();
        $pack = EvidencePack::create([
            'alert_id' => $alert->id,
            'title' => 'Show page evidence',
            'status' => 'collecting',
            'item_count' => 0,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/evidence/{$pack->id}/items", [
                'file' => UploadedFile::fake()->create('evidence.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('control_room_evidence_items', [
            'evidence_pack_id' => $pack->id,
            'type' => 'document',
            'title' => 'evidence.pdf',
            'captured_by_user_id' => $this->admin->id,
        ]);
        $this->assertSame(1, $pack->refresh()->item_count);
    }

    // ──────────────────────────────────────
    // Full Alert Lifecycle
    // ──────────────────────────────────────

    public function test_full_alert_lifecycle(): void
    {
        // 1. Create
        $this->actingAs($this->admin)
            ->postJson('/control-room/alerts', [
                'source' => 'manual',
                'alert_type' => 'Lifecycle Test',
                'severity' => 'high',
            ])
            ->assertCreated();

        $alert = ControlRoomAlert::where('alert_type', 'Lifecycle Test')->firstOrFail();
        $this->assertEquals('open', $alert->status);

        // 2. Acknowledge
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge", ['notes' => 'Acknowledged'])
            ->assertRedirect();
        $alert->refresh();
        $this->assertEquals('ack', $alert->status);

        // 3. Triage
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage")
            ->assertRedirect();
        $alert->refresh();
        $this->assertEquals('triaging', $alert->status);

        // 4. Assign
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => $this->coordinator->id,
            ])
            ->assertRedirect();
        $alert->refresh();
        $this->assertEquals($this->coordinator->id, $alert->assigned_to_user_id);

        // 5. Escalate
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Needs manager review',
            ])
            ->assertRedirect();
        $alert->refresh();
        $this->assertEquals(1, $alert->escalation_level);

        // 6. Resolve
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Issue fixed',
            ])
            ->assertRedirect();
        $alert->refresh();
        $this->assertEquals('resolved', $alert->status);

        // 7. Close
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/close", [
                'closure_notes' => 'Confirmed closed',
            ])
            ->assertRedirect();
        $alert->refresh();
        $this->assertEquals('closed', $alert->status);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    // ──────────────────────────────────────
    // Sensor triage: confirm / dismiss (Gap B)
    // ──────────────────────────────────────

    public function test_confirm_creates_linked_sensor_incident_with_evidence(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
            'severity' => 'high',
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);
        Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'signal_type_code' => 'fall_detected',
            'occurred_at' => now(),
            'payload' => ['confidence' => 0.95, 'location' => 'Bedroom'],
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/confirm")
            ->assertRedirect();

        $incident = ClientIncident::where('source', 'sensor')->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('fall', $incident->type);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertFalse($incident->interactive);
        $this->assertSame('fall_detected', $incident->metadata['sensor_evidence']['signal_type'] ?? null);

        $alert->refresh();
        $this->assertSame('confirmed', $alert->status);
        $this->assertSame($incident->id, $alert->context['incident_id']);
    }

    public function test_dismiss_logs_false_positive_without_incident(): void
    {
        $client = Client::factory()->create();
        $alert = ControlRoomAlert::factory()->open()->create([
            'source' => 'sensor',
            'alert_type' => 'sensor.fall_detected',
            'client_id' => $client->id,
        ]);
        $signal = Signal::create([
            'alert_id' => $alert->id,
            'client_id' => $client->id,
            'signal_type_code' => 'fall_detected',
            'occurred_at' => now(),
            'payload' => ['confidence' => 0.4],
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/dismiss", ['reason' => 'Resident sat down'])
            ->assertRedirect();

        $alert->refresh();
        $this->assertSame('dismissed', $alert->status);
        $this->assertSame('false_positive', $alert->resolution_code);
        $this->assertSame('Resident sat down', $alert->context['dismissed_reason']);

        $this->assertSame(0, ClientIncident::where('source', 'sensor')->count());

        $signal->refresh();
        $this->assertSame('suppressed', $signal->status);
    }

    public function test_confirm_requires_manage_permission(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create(['source' => 'sensor']);

        $this->actingAs($this->supportWorker)
            ->post("/control-room/alerts/{$alert->id}/confirm")
            ->assertForbidden();
    }

    public function test_cannot_confirm_a_resolved_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create(['source' => 'sensor']);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/confirm")
            ->assertSessionHasErrors('alert');

        $this->assertSame(0, ClientIncident::where('source', 'sensor')->count());
    }

    protected function scopeUserToSite(User $user, Site $site): void
    {
        HrEmployeeProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'tenant_id' => 1,
                'employee_number' => 'EMP-CR-'.$user->id,
                'work_email' => $user->email,
                'position_title' => 'Control Room',
                'position_role' => $user->role,
                'employment_type' => 'full_time',
                'start_date' => now()->subMonth()->toDateString(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
            ],
        );
    }
}
