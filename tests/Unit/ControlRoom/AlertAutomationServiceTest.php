<?php

namespace Tests\Unit\ControlRoom;

use App\Models\ControlRoom\Playbook;
use App\Models\ControlRoom\PlaybookRun;
use App\Models\ControlRoom\PlaybookStep;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\AlertAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the safe-automation contract documented at
 * `app/Services/ControlRoom/AlertAutomationService.php`:
 *
 *   - autoAssign respects already-assigned + terminal alerts
 *   - autoAssign priority chain (queue users → queue roles + site → queue
 *     roles fallback → site primary contact → unassigned)
 *   - autoStartPlaybook transitions pending runs to in_progress
 *   - onAlertEscalated adds escalation watchers at level ≥ 2
 *
 * Closes the H6 service unit-test gap from
 * `docs/control-room-readiness-plan.md`.
 */
class AlertAutomationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AlertAutomationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->service = new AlertAutomationService();
    }

    // ──────────────────────────────────────
    // autoAssign — short-circuits
    // ──────────────────────────────────────

    public function test_auto_assign_skips_already_assigned_alert(): void
    {
        $existingAssignee = User::factory()->create();
        $alert = ControlRoomAlert::factory()->open()->create([
            'assigned_to_user_id' => $existingAssignee->id,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertSame($existingAssignee->id, $alert->assigned_to_user_id);
    }

    public function test_auto_assign_skips_terminal_alert(): void
    {
        $alert = ControlRoomAlert::factory()->resolved()->create([
            'assigned_to_user_id' => null,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertNull($alert->assigned_to_user_id);
    }

    public function test_auto_assign_leaves_unassigned_when_no_match(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'queue_id' => null,
            'site_id' => null,
            'assigned_to_user_id' => null,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertNull($alert->assigned_to_user_id);
    }

    // ──────────────────────────────────────
    // autoAssign — priority chain
    // ──────────────────────────────────────

    public function test_auto_assign_uses_first_queue_user_in_configured_order(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $queue = TriageQueue::create([
            'name' => 'Tier 1',
            'code' => 't1',
            'tier' => 1,
            'is_active' => true,
            'assigned_users' => [$first->id, $second->id],
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'assigned_to_user_id' => null,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertSame($first->id, $alert->assigned_to_user_id);
        $this->assertSame(
            "queue_user:{$queue->name}",
            $alert->context['auto_assign_reason'] ?? null,
        );
    }

    public function test_auto_assign_prefers_queue_role_user_at_alerts_site(): void
    {
        $coordinatorRole = Role::firstWhere('name', 'coordinator');
        $this->assertNotNull($coordinatorRole, 'coordinator role missing — RbacSeeder did not run');

        $offSiteCoordinator = User::factory()->create();
        $offSiteCoordinator->roles()->attach($coordinatorRole);

        $siteContact = User::factory()->create();
        $siteContact->roles()->attach($coordinatorRole);

        $site = Site::factory()->create([
            'type' => 'house',
            'primary_contact_user_id' => $siteContact->id,
        ]);

        $queue = TriageQueue::create([
            'name' => 'Coord Queue',
            'code' => 'coord',
            'tier' => 1,
            'is_active' => true,
            'assigned_roles' => ['coordinator'],
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'site_id' => $site->id,
            'assigned_to_user_id' => null,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertSame($siteContact->id, $alert->assigned_to_user_id);
        $this->assertSame(
            "queue_role_site:{$queue->name}",
            $alert->context['auto_assign_reason'] ?? null,
        );
    }

    public function test_auto_assign_falls_back_to_any_queue_role_user_when_no_site_match(): void
    {
        $coordinatorRole = Role::firstWhere('name', 'coordinator');

        $coordinator = User::factory()->create();
        $coordinator->roles()->attach($coordinatorRole);

        $queue = TriageQueue::create([
            'name' => 'Coord Queue',
            'code' => 'coord',
            'tier' => 1,
            'is_active' => true,
            'assigned_roles' => ['coordinator'],
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'queue_id' => $queue->id,
            'site_id' => null,
            'assigned_to_user_id' => null,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertSame($coordinator->id, $alert->assigned_to_user_id);
        $this->assertSame(
            "queue_role:{$queue->name}",
            $alert->context['auto_assign_reason'] ?? null,
        );
    }

    public function test_auto_assign_falls_back_to_site_primary_contact_when_no_queue(): void
    {
        $contact = User::factory()->create();

        $site = Site::factory()->create([
            'type' => 'house',
            'primary_contact_user_id' => $contact->id,
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'queue_id' => null,
            'site_id' => $site->id,
            'assigned_to_user_id' => null,
        ]);

        $this->service->autoAssign($alert);

        $alert->refresh();
        $this->assertSame($contact->id, $alert->assigned_to_user_id);
        $this->assertSame('site_primary_contact', $alert->context['auto_assign_reason'] ?? null);
    }

    // ──────────────────────────────────────
    // autoStartPlaybook
    // ──────────────────────────────────────

    public function test_auto_start_playbook_does_nothing_without_run(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'playbook_run_id' => null,
        ]);

        $this->service->autoStartPlaybook($alert);

        $this->assertNull($alert->fresh()->playbook_run_id);
    }

    public function test_auto_start_playbook_transitions_pending_run_to_in_progress(): void
    {
        $playbook = Playbook::create([
            'code' => 'test-playbook',
            'name' => 'Test Playbook',
            'category' => 'fleet',
            'is_active' => true,
        ]);

        PlaybookStep::create([
            'playbook_id' => $playbook->id,
            'order' => 1,
            'title' => 'Step 1',
        ]);

        $alert = ControlRoomAlert::factory()->open()->create();

        $run = PlaybookRun::create([
            'playbook_id' => $playbook->id,
            'alert_id' => $alert->id,
            'status' => 'pending',
            'total_steps' => 1,
        ]);

        $alert->update(['playbook_run_id' => $run->id]);

        $this->service->autoStartPlaybook($alert);

        $run->refresh();
        $this->assertSame('in_progress', $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNull(
            $run->started_by_user_id,
            'system-initiated runs must not be attributed to a user',
        );
    }

    public function test_auto_start_playbook_skips_runs_not_pending(): void
    {
        $playbook = Playbook::create([
            'code' => 'already-running',
            'name' => 'Already Running',
            'category' => 'fleet',
            'is_active' => true,
        ]);

        $alert = ControlRoomAlert::factory()->open()->create();

        $run = PlaybookRun::create([
            'playbook_id' => $playbook->id,
            'alert_id' => $alert->id,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(5),
            'total_steps' => 1,
        ]);

        $alert->update(['playbook_run_id' => $run->id]);
        $startedAt = $run->started_at;

        $this->service->autoStartPlaybook($alert);

        $run->refresh();
        $this->assertEquals(
            $startedAt->toIso8601String(),
            $run->started_at->toIso8601String(),
            'started_at should not change when run is already in progress',
        );
    }

    // ──────────────────────────────────────
    // onAlertEscalated — watcher addition
    // ──────────────────────────────────────

    public function test_on_alert_escalated_below_level_2_does_not_add_watchers(): void
    {
        $alert = ControlRoomAlert::factory()->open()->create([
            'escalation_level' => 1,
        ]);

        $this->service->onAlertEscalated($alert, 0);

        $this->assertSame(0, $alert->watchers()->count());
    }

    public function test_on_alert_escalated_at_level_2_adds_site_contact_and_managers_as_watchers(): void
    {
        $adminRole = Role::firstWhere('name', 'admin');
        $providerManagerRole = Role::firstWhere('name', 'provider_manager');

        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $providerManager = User::factory()->create();
        $providerManager->roles()->attach($providerManagerRole);

        $contact = User::factory()->create();
        $site = Site::factory()->create([
            'type' => 'house',
            'primary_contact_user_id' => $contact->id,
        ]);

        $alert = ControlRoomAlert::factory()->open()->create([
            'site_id' => $site->id,
            'escalation_level' => 2,
        ]);

        $this->service->onAlertEscalated($alert, 1);

        $watcherIds = $alert->watchers()->pluck('user_id')->all();

        $this->assertContains($contact->id, $watcherIds, 'site primary contact should be added');
        $this->assertContains($admin->id, $watcherIds, 'admin should be added');
        $this->assertContains($providerManager->id, $watcherIds, 'provider_manager should be added');
    }

    public function test_on_alert_escalated_does_not_duplicate_existing_watchers(): void
    {
        $adminRole = Role::firstWhere('name', 'admin');
        $admin = User::factory()->create();
        $admin->roles()->attach($adminRole);

        $alert = ControlRoomAlert::factory()->open()->create([
            'escalation_level' => 2,
        ]);

        $alert->watchers()->create([
            'user_id' => $admin->id,
            'added_by_user_id' => null,
        ]);

        $this->service->onAlertEscalated($alert, 1);

        $this->assertSame(
            1,
            $alert->watchers()->where('user_id', $admin->id)->count(),
            'existing watcher should not be duplicated',
        );
    }
}
