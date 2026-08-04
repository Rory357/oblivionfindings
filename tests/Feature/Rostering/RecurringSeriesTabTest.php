<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\User;

function seriesActorWith(array $permissionKeys, ?Site $site = null): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $role = Role::create([
        'name' => 'series-test-'.uniqid(),
        'label' => 'Series test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $ids = collect($permissionKeys)
        ->map(fn (string $key) => Permission::firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'Rostering', 'module' => 'operations'],
        )->id)
        ->all();
    $role->permissions()->sync($ids);
    $actor->roles()->attach($role);

    if ($site) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }

    return $actor;
}

function makeSeries(User $actor): ShiftSeries
{
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);

    return ShiftSeries::create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-31',
        'timezone' => 'Pacific/Auckland',
        'by_weekday' => ['mon'],
        'starts_time' => '09:00',
        'ends_time' => '13:00',
        'status' => 'scheduled',
        'created_by' => $actor->id,
    ]);
}

it('redirects rostering users from the series index to the recurring tab', function () {
    $actor = seriesActorWith(['rostering.viewAny']);

    $this->actingAs($actor)
        ->get(route('operations.shifts.series.index'))
        ->assertRedirect(route('operations.rostering.index', ['tab' => 'recurring']));
});

it('redirects rostering users from a series to the recurring tab pop-up', function () {
    $actor = seriesActorWith(['rostering.viewAny']);
    $series = makeSeries($actor);

    $this->actingAs($actor)
        ->get(route('operations.shifts.series.show', $series))
        ->assertRedirect(route('operations.rostering.index', [
            'tab' => 'recurring',
            'series' => $series->id,
        ]));
});

it('keeps the standalone series page for managers without rostering workspace access', function () {
    // shifts.manageAny passes role_scope but not the rostering.viewAny redirect,
    // so these users still get the read-only standalone fallback.
    $actor = seriesActorWith(['shifts.manageAny']);

    $this->actingAs($actor)
        ->get(route('operations.shifts.series.index'))
        ->assertOk();
});

it('duplicates a roster template with its shift rows', function () {
    $site = Site::factory()->create();
    $actor = seriesActorWith(['roster_templates.create'], $site);
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
        'name' => 'North House weekdays',
    ]);
    RosterTemplateShift::factory()->count(3)->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'user_id' => null,
        'service_context_id' => null,
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.duplicate', $template))
        ->assertRedirect(route('operations.rostering.index', ['tab' => 'templates']));

    $copy = RosterTemplate::query()->where('name', 'North House weekdays (copy)')->first();

    expect($copy)->not->toBeNull();
    expect($copy->templateShifts()->count())->toBe(3);
    expect(RosterTemplate::count())->toBe(2);
});

it('denies duplicating a template whose rows reference an inaccessible Site', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $actor = seriesActorWith(['roster_templates.create'], $accessibleSite);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
        'name' => 'Outside Site pattern',
    ]);
    RosterTemplateShift::factory()->create([
        'roster_template_id' => $template->id,
        'client_id' => $outsideClient->id,
        'user_id' => null,
        'service_context_id' => null,
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.duplicate', $template))
        ->assertForbidden();

    expect(RosterTemplate::query()->where('name', 'Outside Site pattern (copy)')->exists())->toBeFalse();
});
