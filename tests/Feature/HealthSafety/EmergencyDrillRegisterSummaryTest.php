<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillFinding;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/**
 * G4-12 (board approved by Stephan 1 Nov 2026): the Emergency drills header counts, the one lead block (overdue planned
 * drills and overdue things to fix, oldest due date first, with how many more drills are overdue and where) and the
 * "Where it's up to" who-line. Every number is for the person's Sites only.
 */
class EmergencyDrillRegisterSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-31T01:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'name' => 'Tōtara House', 'is_active' => true]);
        $this->manager = $this->worker();
        $tasks = Mockery::mock(TaskAggregator::class);
        $tasks->shouldReceive('navigationVisibleFor')->andReturn(true);
        $tasks->shouldReceive('navigationBadgeFor')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('navigationBadgeInContext')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $this->app->instance(TaskAggregator::class, $tasks);
    }

    private function worker(): User
    {
        $user = User::factory()->frontlineWorker()->create(['approved_at' => now(), 'name' => 'Hemi Walker']);
        foreach (['hazards.view' => true, 'hazards.manage' => true, 'healthSafety.viewAllSites' => false] as $key => $allowed) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    private function drill(array $fields, ?Site $site = null): EmergencyDrill
    {
        $drill = EmergencyDrill::factory()->create(['site_id' => ($site ?? $this->site)->id, 'created_by' => $this->manager->id]);
        $drill->forceFill(['lock_version' => 1, 'completed_at' => null, 'cancelled_at' => null, ...$fields])->saveQuietly();

        return $drill->fresh();
    }

    public function test_the_lead_block_and_counts_follow_the_persons_sites(): void
    {
        $this->drill(['status' => 'completed', 'drill_type' => 'fire_evacuation', 'scheduled_at' => '2026-01-14 01:00:00', 'completed_at' => '2026-01-14 02:00:00']);
        $overdue = $this->drill(['status' => 'scheduled', 'drill_type' => 'fire_evacuation', 'title' => 'Fire evacuation — Tōtara House', 'scheduled_at' => '2026-07-14 01:00:00']);
        $later = $this->drill(['status' => 'scheduled', 'drill_type' => 'lockdown', 'title' => 'Lockdown', 'scheduled_at' => '2026-10-20 01:00:00']);
        $earthquake = $this->drill(['status' => 'completed', 'drill_type' => 'earthquake', 'title' => 'Earthquake', 'scheduled_at' => '2026-10-02 01:00:00', 'completed_at' => '2026-10-02 02:00:00', 'conducted_by' => $this->manager->id]);
        $fix = EmergencyDrillFinding::create(['emergency_drill_id' => $earthquake->id, 'finding_type' => 'non_conformance', 'description' => 'Fire door wedged open in the kitchen',
            'severity' => 'high', 'status' => 'open', 'due_date' => '2026-10-26', 'assigned_to' => $this->manager->id, 'created_by' => $this->manager->id]);
        $elsewhere = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->drill(['status' => 'scheduled', 'scheduled_at' => '2026-01-01 01:00:00', 'title' => 'Hidden'], $elsewhere);

        $this->actingAs($this->manager)->get('/health-safety/drills')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/drills/index')
                ->where('register_summary.boxes.things_to_fix_overdue', 1)
                ->where('register_summary.boxes.drills_run_90d', 1)
                ->where('register_summary.boxes.sites_drilled_90d', 1)
                ->has('register_summary.lead', 3)
                ->where('register_summary.lead.0.kind', 'drill')
                ->where('register_summary.lead.0.id', $overdue->id)
                ->where('register_summary.lead.0.due', '2026-07-14')
                ->where('register_summary.lead.0.site_id', $this->site->id)
                ->where('register_summary.lead.0.last_run_at', fn ($at) => str_starts_with((string) $at, '2026-01-14'))
                ->where('register_summary.lead.1.id', $later->id)
                ->where('register_summary.lead.2.kind', 'finding')
                ->where('register_summary.lead.2.id', $fix->id)
                ->where('register_summary.lead.2.drill_id', $earthquake->id)
                ->where('register_summary.lead.2.owner', 'Hemi Walker')
                ->where('register_summary.lead.2.drill_type', 'earthquake')
                ->where('register_summary.more_overdue.count', 0));
    }

    public function test_rows_say_who_runs_a_planned_drill_who_has_its_things_to_fix_and_when_an_overdue_site_last_ran_one(): void
    {
        $this->drill(['status' => 'completed', 'drill_type' => 'fire', 'scheduled_at' => '2026-01-14 01:00:00', 'completed_at' => '2026-01-14 02:00:00']);
        // A different kind of drill run later doesn't count as running this one.
        $this->drill(['status' => 'completed', 'drill_type' => 'earthquake', 'scheduled_at' => '2026-09-01 01:00:00', 'completed_at' => '2026-09-01 02:00:00']);
        $planned = $this->drill(['status' => 'scheduled', 'drill_type' => 'lockdown', 'scheduled_at' => '2026-11-12 01:00:00', 'conducted_by' => $this->manager->id]);
        $overdue = $this->drill(['status' => 'scheduled', 'drill_type' => 'fire_evacuation', 'scheduled_at' => '2026-07-14 01:00:00']);

        $this->actingAs($this->manager)->get('/health-safety/drills?period=all')->assertOk()
            ->assertInertia(function (Assert $page) use ($planned, $overdue) {
                $rows = collect($page->toArray()['props']['drills']['data'])->keyBy('id');
                $this->assertSame('Hemi Walker', $rows[$planned->id]['coordinator_name']);
                $this->assertNull($rows[$planned->id]['site_last_run_at']);
                $this->assertStringStartsWith('2026-01-14', (string) $rows[$overdue->id]['site_last_run_at']);
            });
    }
}
