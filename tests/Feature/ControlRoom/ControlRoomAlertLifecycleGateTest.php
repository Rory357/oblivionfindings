<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoom\SlaDefinition;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertWorkspaceService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Support\Journeys\JourneyGate;
use Database\Factories\ControlRoomAlertFactory;
use Database\Factories\HsEventFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlRoomAlertLifecycleGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['tenant_id' => 1]);
        $this->client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $this->site->id,
        ]);
        $this->admin = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $this->admin->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
    }

    public function test_open_alert_cannot_be_resolved_by_human_endpoint(): void
    {
        $alert = $this->alertFactory()->open()->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'This must not skip acknowledgement and triage.',
                'resolution_code' => 'controlled',
            ])
            ->assertSessionHasErrors('alert');

        $this->assertSame(ControlRoomAlert::STATUS_OPEN, $alert->fresh()->status);
    }

    public function test_each_non_terminal_operational_task_blocks_resolution(): void
    {
        foreach ([AlertTask::STATUS_OPEN, AlertTask::STATUS_IN_PROGRESS, AlertTask::STATUS_BLOCKED] as $status) {
            $alert = $this->alertFactory()->triaging()->create();
            $this->makeTask($alert, $status);

            $this->actingAs($this->admin)
                ->post("/control-room/alerts/{$alert->id}/resolve", [
                    'resolution_notes' => 'Attempted while operational work remains.',
                    'resolution_code' => 'controlled',
                ])
                ->assertSessionHasErrors('alert');

            $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);
        }
    }

    public function test_completed_and_cancelled_tasks_permit_resolution(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $this->makeTask($alert, AlertTask::STATUS_COMPLETED, ['completed_at' => now()]);
        $this->makeTask($alert, AlertTask::STATUS_CANCELLED);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'All operational tasks have a truthful outcome.',
                'resolution_code' => 'controlled',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_valid_canonical_hs_transfer_permits_resolution(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'The remaining work has an accepted H&S owner and corrective action.',
                'resolution_code' => 'transferred_to_hs',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_bare_transferred_task_blocks_resolution(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $this->makeTask($alert, AlertTask::STATUS_TRANSFERRED);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'A status label alone must not prove the handover.',
                'resolution_code' => 'transferred_to_hs',
            ])
            ->assertSessionHasErrors('alert');

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);
    }

    public function test_resolve_gate_is_typed_links_to_operational_work_and_does_not_require_hs_closure(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_OPEN,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS, [
            'title' => 'Confirm the temporary control remains effective',
        ]);
        $lifecycle = app(ControlRoomAlertLifecycleService::class);

        $blocked = $lifecycle->resolveGate($alert);

        $this->assertInstanceOf(JourneyGate::class, $blocked);
        $this->assertFalse($blocked->allowed);
        $requirement = collect($blocked->requirements)->firstWhere('key', 'operational_tasks');
        $this->assertNotNull($requirement);
        $this->assertFalse($requirement['complete']);
        $this->assertStringContainsString($task->title, $requirement['label']);
        $this->assertSame("/control-room/alerts/{$alert->id}", $requirement['href']);
        $this->assertNotContains('health_safety_closed', array_column($blocked->requirements, 'key'));
        foreach ($blocked->requirements as $item) {
            $this->assertSame(['key', 'complete', 'label', 'href'], array_keys($item));
        }

        $lifecycle->transferTaskToHealthSafety($task, $this->admin);
        $ready = $lifecycle->resolveGate($alert->fresh());

        $this->assertTrue($ready->allowed);
        $this->assertTrue(collect($ready->requirements)->firstWhere('key', 'operational_tasks')['complete']);
        $this->assertTrue($alert->hsEvent()->firstOrFail()->isOpen());

        $workspace = app(AlertWorkspaceService::class)->build($this->admin, $alert->id);
        $this->assertTrue(data_get($workspace, 'resolve_gate.allowed'));
        $this->assertFalse(data_get($workspace, 'close_gate.allowed'));
        $this->assertSame('Operational response active', data_get($workspace, 'journey_state'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resolution notes are required.');
        $lifecycle->resolve($alert->fresh(), $this->admin, '', 'transferred_to_hs');
    }

    public function test_alert_close_gate_requires_the_linked_incident_and_hs_governance_to_be_closed(): void
    {
        $alert = $this->alertFactory()->resolved()->create();
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'status' => 'reviewed',
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now()->subHour(),
            'reviewed_by' => $this->admin->id,
            'control_room_alert_id' => $alert->id,
        ]));
        $event = $this->hsEventFactory($alert)->forClientIncident($incident)->create([
            'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_OPEN,
        ]);
        $incident->updateQuietly(['hs_event_id' => $event->id]);
        $lifecycle = app(ControlRoomAlertLifecycleService::class);

        $blocked = $lifecycle->closeGate($alert);

        $this->assertFalse($blocked->allowed);
        $this->assertFalse(collect($blocked->requirements)->firstWhere('key', 'incident_closed')['complete']);
        $this->assertFalse(collect($blocked->requirements)->firstWhere('key', 'health_safety_closed')['complete']);
        $this->assertSame(
            "/incidents/{$incident->id}",
            collect($blocked->requirements)->firstWhere('key', 'incident_closed')['href'],
        );
        $this->assertSame(
            "/health-safety/events/{$event->id}",
            collect($blocked->requirements)->firstWhere('key', 'health_safety_closed')['href'],
        );

        $restricted = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $restricted->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $restrictedPermissions = Permission::query()
            ->whereIn('key', ['hazards.view', 'incidents.viewAny', 'incidents.viewAssigned'])
            ->pluck('id');
        $restricted->permissionOverrides()->sync(
            $restrictedPermissions->mapWithKeys(
                fn ($permissionId) => [$permissionId => ['allowed' => false]],
            ),
        );
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $restricted->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        $workspace = app(AlertWorkspaceService::class)->build($restricted, $alert->id);
        $this->assertNull(
            collect(data_get($workspace, 'close_gate.requirements'))
                ->firstWhere('key', 'incident_closed')['href'],
        );
        $this->assertNull(
            collect(data_get($workspace, 'close_gate.requirements'))
                ->firstWhere('key', 'health_safety_closed')['href'],
        );

        try {
            $lifecycle->close($alert, $this->admin);
            $this->fail('The linked journey should block alert closure.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('linked incident', strtolower($exception->getMessage()));
        }

        $event->forceFill([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $this->admin->id,
            'closure_summary' => 'Governance work is complete.',
        ])->saveQuietly();
        $incident->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->admin->id,
            'closed_outcome' => 'Journey complete',
        ])->saveQuietly();

        $this->assertTrue($lifecycle->closeGate($alert->fresh())->allowed);
        $closed = $lifecycle->close($alert->fresh(), $this->admin);
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $closed->status);
    }

    public function test_standalone_alert_close_rejects_multiple_direct_hs_events(): void
    {
        $alert = $this->alertFactory()->resolved()->create();
        $this->hsEventFactory($alert)->closed()->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $this->hsEventFactory($alert)->create([
            'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_OPEN,
        ]);
        $lifecycle = app(ControlRoomAlertLifecycleService::class);

        try {
            $lifecycle->close($alert, $this->admin);
            $this->fail('Multiple direct H&S events must never satisfy standalone alert closure.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('one canonical H&S event', $exception->getMessage());
        }

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_standalone_alert_close_rejects_a_foreign_hs_ownership_tuple(): void
    {
        $alert = $this->alertFactory()->resolved()->create();
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        $foreignClient = Client::factory()->create([
            'organization_id' => 2,
            'site_id' => $foreignSite->id,
        ]);
        HsEvent::factory()->closed()->create([
            'organization_id' => 2,
            'site_id' => $foreignSite->id,
            'client_id' => $foreignClient->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $lifecycle = app(ControlRoomAlertLifecycleService::class);

        try {
            $lifecycle->close($alert, $this->admin);
            $this->fail('A foreign H&S event must never satisfy standalone alert closure.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('provenance conflict', $exception->getMessage());
        }

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
    }

    public function test_incomplete_transferred_tuple_blocks_resolution(): void
    {
        foreach (['action', 'actor', 'time'] as $missingField) {
            $alert = $this->alertFactory()->triaging()->create();
            $event = $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
                'control_room_alert_id' => $alert->id,
            ]);
            $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id]);
            $tuple = [
                'transferred_to_hs_corrective_action_id' => $action->id,
                'transferred_by_user_id' => $this->admin->id,
                'transferred_at' => now(),
            ];
            $tuple[
                match ($missingField) {
                    'action' => 'transferred_to_hs_corrective_action_id',
                    'actor' => 'transferred_by_user_id',
                    default => 'transferred_at',
                }
            ] = null;
            $this->makeTask($alert, AlertTask::STATUS_TRANSFERRED, $tuple);

            $this->actingAs($this->admin)
                ->post("/control-room/alerts/{$alert->id}/resolve", [
                    'resolution_notes' => "The transferred {$missingField} must be present.",
                    'resolution_code' => 'transferred_to_hs',
                ])
                ->assertSessionHasErrors('alert');

            $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);
        }
    }

    public function test_task_cancellation_requires_and_audits_a_reason(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $task = $this->makeTask($alert, AlertTask::STATUS_OPEN);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/status", ['status' => AlertTask::STATUS_CANCELLED])
            ->assertSessionHasErrors('reason');

        $this->assertSame(AlertTask::STATUS_OPEN, $task->fresh()->status);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/status", [
                'status' => AlertTask::STATUS_CANCELLED,
                'reason' => 'No longer needed after the site lead confirmed the control.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $audit = AuditLog::query()
            ->where('action', 'controlRoom.task.statusChanged')
            ->where('auditable_id', $alert->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(AlertTask::STATUS_CANCELLED, $task->fresh()->status);
        $this->assertSame('No longer needed after the site lead confirmed the control.', $audit->meta['reason'] ?? null);
        $this->assertSame($this->admin->id, $audit->user_id);
    }

    public function test_acknowledge_and_triage_endpoints_commit_notes_with_the_transition_audit(): void
    {
        $alert = $this->alertFactory()->open()->withNotes('Original source payload')->create();

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/acknowledge", [
                'notes' => 'Caller and location confirmed.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();
        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/triage", [
                'notes' => 'Immediate controls are being coordinated.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $acknowledgeAudit = AuditLog::query()
            ->where('action', 'controlRoom.alert.acknowledge')
            ->where('auditable_id', $alert->id)
            ->firstOrFail();
        $triageAudit = AuditLog::query()
            ->where('action', 'controlRoom.alert.triage')
            ->where('auditable_id', $alert->id)
            ->firstOrFail();

        $this->assertSame('Caller and location confirmed.', $acknowledgeAudit->meta['operator_note'] ?? null);
        $this->assertSame('Immediate controls are being coordinated.', $triageAudit->meta['operator_note'] ?? null);
        $this->assertSame(
            0,
            AuditLog::query()
                ->where('action', 'controlRoom.alert.addNote')
                ->where('auditable_id', $alert->id)
                ->count(),
        );
        $this->assertSame('Original source payload', $alert->fresh()->notes);
    }

    public function test_transfer_to_hs_creates_one_corrective_action_and_is_retry_safe(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $event = $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS, [
            'title' => 'Replace damaged bathroom grab rail',
            'description' => 'Arrange a permanent repair and retain completion evidence.',
            'priority' => 'high',
            'assigned_to_user_id' => $this->admin->id,
            'due_at' => now()->addDays(5),
        ]);

        $url = "/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety";

        $this->actingAs($this->admin)->post($url)->assertRedirect()->assertSessionDoesntHaveErrors();
        $firstActionId = $task->fresh()->transferred_to_hs_corrective_action_id;
        $this->actingAs($this->admin)->post($url)->assertRedirect()->assertSessionDoesntHaveErrors();

        $task->refresh();
        $action = HsCorrectiveAction::query()->findOrFail($firstActionId);

        $this->assertSame(AlertTask::STATUS_TRANSFERRED, $task->status);
        $this->assertSame($firstActionId, $task->transferred_to_hs_corrective_action_id);
        $this->assertSame($this->admin->id, $task->transferred_by_user_id);
        $this->assertNotNull($task->transferred_at);
        $this->assertSame($event->id, $action->hs_event_id);
        $this->assertSame($task->title, $action->title);
        $this->assertSame(1, HsCorrectiveAction::query()->where('hs_event_id', $event->id)->count());
    }

    public function test_completed_transfer_retry_returns_the_same_action_after_parent_alert_is_terminal(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $event = $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS);
        $lifecycle = app(ControlRoomAlertLifecycleService::class);

        $firstAction = $lifecycle->transferTaskToHealthSafety($task, $this->admin);
        $lifecycle->resolve(
            $alert,
            $this->admin,
            'The operational task has an accepted H&S owner.',
            'transferred_to_hs',
        );
        $retriedAction = $lifecycle->transferTaskToHealthSafety($task, $this->admin);

        $this->assertSame(ControlRoomAlert::STATUS_RESOLVED, $alert->fresh()->status);
        $this->assertSame($firstAction->id, $retriedAction->id);
        $this->assertSame($event->id, $retriedAction->hs_event_id);
        $this->assertSame(1, HsCorrectiveAction::query()->where('hs_event_id', $event->id)->count());
    }

    public function test_transfer_requires_canonical_hs_handover_acceptance(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $event = $this->hsEventFactory($alert)->awaitingHandoverAcceptance($this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertRedirect()
            ->assertSessionHasErrors('task');

        $task->refresh();
        $this->assertSame(AlertTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertNull($task->transferred_to_hs_corrective_action_id);
        $this->assertSame(0, HsCorrectiveAction::query()->where('hs_event_id', $event->id)->count());
    }

    public function test_transfer_requires_an_explicit_due_date_before_creating_health_safety_work(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $event = $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS, ['due_at' => null]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertRedirect()
            ->assertSessionHasErrors('task');

        $this->assertSame(AlertTask::STATUS_IN_PROGRESS, $task->fresh()->status);
        $this->assertNull($task->fresh()->transferred_to_hs_corrective_action_id);
        $this->assertSame(0, HsCorrectiveAction::query()->where('hs_event_id', $event->id)->count());
    }

    public function test_transfer_retry_rejects_a_soft_deleted_corrective_action(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS);
        $url = "/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety";

        $this->actingAs($this->admin)->post($url)->assertSessionDoesntHaveErrors();
        HsCorrectiveAction::query()
            ->findOrFail($task->fresh()->transferred_to_hs_corrective_action_id)
            ->delete();

        $this->actingAs($this->admin)
            ->post($url)
            ->assertRedirect()
            ->assertSessionHasErrors('task');

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Deleted H&S work cannot satisfy the operational resolution gate.',
                'resolution_code' => 'transferred_to_hs',
            ])
            ->assertSessionHasErrors('alert');

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);
    }

    public function test_transfer_retry_and_resolution_reject_action_from_another_hs_event(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $this->hsEventFactory($alert)->handoverAccepted($this->admin, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $foreignEvent = HsEvent::factory()->handoverAccepted($this->admin, $this->admin)->create();
        $foreignAction = HsCorrectiveAction::factory()->create(['hs_event_id' => $foreignEvent->id]);
        $task = $this->makeTask($alert, AlertTask::STATUS_TRANSFERRED, [
            'transferred_to_hs_corrective_action_id' => $foreignAction->id,
            'transferred_by_user_id' => $this->admin->id,
            'transferred_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertRedirect()
            ->assertSessionHasErrors('task');

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/resolve", [
                'resolution_notes' => 'Only the corrective action on this alert journey can satisfy the gate.',
                'resolution_code' => 'transferred_to_hs',
            ])
            ->assertSessionHasErrors('alert');

        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->fresh()->status);
    }

    public function test_transfer_rejects_a_canonical_hs_event_with_a_foreign_ownership_tuple(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        HsEvent::factory()->handoverAccepted($this->admin, $this->admin)->create([
            'organization_id' => 2,
            'site_id' => $foreignSite->id,
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertRedirect()
            ->assertSessionHasErrors('task');

        $task->refresh();
        $this->assertSame(AlertTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertNull($task->transferred_to_hs_corrective_action_id);
        $this->assertSame(0, HsCorrectiveAction::query()->count());
    }

    public function test_transfer_assigns_the_corrective_action_to_the_accepted_hs_owner(): void
    {
        $alert = $this->alertFactory()->triaging()->create();
        $owner = User::factory()->create([
            'organization_id' => 1,
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $owner->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $owner->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
        ]);
        $this->hsEventFactory($alert)->handoverAccepted($owner, $this->admin)->create([
            'control_room_alert_id' => $alert->id,
        ]);
        $task = $this->makeTask($alert, AlertTask::STATUS_IN_PROGRESS, [
            'assigned_to_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$task->alert_id}/tasks/{$task->id}/transfer-to-health-safety")
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $action = HsCorrectiveAction::query()->findOrFail(
            $task->fresh()->transferred_to_hs_corrective_action_id,
        );
        $this->assertSame($owner->id, $action->assigned_to_user_id);
        $this->assertNotSame($task->assigned_to_user_id, $action->assigned_to_user_id);
    }

    public function test_incident_reopen_marks_attention_until_operator_explicitly_reopens_response(): void
    {
        $alert = $this->alertFactory()->closed()->create(['context' => ['existing_key' => 'preserved']]);
        SlaDefinition::create([
            'name' => 'Incident reopen lifecycle gate SLA',
            'code' => 'incident-reopen-gate-'.$alert->id,
            'acknowledge_target_minutes' => 5,
            'response_target_minutes' => 10,
            'resolution_target_minutes' => 30,
            'is_active' => true,
        ]);
        $incident = ClientIncident::factory()->create([
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'status' => 'closed',
            'control_room_alert_id' => $alert->id,
            'closed_by' => $this->admin->id,
            'closed_at' => now()->subDay(),
            'closed_outcome' => 'Resolved at the time',
        ]);

        $this->actingAs($this->admin)
            ->post("/incidents/{$incident->id}/reopen", [
                'reopened_reason' => 'A witness has supplied material new information.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_CLOSED, $alert->status);
        $this->assertSame('preserved', $alert->context['existing_key'] ?? null);
        $this->assertSame($incident->id, $alert->context['journey_attention']['incident_id'] ?? null);

        $this->actingAs($this->admin)
            ->post("/control-room/alerts/{$alert->id}/reopen-for-incident", [
                'reason' => 'Reassess immediate controls against the new witness information.',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $alert->refresh();
        $this->assertSame(ControlRoomAlert::STATUS_TRIAGING, $alert->status);
        $this->assertArrayNotHasKey('journey_attention', $alert->context ?? []);
        $this->assertSame('preserved', $alert->context['existing_key'] ?? null);
    }

    private function makeTask(ControlRoomAlert $alert, string $status, array $overrides = []): AlertTask
    {
        return AlertTask::create(array_merge([
            'alert_id' => $alert->id,
            'title' => 'Operational response task',
            'priority' => 'medium',
            'status' => $status,
            'due_at' => now()->addDays(5),
            'created_by_user_id' => $this->admin->id,
            'sort_order' => 1,
        ], $overrides));
    }

    private function alertFactory(): ControlRoomAlertFactory
    {
        return ControlRoomAlert::factory()->state([
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
        ]);
    }

    private function hsEventFactory(ControlRoomAlert $alert): HsEventFactory
    {
        return HsEvent::factory()->state([
            'organization_id' => 1,
            'site_id' => $alert->site_id,
            'client_id' => $alert->client_id,
        ]);
    }
}
