<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\EvidencePack;
use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Factories\ControlRoomAlertFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ControlRoomAlertControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $coordinator;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);

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
        $alert = $this->alertFactory()->create();
        $this->get("/control-room/alerts/{$alert->id}")->assertRedirect('/login');
    }

    public function test_show_displays_alert(): void
    {
        $alert = $this->alertFactory()->create();

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
        $alert = $this->alertFactory()->assignedTo($this->coordinator)->create();

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
        $alert = $this->alertFactory()->create();
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
        $alert = $this->alertFactory()->open()->create();

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

    public function test_update_meta_cannot_rewrite_resolution_provenance(): void
    {
        $alert = $this->alertFactory()->resolved()->create([
            'resolution_code' => 'controlled_scene',
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/meta", [
                'category' => 'follow_up',
                'resolution_code' => 'silently_rewritten',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $alert->refresh();
        $this->assertSame('follow_up', $alert->category);
        $this->assertSame('controlled_scene', $alert->resolution_code);
    }

    // ──────────────────────────────────────
    // Acknowledge Alert
    // ──────────────────────────────────────

    public function test_acknowledge_open_alert(): void
    {
        $alert = $this->alertFactory()->open()->create();

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
        $alert = $this->alertFactory()->open()->withNotes('Original notes')->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertRedirect();

        $alert->refresh();
        $this->assertEquals('ack', $alert->status);
        $this->assertEquals('Original notes', $alert->notes);
    }

    public function test_cannot_acknowledge_resolved_alert(): void
    {
        $alert = $this->alertFactory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertSessionHasErrors('alert');
    }

    public function test_cannot_acknowledge_closed_alert(): void
    {
        $alert = $this->alertFactory()->closed()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertSessionHasErrors('alert');
    }

    public function test_acknowledge_requires_manage_permission(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->supportWorker)
            ->post("/control-room/alerts/{$alert->id}/acknowledge")
            ->assertForbidden();
    }

    // ──────────────────────────────────────
    // Triage Alert
    // ──────────────────────────────────────

    public function test_open_alert_must_be_acknowledged_before_triage(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage")
            ->assertRedirect()
            ->assertSessionHasErrors('alert');

        $this->assertDatabaseHas('control_room_alerts', [
            'id' => $alert->id,
            'status' => 'open',
        ]);
    }

    public function test_triage_acknowledged_alert(): void
    {
        $alert = $this->alertFactory()->acknowledged()->create();

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
        $alert = $this->alertFactory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage")
            ->assertSessionHasErrors('alert');
    }

    // ──────────────────────────────────────
    // Resolve Alert
    // ──────────────────────────────────────

    public function test_resolve_alert(): void
    {
        $alert = $this->alertFactory()->triaging()->create();

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
        $alert = $this->alertFactory()->triaging()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [])
            ->assertSessionHasErrors('resolution_notes');
    }

    public function test_cannot_resolve_already_resolved_alert(): void
    {
        $alert = $this->alertFactory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Trying again',
            ])
            ->assertSessionHasErrors('alert');
    }

    public function test_cannot_resolve_closed_alert(): void
    {
        $alert = $this->alertFactory()->closed()->create();

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
        $alert = $this->alertFactory()->resolved()->create();

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
        $alert = $this->alertFactory()->open()->create();

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
        $alert = $this->alertFactory()->closed()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/close")
            ->assertSessionHasErrors('alert');
    }

    // ──────────────────────────────────────
    // Assign Alert
    // ──────────────────────────────────────

    public function test_assign_alert_to_user(): void
    {
        $alert = $this->alertFactory()->open()->create();

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

    public function test_assignment_note_is_appended_without_overwriting_original_alert_notes(): void
    {
        $alert = $this->alertFactory()->open()->create([
            'notes' => 'Original device and reporter payload.',
            'context' => ['existing_key' => 'preserved'],
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => $this->coordinator->id,
                'notes' => 'Assigned because the coordinator is leading the site response.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $alert->refresh();
        $this->assertSame('Original device and reporter payload.', $alert->notes);
        $this->assertSame('preserved', $alert->context['existing_key'] ?? null);
        $this->assertTrue(collect($alert->context['activity_log'] ?? [])->contains(
            fn (array $entry): bool => ($entry['content'] ?? null)
                === 'Assigned because the coordinator is leading the site response.',
        ));
    }

    public function test_individual_and_bulk_assignment_scope_and_lock_the_assignee_in_one_query(): void
    {
        $site = Site::factory()->create(['tenant_id' => $this->admin->organization_id]);

        $individualAlert = $this->alertFactory()->open()->create(['site_id' => $site->id]);
        $individualQueries = $this->captureDatabaseQueries(
            fn () => $this->actingAs($this->admin)
                ->post("/control-room/alerts/{$individualAlert->id}/assign", [
                    'assigned_to_user_id' => $this->coordinator->id,
                ])
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors(),
        );
        $this->assertScopedAssigneeLockAfterAlertLock(
            $individualQueries,
            $this->coordinator->id,
            'individual assignment',
        );

        $bulkAlert = $this->alertFactory()->open()->create(['site_id' => $site->id]);
        $bulkQueries = $this->captureDatabaseQueries(
            fn () => $this->actingAs($this->admin)
                ->post('/control-room/alerts/bulk-assign', [
                    'alert_ids' => [$bulkAlert->id],
                    'assigned_to_user_id' => $this->coordinator->id,
                ])
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors(),
        );
        $this->assertScopedAssigneeLockAfterAlertLock(
            $bulkQueries,
            $this->coordinator->id,
            'bulk assignment',
        );
    }

    public function test_individual_and_bulk_assignment_use_fresh_actor_site_access_inside_the_transaction(): void
    {
        $authorizedSite = Site::factory()->create([
            'tenant_id' => $this->coordinator->organization_id,
            'type' => 'house',
        ]);
        $revokedToSite = Site::factory()->create([
            'tenant_id' => $this->coordinator->organization_id,
            'type' => 'house',
        ]);
        $assignee = $this->makeRoleUser('coordinator');
        $this->scopeUserToSite($this->coordinator, $authorizedSite);
        $this->scopeUserToSite($assignee, $authorizedSite);

        // Preserve the preflight request actor's old relationship snapshot, then
        // revoke its site in the database before either assignment transaction.
        $this->coordinator->load('hrEmployeeProfile');
        HrEmployeeProfile::query()
            ->where('user_id', $this->coordinator->id)
            ->update([
                'primary_site_id' => $revokedToSite->id,
                'secondary_site_ids' => [],
            ]);

        $individualAlert = $this->alertFactory()->open()->create([
            'site_id' => $authorizedSite->id,
        ]);
        $bulkAlert = $this->alertFactory()->open()->create([
            'site_id' => $authorizedSite->id,
        ]);

        $this->actingAs($this->coordinator)
            ->post("/control-room/alerts/{$individualAlert->id}/assign", [
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertForbidden();
        $this->actingAs($this->coordinator)
            ->post('/control-room/alerts/bulk-assign', [
                'alert_ids' => [$bulkAlert->id],
                'assigned_to_user_id' => $assignee->id,
            ])
            ->assertForbidden();

        $this->assertNull($individualAlert->fresh()->assigned_to_user_id);
        $this->assertNull($bulkAlert->fresh()->assigned_to_user_id);
    }

    public function test_individual_and_bulk_assignment_roll_back_when_strict_audit_writing_fails(): void
    {
        $site = Site::factory()->create(['tenant_id' => $this->admin->organization_id]);
        $individualAlert = $this->alertFactory()->open()->create(['site_id' => $site->id]);
        $this->assertAssignmentRollsBackOnAuditFailure(
            $individualAlert,
            fn () => $this->actingAs($this->admin)
                ->post("/control-room/alerts/{$individualAlert->id}/assign", [
                    'assigned_to_user_id' => $this->coordinator->id,
                ]),
        );

        $bulkAlert = $this->alertFactory()->open()->create(['site_id' => $site->id]);
        $this->assertAssignmentRollsBackOnAuditFailure(
            $bulkAlert,
            fn () => $this->actingAs($this->admin)
                ->post('/control-room/alerts/bulk-assign', [
                    'alert_ids' => [$bulkAlert->id],
                    'assigned_to_user_id' => $this->coordinator->id,
                ]),
        );
    }

    public function test_alert_assignment_and_escalation_mutations_lock_alerts_before_writing(): void
    {
        $bulkAlert = $this->alertFactory()->open()->create();
        $bulkQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post('/control-room/alerts/bulk-assign', [
                'alert_ids' => [$bulkAlert->id],
                'assigned_to_user_id' => $this->coordinator->id,
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesUpdate($bulkQueries, 'bulk assignment');

        $selfAssignAlert = $this->alertFactory()->open()->create();
        $selfAssignQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$selfAssignAlert->id}/assign-to-me")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesUpdate($selfAssignQueries, 'self assignment');

        $unassignAlert = $this->alertFactory()->open()->assignedTo($this->coordinator)->create();
        $unassignQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$unassignAlert->id}/unassign")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesUpdate($unassignQueries, 'unassignment');

        $escalationAlert = $this->alertFactory()->open()->create(['escalation_level' => 0]);
        $escalationQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$escalationAlert->id}/escalate", [
                'escalation_reason' => 'Senior oversight is required.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesUpdate($escalationQueries, 'manual escalation');

        $noteAlert = $this->alertFactory()->open()->create();
        $noteQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$noteAlert->id}/note", [
                'note' => 'This note must append against the locked alert context.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesUpdate($noteQueries, 'operator note');

        $snoozeAlert = $this->alertFactory()->open()->create(['severity' => 'medium']);
        $snoozeQueries = $this->captureDatabaseQueries(fn () => $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$snoozeAlert->id}/snooze", ['window' => '15m'])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors());
        $this->assertAlertLockPrecedesUpdate($snoozeQueries, 'snooze');

        $this->assertSame(
            'assigned',
            data_get(collect($bulkAlert->fresh()->context['assignment_history'] ?? [])->last(), 'action'),
        );
        $this->assertSame(
            'assigned',
            data_get(collect($selfAssignAlert->fresh()->context['assignment_history'] ?? [])->last(), 'action'),
        );
        $this->assertSame(
            'unassigned',
            data_get(collect($unassignAlert->fresh()->context['assignment_history'] ?? [])->last(), 'action'),
        );
    }

    public function test_bulk_assign_skips_terminal_alerts_after_locking_the_full_selection(): void
    {
        $open = $this->alertFactory()->open()->create();
        $resolved = $this->alertFactory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post('/control-room/alerts/bulk-assign', [
                'alert_ids' => [$resolved->id, $open->id],
                'assigned_to_user_id' => $this->coordinator->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '1 alert(s) assigned. 1 skipped (terminal).');

        $this->assertSame($this->coordinator->id, $open->fresh()->assigned_to_user_id);
        $this->assertNull($resolved->fresh()->assigned_to_user_id);
    }

    public function test_terminal_alerts_are_read_only_for_self_assignment_and_unassignment(): void
    {
        $unassigned = $this->alertFactory()->resolved()->create();
        $assigned = $this->alertFactory()->resolved()->assignedTo($this->coordinator)->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$unassigned->id}/assign-to-me")
            ->assertSessionHasErrors('alert');
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$assigned->id}/unassign")
            ->assertSessionHasErrors('alert');

        $this->assertNull($unassigned->fresh()->assigned_to_user_id);
        $this->assertSame($this->coordinator->id, $assigned->fresh()->assigned_to_user_id);
    }

    public function test_assign_requires_valid_user_id(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/assign", [
                'assigned_to_user_id' => 99999,
            ])
            ->assertSessionHasErrors('assigned_to_user_id');
    }

    public function test_assign_requires_assign_permission(): void
    {
        $alert = $this->alertFactory()->open()->create();

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

        $alert = $this->alertFactory()->open()->create([
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
        $alert = $this->alertFactory()->assignedTo($this->coordinator)->create();

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
        $alert = $this->alertFactory()->open()->create(['escalation_level' => 0]);

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
        $alert = $this->alertFactory()->open()->create(['escalation_level' => 0]);

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
        $alert = $this->alertFactory()->open()->create(['escalation_level' => 4]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Maximum escalation',
                'escalation_level' => 10,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Alert escalated to level 5.');

        $alert->refresh();
        $audit = AuditLog::query()
            ->where('action', 'controlRoom.alert.escalate')
            ->where('auditable_id', $alert->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(5, $alert->escalation_level);
        $this->assertSame(5, data_get(collect($alert->context['escalation_history'] ?? [])->last(), 'level'));
        $this->assertSame(5, $audit->meta['escalation_level'] ?? null);
    }

    public function test_requested_escalation_level_cannot_decrease_the_current_level(): void
    {
        $alert = $this->alertFactory()->open()->create(['escalation_level' => 4]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Keep senior oversight in place.',
                'escalation_level' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Alert escalated to level 4.');

        $alert->refresh();
        $audit = AuditLog::query()
            ->where('action', 'controlRoom.alert.escalate')
            ->where('auditable_id', $alert->id)
            ->latest('id')
            ->firstOrFail();

        $history = collect($alert->context['escalation_history'] ?? [])->last();

        $this->assertSame(4, $alert->escalation_level);
        $this->assertSame(4, data_get($history, 'level'));
        $this->assertSame(2, data_get($history, 'requested_level'));
        $this->assertSame(4, $audit->meta['escalation_level'] ?? null);
        $this->assertSame(2, $audit->meta['requested_level'] ?? null);
    }

    public function test_cannot_escalate_resolved_alert(): void
    {
        $alert = $this->alertFactory()->resolved()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [
                'escalation_reason' => 'Should fail',
            ])
            ->assertSessionHasErrors('alert');
    }

    public function test_escalate_requires_reason(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/escalate", [])
            ->assertSessionHasErrors('escalation_reason');
    }

    public function test_escalate_requires_permission(): void
    {
        $alert = $this->alertFactory()->open()->create();

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
        $alert = $this->alertFactory()->open()->create(['context' => []]);

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
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/note", [])
            ->assertSessionHasErrors('note');
    }

    public function test_add_note_validates_max_length(): void
    {
        $alert = $this->alertFactory()->open()->create();

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
                'site_id' => $this->site->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('control_room_alerts', [
            'source' => 'manual',
            'alert_type' => 'Test Alert',
            'severity' => 'high',
            'status' => 'open',
            'created_by_user_id' => $this->admin->id,
            'site_id' => $this->site->id,
        ]);
    }

    public function test_create_alert_accepts_client_site_and_priority(): void
    {
        $site = Site::factory()->create([
            'tenant_id' => $this->admin->organization_id,
        ]);
        $client = Client::factory()->create([
            'organization_id' => $this->admin->organization_id,
            'site_id' => $site->id,
        ]);

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
                'site_id' => $this->site->id,
            ], ['X-Inertia' => 'true']);

        $alert = ControlRoomAlert::query()->latest('id')->first();
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
                'site_id' => $this->site->id,
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
                'site_id' => $this->site->id,
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
        $alert = $this->alertFactory()->triaging()->create();

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
        $alert = $this->alertFactory()->open()->create(['escalation_level' => 0]);

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
        $alert = $this->alertFactory()->open()->create(['context' => []]);

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
        $alert = $this->alertFactory()->open()->create();

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
        $alert = $this->alertFactory()->open()->create();
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

        $alert = $this->alertFactory()->open()->create();
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
                'site_id' => $this->site->id,
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
        $alert = $this->alertFactory()->open()->create([
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
            ->assertSessionHasErrors('immediate_action_taken');

        $this->assertDatabaseCount('client_incidents', 0);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/confirm", [
                'immediate_action_taken' => 'Resident checked and the area made safe.',
            ])
            ->assertRedirect();

        $incident = ClientIncident::where('source', 'sensor')->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertSame('fall', $incident->type);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame($alert->id, $incident->control_room_alert_id);
        $this->assertSame(
            'Resident checked and the area made safe.',
            $incident->immediate_action_taken,
        );
        $this->assertFalse($incident->interactive);
        $this->assertSame('fall_detected', $incident->metadata['sensor_evidence']['signal_type'] ?? null);

        $alert->refresh();
        $this->assertSame('confirmed', $alert->status);
        $this->assertSame($incident->id, $alert->context['incident_id']);
    }

    public function test_dismiss_logs_false_positive_without_incident(): void
    {
        $client = Client::factory()->create();
        $alert = $this->alertFactory()->open()->create([
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
        $alert = $this->alertFactory()->open()->create(['source' => 'sensor']);

        $this->actingAs($this->supportWorker)
            ->post("/control-room/alerts/{$alert->id}/confirm")
            ->assertForbidden();
    }

    public function test_cannot_confirm_a_resolved_alert(): void
    {
        $alert = $this->alertFactory()->resolved()->create(['source' => 'sensor']);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/confirm", [
                'immediate_action_taken' => 'Resident checked before the alert was resolved.',
            ])
            ->assertSessionHasErrors('alert');

        $this->assertSame(0, ClientIncident::where('source', 'sensor')->count());
    }

    public function test_non_sensor_alerts_cannot_use_sensor_confirm_or_dismiss_workflows(): void
    {
        $client = Client::factory()->create();
        $confirmAlert = $this->alertFactory()->open()->create([
            'source' => 'manual',
            'alert_type' => 'incident.manual',
            'client_id' => $client->id,
        ]);
        $dismissAlert = $this->alertFactory()->open()->create([
            'source' => 'integration',
            'alert_type' => 'integration.offline',
            'client_id' => $client->id,
        ]);
        $signal = Signal::create([
            'alert_id' => $dismissAlert->id,
            'client_id' => $client->id,
            'signal_type_code' => 'manual_test_signal',
            'occurred_at' => now(),
            'payload' => [],
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$confirmAlert->id}/confirm", [
                'immediate_action_taken' => 'No immediate control was possible',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('alert');
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$dismissAlert->id}/dismiss", [
                'reason' => 'This endpoint must not suppress an integration signal.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('alert');

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $confirmAlert->fresh()->status);
        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $dismissAlert->fresh()->status);
        $this->assertSame('pending', $signal->fresh()->status);
        $this->assertSame(0, ClientIncident::query()->count());
    }

    public function test_update_meta_parses_due_at_as_nz_wall_time_and_stores_utc(): void
    {
        $alert = $this->alertFactory()->open()->create();

        // The workspace Due field posts a naive datetime-local string typed in
        // NZ wall time; 10pm NZST (UTC+12) must store as 10am UTC the same day.
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/meta", [
                'due_at' => '2026-07-07T22:00',
            ])
            ->assertRedirect();

        $this->assertSame(
            '2026-07-07 10:00:00',
            $alert->fresh()->due_at->utc()->toDateTimeString(),
        );
    }

    // ── Snooze ──────────────────────────────────────────────────────────

    public function test_snooze_sets_window_and_records_snoozer(): void
    {
        $alert = $this->alertFactory()->open()->create(['severity' => 'medium']);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/snooze", ['window' => '1h'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $alert->refresh();
        $this->assertNotNull($alert->snoozed_until);
        $this->assertTrue($alert->snoozed_until->isFuture());
        $this->assertSame($this->admin->id, $alert->snoozed_by_user_id);
        $this->assertTrue($alert->isSnoozed());
    }

    public function test_snooze_blocks_critical_alerts(): void
    {
        $alert = $this->alertFactory()->open()->create(['severity' => 'critical']);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/snooze", ['window' => '15m'])
            ->assertSessionHasErrors('alert');

        $this->assertNull($alert->fresh()->snoozed_until);
    }

    public function test_snooze_blocks_resolved_alerts(): void
    {
        $alert = $this->alertFactory()->resolved()->create(['severity' => 'medium']);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/snooze", ['window' => '15m'])
            ->assertSessionHasErrors('alert');
    }

    public function test_custom_snooze_parses_the_worker_timezone(): void
    {
        $alert = $this->alertFactory()->open()->create(['severity' => 'low']);

        // Naive datetime-local typed in NZ wall time must store UTC — treating it
        // as UTC would land the snooze 12 hours off.
        $localDate = now()->timezone(config('app.worker_timezone'))->addDay()->format('Y-m-d');
        $expectedUtc = Carbon::parse($localDate.'T22:00', config('app.worker_timezone'))
            ->utc()->format('Y-m-d H:i:s');

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/snooze", [
                'window' => 'custom',
                'snoozed_until' => $localDate.'T22:00',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($expectedUtc, $alert->fresh()->snoozed_until->utc()->format('Y-m-d H:i:s'));
    }

    public function test_custom_snooze_requires_a_future_time(): void
    {
        $alert = $this->alertFactory()->open()->create(['severity' => 'medium']);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/snooze", ['window' => 'custom'])
            ->assertSessionHasErrors('snoozed_until');
    }

    public function test_unsnooze_returns_alert_to_the_worklist(): void
    {
        $alert = $this->alertFactory()->open()->create([
            'severity' => 'medium',
            'snoozed_until' => now()->addHour(),
            'snoozed_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/unsnooze")
            ->assertRedirect();

        $alert->refresh();
        $this->assertNull($alert->snoozed_until);
        $this->assertNull($alert->snoozed_by_user_id);
        $this->assertFalse($alert->isSnoozed());
    }

    public function test_task7_final_gap_default_and_all_alert_lists_are_positive_actionable_worklists(): void
    {
        $active = $this->alertFactory()->open()->create();
        $resolved = $this->alertFactory()->resolved()->create();
        $this->alertFactory()->closed()->create();
        $this->alertFactory()->create(['status' => ControlRoomAlert::STATUS_DISMISSED]);
        $legacy = $this->alertFactory()->open()->create();
        DB::table('control_room_alerts')->where('id', $legacy->id)->update(['status' => 'legacy_unknown']);

        $onlyActive = fn ($rows): bool => collect($rows)->pluck('id')->all() === [$active->id];

        $this->actingAs($this->admin)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data', $onlyActive));

        $this->actingAs($this->admin)
            ->get('/control-room/alerts?status=all')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('alerts.data', $onlyActive));

        $this->actingAs($this->admin)
            ->get('/control-room/alerts?status=resolved')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $resolved->id));
    }

    public function test_index_hides_snoozed_by_default_and_the_snoozed_tab_shows_them(): void
    {
        $open = $this->alertFactory()->open()->create(['severity' => 'medium']);
        $snoozed = $this->alertFactory()->open()->create([
            'severity' => 'medium',
            'snoozed_until' => now()->addHour(),
            'snoozed_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/alerts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/alerts/index')
                ->where('stats.snoozed', 1)
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $open->id)
            );

        $this->actingAs($this->admin)
            ->get('/control-room/alerts?snoozed=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $snoozed->id)
            );
    }

    public function test_expired_snooze_returns_to_the_default_worklist(): void
    {
        $expired = $this->alertFactory()->open()->create([
            'severity' => 'medium',
            'snoozed_until' => now()->subMinute(),
            'snoozed_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/control-room/alerts')
            ->assertInertia(fn ($page) => $page
                ->where('stats.snoozed', 0)
                ->has('alerts.data', 1)
                ->where('alerts.data.0.id', $expired->id)
            );
    }

    /** @return list<array{query: string, bindings: array<mixed>, time: float}> */
    private function captureDatabaseQueries(callable $action): array
    {
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $action();

            return $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
    }

    /** @param list<array{query: string, bindings: array<mixed>, time: float}> $queries */
    private function assertAlertLockPrecedesUpdate(array $queries, string $operation): void
    {
        $sql = collect($queries)
            ->pluck('query')
            ->map(fn (string $query): string => strtolower(str_replace([chr(96), '"'], '', $query)))
            ->values();
        $lockIndex = $sql->search(
            fn (string $query): bool => str_contains($query, 'from control_room_alerts')
                && str_contains($query, 'for update'),
        );
        $updateIndex = $sql->search(
            fn (string $query): bool => str_starts_with($query, 'update control_room_alerts'),
        );

        $this->assertNotFalse($lockIndex, "The {$operation} must lock its alert row.");
        $this->assertNotFalse($updateIndex, "The {$operation} must update the alert row.");
        $this->assertLessThan(
            $updateIndex,
            $lockIndex,
            "The {$operation} must acquire its alert lock before writing.",
        );
    }

    /** @param list<array{query: string, bindings: array<mixed>, time: float}> $queries */
    private function assertScopedAssigneeLockAfterAlertLock(
        array $queries,
        int $assigneeId,
        string $operation,
    ): void {
        $queries = collect($queries)
            ->map(fn (array $query): array => [
                ...$query,
                'normalized' => strtolower(str_replace([chr(96), '"'], '', $query['query'])),
            ])
            ->values();
        $alertLockIndex = $queries->search(
            fn (array $query): bool => str_contains($query['normalized'], 'from control_room_alerts')
                && str_contains($query['normalized'], 'for update'),
        );
        $scopedAssigneeLockIndex = $queries->search(
            fn (array $query, int $index): bool => $alertLockIndex !== false
                && $index > $alertLockIndex
                && str_contains($query['normalized'], 'from users')
                && str_contains($query['normalized'], 'role_user')
                && str_contains($query['normalized'], 'for update')
                && in_array($assigneeId, $query['bindings'], true),
        );
        $unscopedAssigneeLockIndex = $queries->search(
            fn (array $query, int $index): bool => $alertLockIndex !== false
                && $index > $alertLockIndex
                && str_contains($query['normalized'], 'from users')
                && ! str_contains($query['normalized'], 'role_user')
                && str_contains($query['normalized'], 'for update')
                && in_array($assigneeId, $query['bindings'], true),
        );

        $this->assertNotFalse($alertLockIndex, "The {$operation} must lock its alert row first.");
        $this->assertNotFalse(
            $scopedAssigneeLockIndex,
            "The {$operation} must check eligibility and lock the assignee in one scoped query.",
        );
        $this->assertFalse(
            $unscopedAssigneeLockIndex,
            "The {$operation} must not follow an eligibility check with an unscoped assignee lock.",
        );
    }

    private function assertAssignmentRollsBackOnAuditFailure(
        ControlRoomAlert $alert,
        callable $assignment,
    ): void {
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated strict audit write failure.');
        });
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $assignment();
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
            Event::forget($eventName);
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('Simulated strict audit write failure.', $caught?->getMessage());
        $this->assertNull($alert->fresh()->assigned_to_user_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'controlRoom.alert.assign',
            'auditable_id' => $alert->id,
        ]);
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state([
            'site_id' => $this->site->id,
        ]);
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
