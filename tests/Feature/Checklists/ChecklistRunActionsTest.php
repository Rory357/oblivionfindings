<?php

namespace Tests\Feature\Checklists;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use App\Models\SiteDamage;
use App\Models\SiteHazard;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Schedule right-click actions (reschedule / reassign / skip) and the
 * hazard + damage follow-ups a flagged failed run item now raises.
 */
class ChecklistRunActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $supportWorker;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->site = Site::factory()->create(['type' => 'house']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->supportWorker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_reschedule_a_run(): void
    {
        $run = $this->makeRun();
        $newDate = now()->addWeek()->toDateString();

        $this->actingAs($this->admin)
            ->patch("/checklists/runs/{$run->id}/schedule", ['scheduled_date' => $newDate])
            ->assertRedirect();

        $this->assertSame($newDate, $run->fresh()->scheduled_date->toDateString());
    }

    public function test_reschedule_requires_schedule_permission(): void
    {
        $run = $this->makeRun();

        $this->actingAs($this->supportWorker)
            ->patch("/checklists/runs/{$run->id}/schedule", ['scheduled_date' => now()->toDateString()])
            ->assertForbidden();
    }

    public function test_reassign_requires_schedule_permission(): void
    {
        $run = $this->makeRun();
        $assignee = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($this->supportWorker)
            ->patch("/checklists/runs/{$run->id}/assign", ['assigned_to_user_id' => $assignee->id])
            ->assertForbidden();

        $this->assertNull($run->fresh()->assigned_to_user_id);
    }

    public function test_admin_can_reassign_a_run_and_clear_it(): void
    {
        $run = $this->makeRun();

        $this->actingAs($this->admin)
            ->patch("/checklists/runs/{$run->id}/assign", ['assigned_to_user_id' => $this->supportWorker->id])
            ->assertRedirect();
        $this->assertSame($this->supportWorker->id, $run->fresh()->assigned_to_user_id);

        $this->actingAs($this->admin)
            ->patch("/checklists/runs/{$run->id}/assign", ['assigned_to_user_id' => null])
            ->assertRedirect();
        $this->assertNull($run->fresh()->assigned_to_user_id);
    }

    public function test_admin_can_skip_a_run(): void
    {
        $run = $this->makeRun();

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/skip")
            ->assertRedirect();

        $this->assertSame('skipped', $run->fresh()->status);

        $this->actingAs($this->admin)
            ->get("/sites/{$this->site->id}/checklists")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('skippedRuns', 1)
                ->where('skippedRuns.0.id', $run->id));
    }

    public function test_skip_remains_manager_only_for_support_worker(): void
    {
        $run = $this->makeRun();

        $this->actingAs($this->supportWorker)
            ->post("/checklists/runs/{$run->id}/skip")
            ->assertForbidden();
    }

    public function test_admin_can_restore_a_skipped_run_to_scheduled(): void
    {
        $run = $this->makeRun();
        $run->update(['status' => 'skipped']);

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/restore")
            ->assertRedirect();

        $this->assertSame('scheduled', $run->fresh()->status);

        $this->actingAs($this->admin)
            ->get("/sites/{$this->site->id}/checklists")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('skippedRuns', 0));
    }

    public function test_restore_requires_schedule_permission(): void
    {
        $run = $this->makeRun();
        $run->update(['status' => 'skipped']);

        $this->actingAs($this->supportWorker)
            ->post("/checklists/runs/{$run->id}/restore")
            ->assertForbidden();

        $this->assertSame('skipped', $run->fresh()->status);
    }

    public function test_support_worker_can_save_and_complete_a_site_run(): void
    {
        [$run, $hazardItem, $damageItem] = $this->makeRunWithFlaggedItems();
        $payload = [
            ['template_item_id' => $hazardItem->id, 'response_value' => 'yes', 'is_failed' => false],
            ['template_item_id' => $damageItem->id, 'response_value' => 'pass', 'is_failed' => false],
        ];

        $this->actingAs($this->supportWorker)
            ->post("/checklists/runs/{$run->id}/responses", ['responses' => $payload])
            ->assertRedirect();

        $this->assertSame('in_progress', $run->fresh()->status);

        $this->actingAs($this->supportWorker)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $payload,
                'signature_name' => 'Support Worker',
            ])
            ->assertRedirect();

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame($this->supportWorker->id, $run->completed_by_user_id);
    }

    public function test_completion_requires_run_permission(): void
    {
        [$run, $hazardItem] = $this->makeRunWithFlaggedItems();
        $viewerWithoutChecklistRun = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($viewerWithoutChecklistRun)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => [
                    ['template_item_id' => $hazardItem->id, 'response_value' => 'yes', 'is_failed' => false],
                ],
                'signature_name' => 'No Permission',
            ])
            ->assertForbidden();

        $this->assertSame('in_progress', $run->fresh()->status);
    }

    public function test_failed_flagged_items_raise_hazard_and_damage(): void
    {
        [$run, $hazardItem, $damageItem] = $this->makeRunWithFlaggedItems();

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => [
                    ['template_item_id' => $hazardItem->id, 'response_value' => 'no', 'is_failed' => true, 'create_hazard' => true],
                    ['template_item_id' => $damageItem->id, 'response_value' => 'fail', 'is_failed' => true, 'create_damage' => true],
                ],
                'signature_name' => 'Admin User',
            ])
            ->assertRedirect();

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseHas('site_hazards', [
            'site_id' => $this->site->id,
            'linked_checklist_run_id' => $run->id,
        ]);
        $this->assertDatabaseHas('site_damages', [
            'site_id' => $this->site->id,
            'checklist_run_id' => $run->id,
        ]);
    }

    public function test_follow_ups_are_idempotent_across_save_then_complete(): void
    {
        [$run, $hazardItem, $damageItem] = $this->makeRunWithFlaggedItems();
        $payload = [
            ['template_item_id' => $hazardItem->id, 'response_value' => 'no', 'is_failed' => true, 'create_hazard' => true],
            ['template_item_id' => $damageItem->id, 'response_value' => 'pass', 'is_failed' => false],
        ];

        // Saving raises the hazard; completing again must not duplicate it.
        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/responses", ['responses' => $payload])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/complete", ['responses' => $payload, 'signature_name' => 'Admin User'])
            ->assertRedirect();

        $this->assertSame(1, SiteHazard::where('linked_checklist_run_id', $run->id)->count());
    }

    public function test_passing_a_flagged_item_raises_nothing(): void
    {
        [$run, $hazardItem, $damageItem] = $this->makeRunWithFlaggedItems();

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => [
                    ['template_item_id' => $hazardItem->id, 'response_value' => 'yes', 'is_failed' => false],
                    ['template_item_id' => $damageItem->id, 'response_value' => 'pass', 'is_failed' => false],
                ],
                'signature_name' => 'Admin User',
            ])
            ->assertRedirect();

        $this->assertSame(0, SiteHazard::where('linked_checklist_run_id', $run->id)->count());
        $this->assertSame(0, SiteDamage::where('checklist_run_id', $run->id)->count());
    }

    private function makeRun(): SiteChecklistRun
    {
        $template = SiteChecklistTemplate::create([
            'key' => 'actions_test_'.uniqid(),
            'name' => 'Actions Test',
            'applicable_to_type' => 'house',
            'frequency' => 'weekly',
            'is_active' => true,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'frequency' => 'weekly',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        return SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);
    }

    /** @return array{0: SiteChecklistRun, 1: SiteChecklistTemplateItem, 2: SiteChecklistTemplateItem} */
    private function makeRunWithFlaggedItems(): array
    {
        $template = SiteChecklistTemplate::create([
            'key' => 'flagged_test_'.uniqid(),
            'name' => 'Flagged Test',
            'applicable_to_type' => 'house',
            'frequency' => 'weekly',
            'is_active' => true,
        ]);

        $hazardItem = $template->items()->create([
            'question' => 'Fire exits clear?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'sort_order' => 0,
            'failure_creates_hazard' => true,
        ]);

        $damageItem = $template->items()->create([
            'question' => 'Wall condition OK?',
            'response_type' => 'pass_fail',
            'is_required' => true,
            'sort_order' => 1,
            'failure_creates_damage' => true,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'frequency' => 'weekly',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return [$run, $hazardItem, $damageItem];
    }
}
