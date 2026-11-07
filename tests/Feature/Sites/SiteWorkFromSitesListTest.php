<?php

/*
 * To-do #1 — schedule an inspection, record its result and log maintenance
 * from the Sites list (and the cross-site Inspections & Maintenance page),
 * plus the Overdue list behind the Sites header's Overdue meter.
 *
 * Saving goes to the existing endpoints (SiteInspectionController::store /
 * ::complete, AssetMaintenanceController::store); these tests pin that each
 * one works from the list pages, returns to them, keeps its permission
 * (checklists.schedule, checklists.run, assets.maintenance.record) and keeps
 * a manager to their own Sites.
 */

use App\Models\Asset;
use App\Models\AssetMaintenanceLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteInspectionRecord;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->seed(RbacSeeder::class);

    $this->workSite = Site::factory()->create([
        'name' => 'Kōwhai House',
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->otherSite = Site::factory()->create([
        'name' => 'Tōtara House',
        'type' => 'house',
        'is_active' => true,
        'archived' => false,
    ]);

    $this->manager = siteWorkActor(siteWorkManagerKeys(), $this->workSite);
});

/** Everything a house manager needs for the three actions. */
function siteWorkManagerKeys(): array
{
    return [
        'sites.viewAny',
        'sites.update',
        'checklists.view',
        'checklists.schedule',
        'checklists.run',
        'assets.viewAny',
        'assets.maintenance.record',
    ];
}

/** A user holding exactly these permission keys, scoped to one Site. */
function siteWorkActor(array $keys, ?Site $site = null): User
{
    $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
    $role = Role::create([
        'name' => 'site-work-'.str()->uuid(),
        'label' => 'Site work fixture',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->attach(Permission::query()->whereIn('key', $keys)->pluck('id'));
    $user->roles()->sync([$role->id]);

    if ($site) {
        ensureCanonicalHrStaffProfile($user, $site);
    }

    return $user->fresh();
}

function siteWorkSchedule(Site $site, array $attributes = []): SiteInspectionSchedule
{
    return SiteInspectionSchedule::create(array_replace([
        'site_id' => $site->id,
        'inspection_type' => 'Fire safety',
        'title' => 'Monthly fire safety check',
        'frequency' => 'monthly',
        'first_due_date' => now()->subMonths(2)->toDateString(),
        'next_due_date' => now()->subDays(3)->toDateString(),
        'is_active' => true,
    ], $attributes));
}

function siteWorkAsset(Site $site, User $by, array $attributes = []): Asset
{
    return Asset::factory()->forSite($site)->create(array_replace([
        'name' => 'Heat pump',
        'requires_maintenance' => true,
        'maintenance_due_at' => now()->subDays(5)->toDateString(),
        'created_by_user_id' => $by->id,
        'updated_by_user_id' => $by->id,
    ], $attributes));
}

function siteWorkOverdueRun(Site $site): SiteChecklistRun
{
    $template = SiteChecklistTemplate::create([
        'key' => 'site_work_'.uniqid(),
        'name' => 'Weekly house check',
        'applicable_to_type' => 'house',
        'frequency' => 'weekly',
        'is_active' => true,
    ]);
    $assignment = SiteChecklistAssignment::create([
        'site_id' => $site->id,
        'template_id' => $template->id,
        'frequency' => 'weekly',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ]);

    return SiteChecklistRun::create([
        'assignment_id' => $assignment->id,
        'site_id' => $site->id,
        'template_id' => $template->id,
        'scheduled_date' => now()->subDays(2)->toDateString(),
        'status' => 'scheduled',
    ]);
}

function siteWorkSchedulePayload(array $overrides = []): array
{
    return array_replace([
        'inspection_type' => 'Electrical',
        'title' => 'Quarterly electrical check',
        'frequency' => 'quarterly',
        'first_due_date' => now()->addWeek()->toDateString(),
        'description' => 'Test every RCD.',
        'auto_create_calendar_event' => false,
    ], $overrides);
}

// ── The Sites list ──────────────────────────────────────────────────────

test('the Sites list offers inspection and maintenance actions only to people who hold them', function () {
    $this->actingAs($this->manager)
        ->get('/sites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/index')
            ->where('siteWork.can.schedule', true)
            ->where('siteWork.can.record', true)
            ->where('siteWork.can.maintenance', true)
            ->has('siteWork.sites', 1)
            ->where('siteWork.sites.0.id', $this->workSite->id)
            ->where('siteWork.sites.0.name', 'Kōwhai House'));

    $viewer = siteWorkActor(['sites.viewAny'], $this->workSite);

    $this->actingAs($viewer)
        ->get('/sites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('siteWork.can.schedule', false)
            ->where('siteWork.can.record', false)
            ->where('siteWork.can.maintenance', false)
            ->has('siteWork.sites', 0));

    // Scheduling and recording also need sites.update (SitePolicy::update).
    $noSiteUpdate = siteWorkActor(['sites.viewAny', 'checklists.schedule', 'checklists.run'], $this->workSite);

    $this->actingAs($noSiteUpdate)
        ->get('/sites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('siteWork.can.schedule', false)
            ->where('siteWork.can.record', false));
});

test('the Overdue meter counts overdue inspections and only maintenance that is actually due', function () {
    siteWorkSchedule($this->workSite);
    siteWorkSchedule($this->workSite, ['title' => 'Not due yet', 'next_due_date' => now()->addDays(10)->toDateString()]);
    siteWorkSchedule($this->workSite, ['title' => 'Paused', 'is_active' => false]);
    siteWorkAsset($this->workSite, $this->manager);
    // Has scheduled servicing, but not due for two months: not overdue.
    siteWorkAsset($this->workSite, $this->manager, ['name' => 'Dishwasher', 'maintenance_due_at' => now()->addDays(60)->toDateString()]);
    // No scheduled servicing: an old date alone is not overdue work.
    siteWorkAsset($this->workSite, $this->manager, ['name' => 'Couch', 'requires_maintenance' => false]);
    siteWorkOverdueRun($this->workSite);
    // Another manager's Site never counts here.
    siteWorkSchedule($this->otherSite);
    siteWorkAsset($this->otherSite, $this->manager);

    $this->actingAs($this->manager)
        ->get('/sites')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.overdue_checks', 1)
            ->where('summary.overdue_inspections', 1)
            ->where('summary.overdue_maintenance', 1)
            ->where('summary.overdue', 3)
            ->where('sites.0.overdue_inspections_count', 1)
            ->where('sites.0.open_maintenance_count', 1));
});

