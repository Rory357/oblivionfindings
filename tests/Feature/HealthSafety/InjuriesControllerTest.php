<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\ClientIncident;
use App\Models\ReturnToWorkPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Injuries & RTW redesign — controller + observer coverage (the module had zero
 * HTTP tests before the rebuild). Hero/tabs/detail/can, store with derived ACC +
 * incident link, WorkSafe NotifiableIncident seam, lifecycle transitions, RTW /
 * capacity / modified-duty sub-modals, premium attachments (IDOR guard).
 */
class InjuriesControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
        $this->site = Site::factory()->create();
    }

    private function injury(array $overrides = []): WorkplaceInjury
    {
        return WorkplaceInjury::factory()->create(array_merge([
            'site_id' => $this->site->id,
            'status' => 'reported',
        ], $overrides));
    }

    public function test_index_renders_hero_tabcounts_and_can(): void
    {
        $this->injury(['status' => 'reported']);
        $this->injury(['status' => 'under_treatment']);

        $this->actingAs($this->admin)
            ->get('/health-safety/injuries')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/injuries/index')
                ->where('tab', 'all')
                ->where('tabCounts.all', 2)
                ->where('tabCounts.reported', 1)
                ->where('can.manage', true)
                ->has('hero.live')
                ->has('hero.attention')
                ->has('hero.badges')
                ->has('staff')
                ->has('incidents')
                ->where('detail', null));
    }

    public function test_detail_loads_only_with_injury_param(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->get('/health-safety/injuries?injury='.$inj->id)
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.id', $inj->id)
                ->where('detail.reference', 'WI-'.str_pad((string) $inj->id, 4, '0', STR_PAD_LEFT))
                ->has('detail.rtw_plans')
                ->has('detail.attachments')
                ->where('detail.can.manage', true));
    }

    public function test_store_creates_injury_with_derived_acc_and_incident_link(): void
    {
        $worker = User::factory()->create();
        $incident = ClientIncident::factory()->create();

        $this->actingAs($this->admin)
            ->from('/health-safety/injuries')
            ->post('/health-safety/injuries', [
                'user_id' => $worker->id,
                'site_id' => $this->site->id,
                'related_incident_id' => $incident->id,
                'injury_date' => now()->toDateString(),
                'injury_type' => 'manual_handling',
                'body_part_affected' => 'Lower back',
                'severity' => 'moderate',
                'description' => 'Strained back during a transfer.',
                'medical_treatment_type' => 'gp_visit',
                'acc_claim_number' => '26/123456',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $inj = WorkplaceInjury::latest('id')->first();
        $this->assertSame('reported', $inj->status);
        $this->assertSame(0, (int) $inj->lost_time_days);
        $this->assertTrue((bool) $inj->acc_claim_lodged, 'ACC lodged should derive from a captured claim number');
        $this->assertSame($incident->id, $inj->related_incident_id);
        $this->assertEquals($inj->id, session('created_injury_id'));
    }

    public function test_store_worksafe_notifiable_creates_notifiable_incident(): void
    {
        $worker = User::factory()->create();

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries', [
                'user_id' => $worker->id,
                'site_id' => $this->site->id,
                'injury_date' => now()->toDateString(),
                'injury_type' => 'fracture',
                'body_part_affected' => 'Right wrist',
                'severity' => 'serious',
                'description' => 'Fall from a ladder; wrist fracture requiring hospitalisation.',
                'medical_treatment_type' => 'hospitalisation',
                'worksafe_notifiable' => true,
            ])
            ->assertSessionHasNoErrors();

        $inj = WorkplaceInjury::latest('id')->first();
        $notifiable = NotifiableIncident::where('workplace_injury_id', $inj->id)->first();

        $this->assertNotNull($notifiable, 'A worksafe-notifiable injury must create a NotifiableIncident (seam 4)');
        $this->assertSame('worksafe', $notifiable->notification_authority);
        $this->assertSame('pending', $notifiable->status);
    }

    public function test_update_edits_injury_fields(): void
    {
        $inj = $this->injury(['lost_time_days' => 0]);

        $this->actingAs($this->admin)
            ->put('/health-safety/injuries/'.$inj->id, [
                'lost_time_days' => 5,
                'body_part_affected' => 'Left shoulder',
            ])
            ->assertSessionHasNoErrors();

        $inj->refresh();
        $this->assertSame(5, (int) $inj->lost_time_days);
        $this->assertSame('Left shoulder', $inj->body_part_affected);
    }

    public function test_transition_status_advances_and_sets_return_date(): void
    {
        $inj = $this->injury(['status' => 'under_treatment', 'actual_return_date' => null]);

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/status', ['status' => 'recovered'])
            ->assertSessionHasNoErrors();

        $inj->refresh();
        $this->assertSame('recovered', $inj->status);
        $this->assertNotNull($inj->actual_return_date, 'Recovered should stamp actual_return_date');
    }

    public function test_transition_status_rejects_illegal_jump(): void
    {
        $inj = $this->injury(['status' => 'reported']);

        $this->actingAs($this->admin)
            ->from('/health-safety/injuries')
            ->post('/health-safety/injuries/'.$inj->id.'/status', ['status' => 'recovered'])
            ->assertSessionHas('error');

        $this->assertSame('reported', $inj->fresh()->status);
    }

    public function test_store_rtw_plan(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/rtw-plans', [
                'plan_start_date' => now()->toDateString(),
                'goals' => ['Return to full duties'],
                'stages' => [[
                    'name' => 'Graduated return',
                    'start_date' => now()->toDateString(),
                    'hours_per_week' => 20,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $plan = ReturnToWorkPlan::where('workplace_injury_id', $inj->id)->first();
        $this->assertNotNull($plan);
        $this->assertSame('active', $plan->status);
        $this->assertSame($inj->user_id, $plan->worker_id);
    }

    public function test_store_capacity_assessment(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/capacity-assessments', [
                'assessment_date' => now()->toDateString(),
                'assessor_type' => 'gp',
                'capacity_status' => 'fit_for_modified_duties',
                'restrictions' => 'No lifting over 10 kg.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_capacity_assessments', [
            'workplace_injury_id' => $inj->id,
            'capacity_status' => 'fit_for_modified_duties',
        ]);
    }

    public function test_store_modified_duty_keyed_by_plan(): void
    {
        $inj = $this->injury();
        $plan = ReturnToWorkPlan::factory()->create(['workplace_injury_id' => $inj->id, 'worker_id' => $inj->user_id]);

        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/rtw-plans/'.$plan->id.'/modified-duties', [
                'start_date' => now()->toDateString(),
                'modified_duties_description' => 'Desk duties only.',
                'hours_per_day' => 6,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('modified_duties', [
            'return_to_work_plan_id' => $plan->id,
            'modified_duties_description' => 'Desk duties only.',
        ]);
    }

    public function test_upload_download_destroy_attachment_with_idor_guard(): void
    {
        Storage::fake('public');
        $inj = $this->injury();
        $other = $this->injury();

        // Upload
        $this->actingAs($this->admin)
            ->post('/health-safety/injuries/'.$inj->id.'/attachments', [
                'file' => UploadedFile::fake()->create('medical-cert.pdf', 200, 'application/pdf'),
                'kind' => 'medical_cert',
            ])
            ->assertSessionHasNoErrors();

        $att = WorkplaceInjuryAttachment::where('workplace_injury_id', $inj->id)->first();
        $this->assertNotNull($att);
        $this->assertSame('medical_cert', $att->kind);
        Storage::disk('public')->assertExists($att->path);

        // IDOR guard: the attachment belongs to $inj, not $other → 404 under $other.
        $this->actingAs($this->admin)
            ->get('/health-safety/injuries/'.$other->id.'/attachments/'.$att->id.'/download')
            ->assertNotFound();

        // Correct parent downloads fine.
        $this->actingAs($this->admin)
            ->get('/health-safety/injuries/'.$inj->id.'/attachments/'.$att->id.'/download')
            ->assertOk();

        // Destroy
        $this->actingAs($this->admin)
            ->delete('/health-safety/injuries/'.$inj->id.'/attachments/'.$att->id)
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted('workplace_injury_attachments', ['id' => $att->id]);
    }

    public function test_client_incident_reverse_relation(): void
    {
        $incident = ClientIncident::factory()->create();
        $inj = $this->injury(['related_incident_id' => $incident->id]);

        $this->assertTrue($incident->workplaceInjuries()->where('id', $inj->id)->exists());
    }

    public function test_export_streams_filtered_csv(): void
    {
        $inj = $this->injury(['injury_type' => 'fracture']);

        $res = $this->actingAs($this->admin)->get('/health-safety/injuries/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('WI-'.str_pad((string) $inj->id, 4, '0', STR_PAD_LEFT), $res->streamedContent());
    }

    public function test_show_redirects_to_register_modal(): void
    {
        $inj = $this->injury();

        $this->actingAs($this->admin)
            ->get('/health-safety/injuries/'.$inj->id)
            ->assertRedirect('/health-safety/injuries?injury='.$inj->id);
    }
}
