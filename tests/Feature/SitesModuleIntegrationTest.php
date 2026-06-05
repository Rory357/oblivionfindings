<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Site;
use App\Models\AuditLog;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistResponse;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use App\Models\SiteCredential;
use App\Models\SiteInspectionSchedule;
use App\Models\SiteVendor;
use App\Models\User;
use App\Services\Sites\SiteChecklistScheduler;
use App\Services\Sites\SiteCredentialEncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SitesModuleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);

        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
        $this->admin->roles()->sync([$adminRole->id]);
    }

    public function test_sites_global_calendar_route_renders(): void
    {
        Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/calendar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calendar/global')
                ->has('sites')
                ->has('events')
                ->has('eventTypes')
            );
    }

    public function test_sites_global_inspections_route_renders(): void
    {
        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
        ]);

        SiteInspectionSchedule::create([
            'site_id' => $site->id,
            'inspection_type' => 'fire_safety',
            'title' => 'Fire Exit Audit',
            'frequency' => 'monthly',
            'first_due_date' => now()->toDateString(),
            'next_due_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/sites/inspections')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/inspections/global')
                ->has('schedules')
                ->has('records')
                ->has('sites')
            );
    }

    public function test_sites_global_vendors_credentials_route_renders(): void
    {
        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
        ]);

        SiteVendor::create([
            'site_id' => $site->id,
            'service_type' => 'maintenance',
            'company_name' => 'Acme Maintenance',
            'preferred_contact_method' => 'phone',
            'is_active' => true,
        ]);

        $encrypted = app(SiteCredentialEncryptionService::class)->encrypt('Secret123');
        SiteCredential::create([
            'site_id' => $site->id,
            'label' => 'Door Code',
            'credential_type' => 'pin',
            'encrypted_value' => $encrypted['value'],
            'requires_reauth' => false,
        ]);

        // Legacy URL now redirects to the canonical /vendors location.
        $this->actingAs($this->admin)
            ->get('/sites/vendors-credentials')
            ->assertRedirect('/vendors');

        $this->actingAs($this->admin)
            ->get('/vendors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/vendors-credentials/global')
                ->has('vendors')
                ->has('credentials')
                ->has('sites')
            );
    }

    public function test_checklist_run_detail_page_contract_is_valid(): void
    {
        $site = Site::factory()->create(['type' => 'house']);

        $template = SiteChecklistTemplate::create([
            'key' => 'house_quality_' . uniqid(),
            'name' => 'House Quality Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'monthly',
            'is_active' => true,
        ]);

        $item = SiteChecklistTemplateItem::create([
            'template_id' => $template->id,
            'sort_order' => 1,
            'question' => 'Are exits clear?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'failure_creates_hazard' => true,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'scheduled',
        ]);

        SiteChecklistResponse::create([
            'run_id' => $run->id,
            'template_item_id' => $item->id,
            'response_value' => 'yes',
            'is_failed' => false,
        ]);

        $this->actingAs($this->admin)
            ->get("/checklists/runs/{$run->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sites/checklists/runs/[id]')
                ->where('site.id', $site->id)
                ->where('template.id', $template->id)
                ->has('items', 1)
                ->has('responses', 1)
            );
    }

    public function test_checklist_run_can_complete_without_recursive_observer_updates(): void
    {
        $site = Site::factory()->create(['type' => 'house']);

        $template = SiteChecklistTemplate::create([
            'tenant_id' => $site->tenant_id,
            'key' => 'house_quality_' . uniqid(),
            'name' => 'House Quality Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'monthly',
            'is_active' => true,
        ]);

        $passItem = SiteChecklistTemplateItem::create([
            'tenant_id' => $site->tenant_id,
            'template_id' => $template->id,
            'sort_order' => 1,
            'question' => 'Entry locks working?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'failure_creates_hazard' => false,
        ]);

        $failItem = SiteChecklistTemplateItem::create([
            'tenant_id' => $site->tenant_id,
            'template_id' => $template->id,
            'sort_order' => 2,
            'question' => 'Fire extinguishers tagged?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'failure_creates_hazard' => true,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'tenant_id' => $site->tenant_id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $run = SiteChecklistRun::create([
            'tenant_id' => $site->tenant_id,
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
        ]);

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => [
                    [
                        'template_item_id' => $passItem->id,
                        'response_value' => 'yes',
                        'is_failed' => false,
                    ],
                    [
                        'template_item_id' => $failItem->id,
                        'response_value' => 'no',
                        'is_failed' => true,
                        'create_hazard' => false,
                    ],
                ],
                'overall_notes' => 'Completed during test.',
                'signature_name' => $this->admin->name,
            ])
            // completeRun now redirects back() (modal-coherent) instead of a
            // hard-coded per-site index route.
            ->assertRedirect();

        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->items_passed);
        $this->assertSame(1, $run->items_failed);
        $this->assertSame('100.00', (string) $run->completion_percentage);
        $this->assertDatabaseCount('site_checklist_responses', 2);
        $this->assertSame(1, AuditLog::where('action', 'checklist.completed')
            ->where('auditable_type', $run->getMorphClass())
            ->where('auditable_id', $run->id)
            ->count());
    }

    public function test_create_run_reuses_existing_unfinished_run(): void
    {
        $site = Site::factory()->create(['type' => 'house']);

        $template = SiteChecklistTemplate::create([
            'tenant_id' => $site->tenant_id,
            'key' => 'house_quality_' . uniqid(),
            'name' => 'House Quality Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'tenant_id' => $site->tenant_id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'start_date' => now()->subDay()->toDateString(),
            'is_active' => true,
        ]);

        $run = SiteChecklistRun::create([
            'tenant_id' => $site->tenant_id,
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->subDay()->toDateString(),
            'status' => 'overdue',
        ]);

        $this->actingAs($this->admin)
            ->post("/sites/{$site->id}/checklists/assignments/{$assignment->id}/run")
            ->assertRedirect(route('sites.checklists.showRun', $run->id));

        $run->refresh();

        $this->assertSame('in_progress', $run->status);
        $this->assertSame(1, SiteChecklistRun::where('assignment_id', $assignment->id)->count());
    }

    public function test_scheduler_waits_for_existing_run_to_complete_before_creating_next(): void
    {
        $site = Site::factory()->create(['type' => 'house']);

        $template = SiteChecklistTemplate::create([
            'tenant_id' => $site->tenant_id,
            'key' => 'house_quality_' . uniqid(),
            'name' => 'House Quality Checklist',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);

        $assignment = SiteChecklistAssignment::create([
            'tenant_id' => $site->tenant_id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'start_date' => now()->toDateString(),
            'is_active' => true,
        ]);

        $run = SiteChecklistRun::create([
            'tenant_id' => $site->tenant_id,
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->toDateString(),
            'status' => 'in_progress',
        ]);

        $scheduler = app(SiteChecklistScheduler::class);

        $this->assertSame(0, $scheduler->generateRunsForAssignment($assignment, now()->addDays(7)));
        $this->assertSame(1, SiteChecklistRun::where('assignment_id', $assignment->id)->count());

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $this->admin->id,
        ]);

        $this->assertSame(1, $scheduler->generateRunsForAssignment($assignment->fresh(), now()->addDays(7)));
        $this->assertSame(2, SiteChecklistRun::where('assignment_id', $assignment->id)->count());
    }

    public function test_credential_reveal_and_copy_are_audited(): void
    {
        $site = Site::factory()->create(['type' => 'head_office']);

        $encrypted = app(SiteCredentialEncryptionService::class)->encrypt('TopSecretValue');

        $credential = SiteCredential::create([
            'site_id' => $site->id,
            'label' => 'Alarm PIN',
            'credential_type' => 'pin',
            'encrypted_value' => $encrypted['value'],
            'requires_reauth' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$site->id}/credentials/{$credential->id}/reveal")
            ->assertOk()
            ->assertJson(['value' => 'TopSecretValue']);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$site->id}/credentials/{$credential->id}/copy")
            ->assertOk();

        $this->assertDatabaseHas('site_credential_audit_logs', [
            'credential_id' => $credential->id,
            'action' => 'reveal',
        ]);

        $this->assertDatabaseHas('site_credential_audit_logs', [
            'credential_id' => $credential->id,
            'action' => 'copy',
        ]);
    }

    public function test_onboarding_contacts_and_assets_steps_persist_records(): void
    {
        $site = Site::factory()->create(['type' => 'facility']);

        $this->actingAs($this->admin)
            ->postJson("/sites/{$site->id}/onboarding/step", [
                'step' => 'contacts',
                'data' => [
                    'contacts' => [
                        [
                            'type' => 'site_lead',
                            'name' => 'Jordan Lead',
                            'role' => 'Team Lead',
                            'phone' => '0210000000',
                            'email' => 'lead@example.test',
                            'is_primary' => true,
                        ],
                    ],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->admin)
            ->postJson("/sites/{$site->id}/onboarding/step", [
                'step' => 'assets',
                'data' => [
                    'assets' => [
                        [
                            'name' => 'Defibrillator',
                            'category' => 'safety equipment',
                            'quantity' => 2,
                        ],
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('site_contacts', [
            'site_id' => $site->id,
            'name' => 'Jordan Lead',
        ]);

        $this->assertDatabaseHas('assets', [
            'site_id' => $site->id,
            'name' => 'Defibrillator (1)',
        ]);

        $this->assertDatabaseHas('assets', [
            'site_id' => $site->id,
            'name' => 'Defibrillator (2)',
        ]);
    }

    public function test_sites_report_export_returns_csv(): void
    {
        Site::factory()->create(['type' => 'house']);

        $response = $this->actingAs($this->admin)
            ->get('/sites/reports/export?type=houses&format=csv');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));
        $response->assertSee('site_name', false);
    }
}