// ── Schedule inspection ─────────────────────────────────────────────────

test('a manager schedules an inspection from the Sites list and lands back on it', function () {
    $this->actingAs($this->manager)
        ->from('/sites')
        ->post("/sites/{$this->workSite->id}/inspections", siteWorkSchedulePayload(['redirect_back' => true]))
        ->assertRedirect('/sites')
        ->assertSessionHas('success', 'Inspection schedule created.');

    $schedule = SiteInspectionSchedule::query()->where('title', 'Quarterly electrical check')->firstOrFail();
    expect($schedule->site_id)->toBe($this->workSite->id)
        ->and($schedule->next_due_date->toDateString())->toBe(now()->addWeek()->toDateString())
        ->and($schedule->is_active)->toBeTrue();
});

test('scheduling without the flag still lands on the Site inspections page', function () {
    $this->actingAs($this->manager)
        ->from('/sites')
        ->post("/sites/{$this->workSite->id}/inspections", siteWorkSchedulePayload())
        ->assertRedirect(route('sites.inspections.index', $this->workSite));
});

test('the return flag never sends anyone to another site', function () {
    $this->actingAs($this->manager)
        ->withHeader('referer', 'https://phishing.example/sites')
        ->post("/sites/{$this->workSite->id}/inspections", siteWorkSchedulePayload(['redirect_back' => true]))
        ->assertRedirect(route('sites.inspections.index', $this->workSite));
});

