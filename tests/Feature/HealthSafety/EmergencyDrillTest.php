<?php

namespace Tests\Feature\HealthSafety;

use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillAttachment;
use App\Models\EmergencyDrillFinding;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Observers\EmergencyDrillObserver;
use App\Services\HealthSafety\DrillComplianceService;
use App\Services\Sites\Calendar\Providers\DrillObligationProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Emergency Drills redesign — register payload, lifecycle endpoints (start/complete/
 * cancel/resolve), the addFinding finding_type fix, premium evidence upload, the
 * observer convergence, the compliance single-source-of-truth and the calendar provider.
 */
class EmergencyDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    protected function officer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    protected function supportWorker(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    /* ---- Register ---- */

    public function test_index_renders_gold_standard_payload(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        EmergencyDrill::factory()->create(['site_id' => $site->id]);

        $this->actingAs($this->officer())
            ->get('/health-safety/drills')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('health-safety/drills/index')
                ->has('drills.data', 1)
                ->has('tabCounts.all')
                ->has('hero.live.scheduled')
                ->has('hero.attention.sites_overdue')
                ->has('hero.badges.sites_drilled_pct')
                ->has('sites')
                ->has('staff')
                ->where('can.manage', true));
    }

    public function test_index_returns_detail_payload_on_drill_query(): void
    {
        $drill = EmergencyDrill::factory()->create();

        $this->actingAs($this->officer())
            ->get('/health-safety/drills?drill='.$drill->id)
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->where('detail.id', $drill->id)
                ->where('detail.reference', 'DR-'.$drill->id)
                ->has('detail.participants')
                ->has('detail.findings')
                ->has('detail.attachments')
                ->has('detail.timeline'));
    }

    public function test_requires_permission(): void
    {
        $this->actingAs($this->supportWorker())->get('/health-safety/drills')->assertForbidden();
    }

    /* ---- Schedule ---- */

    public function test_store_schedules_drill_and_seeds_roll_call(): void
    {
        $site = Site::factory()->create();
        $coordinator = User::factory()->create();
        $warden = User::factory()->create();

        $this->actingAs($this->officer())
            ->post('/health-safety/drills', [
                'site_id' => $site->id,
                'drill_type' => 'fire_evacuation',
                'title' => 'Q2 fire evacuation',
                'scheduled_at' => now()->addWeek()->toDateTimeString(),
                'conducted_by' => $coordinator->id,
                'warden_ids' => [$warden->id],
                'is_unannounced' => true,
            ])
            ->assertRedirect();

        $drill = EmergencyDrill::firstWhere('title', 'Q2 fire evacuation');
        $this->assertNotNull($drill);
        $this->assertSame('scheduled', $drill->status);
        $this->assertTrue((bool) $drill->is_unannounced);
        $this->assertDatabaseHas('emergency_drill_participants', ['emergency_drill_id' => $drill->id, 'user_id' => $coordinator->id, 'role' => 'coordinator']);
        $this->assertDatabaseHas('emergency_drill_participants', ['emergency_drill_id' => $drill->id, 'user_id' => $warden->id, 'role' => 'warden']);
    }

    public function test_create_redirects_to_register(): void
    {
        $this->actingAs($this->officer())
            ->get('/health-safety/drills/create')
            ->assertRedirect('/health-safety/drills?schedule=1');
    }

    /* ---- Findings (the finding_type bug fix) ---- */

    public function test_add_finding_requires_and_persists_finding_type(): void
    {
        $drill = EmergencyDrill::factory()->create();
        $actor = $this->officer();

        // Missing finding_type → validation error (previously a 500 / NOT NULL violation).
        $this->actingAs($actor)
            ->post("/health-safety/drills/{$drill->id}/findings", ['description' => 'x', 'severity' => 'high'])
            ->assertSessionHasErrors('finding_type');

        $this->actingAs($actor)
            ->post("/health-safety/drills/{$drill->id}/findings", [
                'finding_type' => 'non_conformance',
                'description' => 'Room 4 not swept',
                'severity' => 'high',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('emergency_drill_findings', [
            'emergency_drill_id' => $drill->id,
            'finding_type' => 'non_conformance',
            'status' => 'open',
        ]);
    }

    public function test_resolve_finding_marks_resolved(): void
    {
        $drill = EmergencyDrill::factory()->create();
        $finding = EmergencyDrillFinding::factory()->create(['emergency_drill_id' => $drill->id, 'status' => 'open']);

        $this->actingAs($this->officer())
            ->post("/health-safety/drills/{$drill->id}/findings/{$finding->id}/resolve", ['resolution_notes' => 'Fixed'])
            ->assertRedirect();

        $finding->refresh();
        $this->assertSame('resolved', $finding->status);
        $this->assertNotNull($finding->resolved_at);
        $this->assertSame('Fixed', $finding->resolution_notes);
    }

    /* ---- Lifecycle ---- */

    public function test_start_transitions_to_in_progress(): void
    {
        $drill = EmergencyDrill::factory()->create(['status' => 'scheduled']);

        $this->actingAs($this->officer())->post("/health-safety/drills/{$drill->id}/start")->assertRedirect();

        $drill->refresh();
        $this->assertSame('in_progress', $drill->status);
        $this->assertNotNull($drill->started_at);
    }

    public function test_complete_endpoint_records_completion(): void
    {
        $drill = EmergencyDrill::factory()->inProgress()->create();

        $this->actingAs($this->officer())
            ->post("/health-safety/drills/{$drill->id}/complete", [
                'completed_at' => now()->toDateTimeString(),
                'outcome' => 'passed_actions',
                'duration_minutes' => 12,
                'evacuation_time_seconds' => 180,
                'roll_call_completed' => true,
                'all_areas_checked' => false,
            ])
            ->assertRedirect();

        $drill->refresh();
        $this->assertSame('completed', $drill->status);
        $this->assertSame('passed_actions', $drill->outcome);
        $this->assertSame(12, $drill->duration_minutes);
        $this->assertSame(180, $drill->evacuation_time_seconds);
        $this->assertTrue((bool) $drill->roll_call_completed);
        $this->assertFalse((bool) $drill->all_areas_checked);
        $this->assertNotNull($drill->completed_at);
    }

    public function test_observer_raises_safety_event_for_failing_drill(): void
    {
        // EmergencyDrillObserver is ShouldHandleEventsAfterCommit — that afterCommit
        // hook never fires under RefreshDatabase's open transaction, so exercise the
        // observer's decision + recordEvent convergence directly after a real change.
        $drill = EmergencyDrill::factory()->inProgress()->create();
        $drill->update(['status' => 'completed', 'outcome' => 'failed', 'completed_at' => now()]);

        app(EmergencyDrillObserver::class)->updated($drill);

        $this->assertTrue(
            HsEvent::where('source_type', EmergencyDrill::class)->where('source_id', $drill->id)->exists(),
            'A failed drill should raise a drill_failure HsEvent.',
        );
    }

    public function test_observer_raises_no_event_for_passing_drill(): void
    {
        $drill = EmergencyDrill::factory()->inProgress()->create();
        $drill->update(['status' => 'completed', 'outcome' => 'passed', 'completed_at' => now()]);

        app(EmergencyDrillObserver::class)->updated($drill);

        $this->assertFalse(
            HsEvent::where('source_type', EmergencyDrill::class)->where('source_id', $drill->id)->exists(),
            'A passed drill should NOT raise an HsEvent.',
        );
    }

    public function test_cancel_marks_cancelled(): void
    {
        $drill = EmergencyDrill::factory()->create(['status' => 'scheduled']);

        $this->actingAs($this->officer())
            ->post("/health-safety/drills/{$drill->id}/cancel", ['reason' => 'Site closed'])
            ->assertRedirect();

        $this->assertSame('cancelled', $drill->fresh()->status);
    }

    /* ---- Premium evidence upload ---- */

    public function test_upload_and_remove_attachment(): void
    {
        Storage::fake('private');
        $drill = EmergencyDrill::factory()->create();

        $this->actingAs($this->officer())
            ->post("/health-safety/drills/{$drill->id}/attachments", [
                'file' => UploadedFile::fake()->create('signin-sheet.pdf', 120, 'application/pdf'),
                'notes' => 'Roll-call sheet',
            ])
            ->assertRedirect();

        $attachment = EmergencyDrillAttachment::firstWhere('emergency_drill_id', $drill->id);
        $this->assertNotNull($attachment);
        $this->assertSame('private', $attachment->disk);
        Storage::disk('private')->assertExists($attachment->path);

        $this->actingAs($this->officer())
            ->delete("/health-safety/drills/{$drill->id}/attachments/{$attachment->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('emergency_drill_attachments', ['id' => $attachment->id]);
        Storage::disk('private')->assertMissing($attachment->path);
    }

    /* ---- Compliance single source of truth ---- */

    public function test_compliance_service_grades_sites(): void
    {
        $compliant = Site::factory()->create(['is_active' => true]);
        $dueSoon = Site::factory()->create(['is_active' => true]);
        $overdue = Site::factory()->create(['is_active' => true]);
        $never = Site::factory()->create(['is_active' => true]);

        EmergencyDrill::factory()->create(['site_id' => $compliant->id, 'status' => 'completed', 'completed_at' => Carbon::now()->subMonths(2)]);
        EmergencyDrill::factory()->create(['site_id' => $dueSoon->id, 'status' => 'completed', 'completed_at' => Carbon::now()->subMonths(6)->subDays(10)]);
        EmergencyDrill::factory()->create(['site_id' => $overdue->id, 'status' => 'completed', 'completed_at' => Carbon::now()->subMonths(9)]);

        $svc = app(DrillComplianceService::class);

        $this->assertSame('compliant', $svc->statusForSite($compliant->id));
        $this->assertSame('due_soon', $svc->statusForSite($dueSoon->id));
        $this->assertSame('overdue', $svc->statusForSite($overdue->id));
        $this->assertSame('overdue', $svc->statusForSite($never->id), 'A site with no completed drill defaults to overdue.');

        // 1 of 4 active sites is compliant → 25%.
        $this->assertSame(25, $svc->compliancePct());
        $this->assertSame(2, $svc->sitesOverdue());
    }

    public function test_dashboard_pct_reconciles_with_service(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        EmergencyDrill::factory()->create(['site_id' => $site->id, 'status' => 'completed', 'completed_at' => Carbon::now()->subMonth()]);

        $expected = app(DrillComplianceService::class)->compliancePct();

        $this->actingAs($this->officer())
            ->get('/health-safety')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('kpis.drill_compliance_pct', $expected));
    }

    /* ---- Calendar provider ---- */

    public function test_drill_obligation_provider_emits_scheduled_drills_in_range(): void
    {
        $site = Site::factory()->create();
        $inRange = EmergencyDrill::factory()->create(['site_id' => $site->id, 'status' => 'scheduled', 'scheduled_at' => Carbon::now()->addDays(5)]);
        EmergencyDrill::factory()->create(['site_id' => $site->id, 'status' => 'scheduled', 'scheduled_at' => Carbon::now()->addMonths(6)]); // out of range
        EmergencyDrill::factory()->completed()->create(['site_id' => $site->id]); // not scheduled

        $items = (new DrillObligationProvider)->obligations([$site->id], Carbon::now(), Carbon::now()->addMonth());

        $this->assertCount(1, $items);
        $arr = $items[0]->toArray();
        $this->assertSame('drill', $arr['source']);
        $this->assertSame('DR-'.$inRange->id, $arr['ref']);
        $this->assertSame("/health-safety/drills?drill={$inRange->id}", $arr['link']);
    }
}
