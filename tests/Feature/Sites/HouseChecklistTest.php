<?php

namespace Tests\Feature\Sites;

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $supportWorker;
    protected Site $houseSite;
    protected Site $officeSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->houseSite = Site::factory()->create(['type' => 'house']);
        $this->officeSite = Site::factory()->create(['type' => 'head_office']);
    }

    public function test_house_checklists_index_requires_authentication(): void
    {
        $this->get("/sites/{$this->houseSite->id}/house-checklists")->assertRedirect('/login');
    }

    public function test_admin_can_view_house_checklists(): void
    {
        $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}/house-checklists")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/checklists/house-index')
                ->has('site')
                ->has('templates')
                ->has('runs')
            );
    }

    public function test_non_house_site_returns_404(): void
    {
        $this->actingAs($this->admin)
            ->get("/sites/{$this->officeSite->id}/house-checklists")
            ->assertNotFound();
    }

    public function test_admin_can_create_site_specific_template(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/house-checklists/templates", [
                'name' => 'Morning House Check',
                'description' => 'Daily morning walkthrough',
                'frequency' => 'daily',
                'items' => [
                    ['question' => 'Kitchen clean and tidy?', 'response_type' => 'yes_no', 'is_required' => true],
                    ['question' => 'All doors locked overnight?', 'response_type' => 'yes_no', 'is_required' => true],
                    ['question' => 'Temperature reading', 'response_type' => 'numeric', 'is_required' => false],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('site_checklist_templates', [
            'site_id' => $this->houseSite->id,
            'name' => 'Morning House Check',
            'frequency' => 'daily',
        ]);

        $template = SiteChecklistTemplate::where('site_id', $this->houseSite->id)->first();
        $this->assertEquals(3, $template->items()->count());
    }

    public function test_admin_can_start_checklist_run(): void
    {
        $template = SiteChecklistTemplate::create([
            'site_id' => $this->houseSite->id,
            'key' => 'test_evening_check',
            'name' => 'Evening Check',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/house-checklists/{$template->id}/start")
            ->assertRedirect();

        $this->assertDatabaseHas('site_checklist_runs', [
            'template_id' => $template->id,
            'site_id' => $this->houseSite->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_admin_can_complete_checklist_run(): void
    {
        $template = SiteChecklistTemplate::create([
            'site_id' => $this->houseSite->id,
            'key' => 'test_night_check',
            'name' => 'Night Check',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);

        $item1 = $template->items()->create([
            'question' => 'Doors locked?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'sort_order' => 0,
        ]);

        $item2 = $template->items()->create([
            'question' => 'Lights off?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'sort_order' => 1,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'site_id' => $this->houseSite->id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'template_id' => $template->id,
            'site_id' => $this->houseSite->id,
            'scheduled_date' => now()->toDateString(),
            'started_at' => now(),
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$this->houseSite->id}/house-checklists/runs/{$run->id}/complete", [
                'responses' => [
                    ['template_item_id' => $item1->id, 'response_value' => 'yes'],
                    ['template_item_id' => $item2->id, 'response_value' => 'yes', 'notes' => 'All good'],
                ],
            ])
            ->assertRedirect();

        $run->refresh();
        $this->assertEquals('completed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertEquals(2, $run->responses()->count());
    }

    public function test_non_house_site_cannot_create_template(): void
    {
        $this->actingAs($this->admin)
            ->post("/sites/{$this->officeSite->id}/house-checklists/templates", [
                'name' => 'Should fail',
                'frequency' => 'daily',
                'items' => [
                    ['question' => 'Test', 'response_type' => 'yes_no', 'is_required' => true],
                ],
            ])
            ->assertNotFound();
    }

    public function test_global_templates_appear_for_house_site(): void
    {
        // Create a global template for house type
        SiteChecklistTemplate::create([
            'key' => 'global_house_check',
            'name' => 'Global House Check',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);

        // Create a global template for facility type (should NOT appear)
        SiteChecklistTemplate::create([
            'key' => 'facility_check',
            'name' => 'Facility Check',
            'applicable_to_type' => 'facility',
            'frequency' => 'weekly',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get("/sites/{$this->houseSite->id}/house-checklists")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('sites/checklists/house-index')
                ->has('templates', 1)
            );
    }
}