test('scheduling an inspection is refused without checklists.schedule, without sites.update and outside the manager\'s sites', function () {
    $noSchedule = siteWorkActor(array_diff(siteWorkManagerKeys(), ['checklists.schedule']), $this->workSite);
    $this->actingAs($noSchedule)
        ->post("/sites/{$this->workSite->id}/inspections", siteWorkSchedulePayload(['redirect_back' => true]))
        ->assertForbidden();

    $noSiteUpdate = siteWorkActor(array_diff(siteWorkManagerKeys(), ['sites.update']), $this->workSite);
    $this->actingAs($noSiteUpdate)
        ->post("/sites/{$this->workSite->id}/inspections", siteWorkSchedulePayload(['redirect_back' => true]))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post("/sites/{$this->otherSite->id}/inspections", siteWorkSchedulePayload(['redirect_back' => true]))
        ->assertNotFound();

    expect(SiteInspectionSchedule::query()->where('title', 'Quarterly electrical check')->exists())->toBeFalse();
});

// ── Record inspection result ────────────────────────────────────────────

test('a manager records an inspection result from the Sites list and the overdue count drops', function () {
    $schedule = siteWorkSchedule($this->workSite);

    $this->actingAs($this->manager)
        ->get('/sites')
        ->assertInertia(fn ($page) => $page->where('summary.overdue_inspections', 1));

    $this->actingAs($this->manager)
        ->from('/sites')
        ->post("/sites/{$this->workSite->id}/inspections/{$schedule->id}/complete", [
            'result' => 'pass',
            'findings' => 'Alarms tested, all working.',
            'redirect_back' => true,
        ])
        ->assertRedirect('/sites')
        ->assertSessionHas('success', 'Inspection recorded successfully.');

    $record = SiteInspectionRecord::query()->where('schedule_id', $schedule->id)->firstOrFail();
    expect($record->result)->toBe('pass')
        ->and($record->completed_by_user_id)->toBe($this->manager->id)
        ->and($schedule->fresh()->next_due_date->toDateString())
        ->toBe(now()->subDays(3)->addMonth()->toDateString());

    $this->actingAs($this->manager)
        ->get('/sites')
        ->assertInertia(fn ($page) => $page
            ->where('summary.overdue_inspections', 0)
            ->where('summary.overdue', 0));
});

test('recording a result is refused without checklists.run, outside the manager\'s sites and for another site\'s schedule', function () {
    $schedule = siteWorkSchedule($this->workSite);
    $otherSchedule = siteWorkSchedule($this->otherSite);

    $noRun = siteWorkActor(array_diff(siteWorkManagerKeys(), ['checklists.run']), $this->workSite);
    $this->actingAs($noRun)
        ->post("/sites/{$this->workSite->id}/inspections/{$schedule->id}/complete", ['result' => 'pass', 'redirect_back' => true])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post("/sites/{$this->otherSite->id}/inspections/{$otherSchedule->id}/complete", ['result' => 'pass', 'redirect_back' => true])
        ->assertNotFound();

    // A schedule from another Site can't be completed through this one.
    $this->actingAs($this->manager)
        ->post("/sites/{$this->workSite->id}/inspections/{$otherSchedule->id}/complete", ['result' => 'pass', 'redirect_back' => true])
        ->assertNotFound();

    expect(SiteInspectionRecord::query()->count())->toBe(0);
});

// ── Log maintenance ─────────────────────────────────────────────────────

test('a manager logs maintenance from the Sites list and the asset leaves the overdue count', function () {
    $asset = siteWorkAsset($this->workSite, $this->manager);

    $this->actingAs($this->manager)
        ->get('/sites')
        ->assertInertia(fn ($page) => $page->where('summary.overdue_maintenance', 1));

    $this->actingAs($this->manager)
        ->from('/sites')
        ->post("/assets/{$asset->id}/maintenance", [
            'performed_at' => now()->toDateString(),
            'type' => 'Annual service',
            'vendor' => 'Kōwhai Electrical',
            'cost' => '180.00',
            'next_due_at' => now()->addYear()->toDateString(),
        ])
        ->assertRedirect('/sites')
        ->assertSessionHas('success', 'Maintenance logged.');

    expect(AssetMaintenanceLog::query()->where('asset_id', $asset->id)->count())->toBe(1)
        ->and($asset->fresh()->maintenance_due_at->toDateString())->toBe(now()->addYear()->toDateString());

    $this->actingAs($this->manager)
        ->get('/sites')
        ->assertInertia(fn ($page) => $page
            ->where('summary.overdue_maintenance', 0)
            ->where('summary.overdue', 0));
});

