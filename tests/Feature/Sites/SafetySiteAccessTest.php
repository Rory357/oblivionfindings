<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);

    $this->visibleSite = Site::factory()->create([
        'name' => 'Approved Safety Site',
        'type' => 'house',
        'is_active' => true,
    ]);
    $this->hiddenSite = Site::factory()->create([
        'name' => 'Restricted Safety Site',
        'type' => 'house',
        'is_active' => true,
    ]);

    $this->viewer = safetyBridgeCurrentStaff($this->visibleSite);
});

function safetyBridgeCurrentStaff(Site $site): User
{
    $user = User::factory()->create([
        'name' => 'Scoped Safety Lead',
        'role' => 'team_lead',
        'approved_at' => now(),
    ]);
    $user->roles()->sync([
        Role::query()->where('name', 'team_lead')->firstOrFail()->id,
    ]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user->fresh(['roles', 'hrEmployeeProfile']);
}

function safetyBridgeHazard(Site $site, User $reporter, string $description): SiteHazard
{
    return SiteHazard::query()->create([
        'site_id' => $site->id,
        'reported_by_user_id' => $reporter->id,
        'hazard_type' => 'slip_trip_fall',
        'severity' => 'low',
        'likelihood' => 'rare',
        'description' => $description,
        'status' => 'open',
    ]);
}

function safetyBridgeSchedule(Site $site, string $title): SiteInspectionSchedule
{
    return SiteInspectionSchedule::query()->create([
        'site_id' => $site->id,
        'inspection_type' => 'fire_safety',
        'title' => $title,
        'frequency' => 'monthly',
        'first_due_date' => today(),
        'next_due_date' => today(),
        'is_active' => true,
    ]);
}

test('hazard registers details exports and mutations honour canonical Site access', function (): void {
    $visibleHazard = safetyBridgeHazard($this->visibleSite, $this->viewer, 'Visible safety hazard');
    $hiddenHazard = safetyBridgeHazard($this->hiddenSite, $this->viewer, 'Restricted safety hazard');

    $this->actingAs($this->viewer)
        ->get('/compliance/hazards')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('hazards.data', 1)
            ->where('hazards.data.0.id', $visibleHazard->id)
            ->has('sites', 1)
            ->where('sites.0.id', $this->visibleSite->id));

    $this->actingAs($this->viewer)
        ->get("/compliance/hazards?hazard={$hiddenHazard->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('detail', null));

    foreach ([$hiddenHazard->id, (int) SiteHazard::query()->max('id') + 1] as $hazardId) {
        $this->actingAs($this->viewer)
            ->get("/hazards/{$hazardId}")
            ->assertNotFound()
            ->assertDontSee('Restricted safety hazard');
    }

    $this->actingAs($this->viewer)
        ->get("/compliance/hazards?site_id={$this->hiddenSite->id}")
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->get("/compliance/hazards/export?site_id={$this->hiddenSite->id}")
        ->assertForbidden();

    $hazardCount = SiteHazard::query()->count();
    foreach ([$this->hiddenSite->id, (int) Site::query()->max('id') + 1] as $siteId) {
        $this->actingAs($this->viewer)
            ->post("/sites/{$siteId}/hazards", [
                'hazard_type' => 'slip_trip_fall',
                'severity' => 'low',
                'likelihood' => 'rare',
                'description' => 'Rejected cross-Site hazard',
            ])
            ->assertNotFound();
    }

    expect(SiteHazard::query()->where('description', 'Rejected cross-Site hazard')->exists())->toBeFalse()
        ->and(SiteHazard::query()->count())->toBe($hazardCount)
        ->and($hiddenHazard->fresh()->status)->toBe('open');
});

test('inspection registers filters and mutations honour canonical Site access', function (): void {
    $visibleSchedule = safetyBridgeSchedule($this->visibleSite, 'Visible fire inspection');
    $hiddenSchedule = safetyBridgeSchedule($this->hiddenSite, 'Restricted fire inspection');
    $hiddenHazard = safetyBridgeHazard($this->hiddenSite, $this->viewer, 'Restricted linked hazard');

    SiteInspectionRecord::query()->create([
        'schedule_id' => $visibleSchedule->id,
        'site_id' => $this->visibleSite->id,
        'due_date' => today(),
        'completed_at' => now(),
        'completed_by_user_id' => $this->viewer->id,
        'result' => 'pass',
        'findings' => 'Visible result',
    ]);
    SiteInspectionRecord::query()->create([
        'schedule_id' => $hiddenSchedule->id,
        'site_id' => $this->hiddenSite->id,
        'due_date' => today(),
        'completed_at' => now(),
        'completed_by_user_id' => $this->viewer->id,
        'result' => 'pass',
        'findings' => 'Restricted result',
    ]);

    $this->actingAs($this->viewer)
        ->get('/sites/inspections')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('schedules', 1)
            ->where('schedules.0.id', $visibleSchedule->id)
            ->has('records', 1)
            ->where('records.0.site_id', $this->visibleSite->id)
            ->has('sites', 1)
            ->where('sites.0.id', $this->visibleSite->id));

    $this->actingAs($this->viewer)
        ->get("/sites/inspections?site_id={$this->hiddenSite->id}")
        ->assertForbidden();

    $recordCount = SiteInspectionRecord::query()->count();
    $scheduleCount = SiteInspectionSchedule::query()->count();
    foreach ([$this->hiddenSite->id, (int) Site::query()->max('id') + 1] as $siteId) {
        $this->actingAs($this->viewer)
            ->get("/sites/{$siteId}/inspections")
            ->assertNotFound()
            ->assertDontSee('Restricted fire inspection')
            ->assertDontSee('Restricted result');

        $this->actingAs($this->viewer)
            ->post("/sites/{$siteId}/inspections", [
                'inspection_type' => 'fire_safety',
                'title' => 'Rejected cross-Site inspection',
                'frequency' => 'monthly',
                'first_due_date' => today()->toDateString(),
            ])
            ->assertNotFound();

        $this->actingAs($this->viewer)
            ->post("/sites/{$siteId}/inspections/{$hiddenSchedule->id}/complete", [
                'result' => 'pass',
            ])
            ->assertNotFound();
    }

    $this->actingAs($this->viewer)
        ->from("/sites/{$this->visibleSite->id}/inspections")
        ->post("/sites/{$this->visibleSite->id}/inspections/{$visibleSchedule->id}/complete", [
            'result' => 'pass',
            'linked_hazard_id' => $hiddenHazard->id,
        ])
        ->assertSessionHasErrors('linked_hazard_id');

    expect(SiteInspectionSchedule::query()->where('title', 'Rejected cross-Site inspection')->exists())->toBeFalse()
        ->and(SiteInspectionSchedule::query()->count())->toBe($scheduleCount)
        ->and(SiteInspectionRecord::query()->count())->toBe($recordCount);
});