test('logging the due service without a next date clears the due date, but older work leaves it', function () {
    $serviced = siteWorkAsset($this->workSite, $this->manager);
    $catchUp = siteWorkAsset($this->workSite, $this->manager, ['name' => 'Smoke alarms']);

    $this->actingAs($this->manager)
        ->from('/sites')
        ->post("/assets/{$serviced->id}/maintenance", ['performed_at' => now()->toDateString()])
        ->assertRedirect('/sites');

    // Logged as done before it fell due: the due date stays.
    $this->actingAs($this->manager)
        ->from('/sites')
        ->post("/assets/{$catchUp->id}/maintenance", ['performed_at' => now()->subDays(10)->toDateString()])
        ->assertRedirect('/sites');

    expect($serviced->fresh()->maintenance_due_at)->toBeNull()
        ->and($catchUp->fresh()->maintenance_due_at->toDateString())->toBe(now()->subDays(5)->toDateString());
});

test('logging maintenance is refused without assets.maintenance.record and for assets outside the manager\'s sites', function () {
    $asset = siteWorkAsset($this->workSite, $this->manager);
    $hidden = siteWorkAsset($this->otherSite, $this->manager);

    $noRecord = siteWorkActor(array_diff(siteWorkManagerKeys(), ['assets.maintenance.record']), $this->workSite);
    $this->actingAs($noRecord)
        ->post("/assets/{$asset->id}/maintenance", ['performed_at' => now()->toDateString()])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post("/assets/{$hidden->id}/maintenance", ['performed_at' => now()->toDateString()])
        ->assertNotFound();

    expect(AssetMaintenanceLog::query()->count())->toBe(0);
});

// ── The Overdue list ────────────────────────────────────────────────────

test('the Overdue list shows only the manager\'s sites with real totals and the actions they may take', function () {
    $schedule = siteWorkSchedule($this->workSite);
    $asset = siteWorkAsset($this->workSite, $this->manager);
    $run = siteWorkOverdueRun($this->workSite);
    siteWorkSchedule($this->otherSite);
    siteWorkAsset($this->otherSite, $this->manager);
    siteWorkOverdueRun($this->otherSite);

    $this->actingAs($this->manager)
        ->getJson('/sites/overdue-work')
        ->assertOk()
        ->assertJsonPath('inspections.total', 1)
        ->assertJsonPath('inspections.items.0.id', $schedule->id)
        ->assertJsonPath('inspections.items.0.site.id', $this->workSite->id)
        ->assertJsonPath('inspections.items.0.days_overdue', 3)
        ->assertJsonPath('inspections.items.0.can_record', true)
        ->assertJsonPath('maintenance.total', 1)
        ->assertJsonPath('maintenance.items.0.id', $asset->id)
        ->assertJsonPath('maintenance.items.0.days_overdue', 5)
        ->assertJsonPath('maintenance.items.0.can_log', true)
        ->assertJsonPath('checklists.total', 1)
        ->assertJsonPath('checklists.items.0.id', $run->id)
        ->assertJsonPath('checklists.items.0.href', "/sites/{$this->workSite->id}/checklists?run={$run->id}")
        ->assertJsonPath('checklists.can_complete', true);

    $this->actingAs($this->manager)
        ->getJson("/sites/overdue-work?site_id={$this->otherSite->id}")
        ->assertNotFound();

    // Can see the Sites list, can't act: the same rows, no actions.
    $viewer = siteWorkActor(['sites.viewAny', 'assets.viewAny'], $this->workSite);
    $this->actingAs($viewer)
        ->getJson('/sites/overdue-work')
        ->assertOk()
        ->assertJsonPath('inspections.total', 1)
        ->assertJsonPath('inspections.items.0.can_record', false)
        ->assertJsonPath('maintenance.items.0.can_log', false)
        ->assertJsonPath('checklists.can_complete', false);
});

test('the Overdue list is refused to people who cannot open the Sites list', function () {
    $supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $supportWorker->roles()->sync([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);

    $this->actingAs($supportWorker)
        ->getJson('/sites/overdue-work')
        ->assertForbidden();
});

test('the Overdue list is capped at 50 rows per group and keeps the real total', function () {
    foreach (range(1, 55) as $i) {
        siteWorkAsset($this->workSite, $this->manager, [
            'name' => "Asset {$i}",
            'maintenance_due_at' => now()->subDays($i)->toDateString(),
        ]);
    }

    $this->actingAs($this->manager)
        ->getJson('/sites/overdue-work')
        ->assertOk()
        ->assertJsonPath('limit', 50)
        ->assertJsonPath('maintenance.total', 55)
        ->assertJsonCount(50, 'maintenance.items')
        // Most overdue first.
        ->assertJsonPath('maintenance.items.0.days_overdue', 55);
});

// ── Pop-up choices for one Site ─────────────────────────────────────────

test('the pop-up choices list one site\'s inspections and the assets the manager may log, and nothing else', function () {
    $due = siteWorkSchedule($this->workSite);
    siteWorkSchedule($this->workSite, ['title' => 'Paused', 'is_active' => false]);
    $dueAsset = siteWorkAsset($this->workSite, $this->manager, ['name' => 'Heat pump']);
    siteWorkAsset($this->workSite, $this->manager, ['name' => 'Dryer', 'maintenance_due_at' => now()->addDays(30)->toDateString()]);

    $this->actingAs($this->manager)
        ->getJson("/sites/{$this->workSite->id}/work-options?kind=schedules")
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', $due->id)
        ->assertJsonPath('items.0.days_overdue', 3);

    $this->actingAs($this->manager)
        ->getJson("/sites/{$this->workSite->id}/work-options?kind=assets")
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('items.0.id', $dueAsset->id)
        ->assertJsonPath('items.0.days_overdue', 5)
        ->assertJsonPath('items.1.days_overdue', null);

    $this->actingAs($this->manager)
        ->getJson("/sites/{$this->workSite->id}/work-options?kind=assets&q=dry")
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.name', 'Dryer');

    $this->actingAs($this->manager)
        ->getJson("/sites/{$this->otherSite->id}/work-options?kind=assets")
        ->assertNotFound();

    $noRun = siteWorkActor(array_diff(siteWorkManagerKeys(), ['checklists.run']), $this->workSite);
    $this->actingAs($noRun)
        ->getJson("/sites/{$this->workSite->id}/work-options?kind=schedules")
        ->assertForbidden();

    $noRecord = siteWorkActor(array_diff(siteWorkManagerKeys(), ['assets.maintenance.record']), $this->workSite);
    $this->actingAs($noRecord)
        ->getJson("/sites/{$this->workSite->id}/work-options?kind=assets")
        ->assertForbidden();
});

// ── The cross-site Inspections & Maintenance page ───────────────────────

test('the cross-site Inspections & Maintenance page offers the same actions and returns to itself', function () {
    $schedule = siteWorkSchedule($this->workSite);
    siteWorkAsset($this->workSite, $this->manager);
    siteWorkAsset($this->otherSite, $this->manager);

    $this->actingAs($this->manager)
        ->get('/sites/inspections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/inspections/global')
            ->where('can.schedule', true)
            ->where('can.record', true)
            ->where('can.maintenance', true)
            ->where('schedules.0.id', $schedule->id)
            ->where('schedules.0.can_record', true)
            ->has('maintenanceDue', 1)
            ->where('maintenanceDue.0.site_id', $this->workSite->id)
            ->where('maintenanceDue.0.count', 1));

    $this->actingAs($this->manager)
        ->from('/sites/inspections')
        ->post("/sites/{$this->workSite->id}/inspections/{$schedule->id}/complete", [
            'result' => 'partial',
            'findings' => 'Exit light out.',
            'redirect_back' => true,
        ])
        ->assertRedirect('/sites/inspections');

    $viewer = siteWorkActor(['sites.viewAny', 'checklists.view'], $this->workSite);
    $this->actingAs($viewer)
        ->get('/sites/inspections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.schedule', false)
            ->where('can.record', false)
            ->where('can.maintenance', false)
            ->where('schedules.0.can_record', false));
});

test('a Site inspections page only offers scheduling to people who may schedule there', function () {
    $this->actingAs($this->manager)
        ->get("/sites/{$this->workSite->id}/inspections")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.schedule', true));

    $viewer = siteWorkActor(['sites.viewAny', 'checklists.view'], $this->workSite);
    $this->actingAs($viewer)
        ->get("/sites/{$this->workSite->id}/inspections")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.schedule', false));
});
