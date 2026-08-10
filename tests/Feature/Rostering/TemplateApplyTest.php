<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('creates roster templates with related shifts from the factory helper', function () {
    $template = RosterTemplate::factory()->withShifts(5)->create();

    expect($template->templateShifts()->count())->toBe(5);
});

it('applies a template once per actor and week through the lifecycle assignment path', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $assignee = rosteringTemplateAssignee($site);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
    ]);

    RosterTemplateShift::factory()->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'user_id' => $assignee->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);

    $payload = [
        'week_start' => '2026-05-04',
        'confirm_warnings' => true,
    ];

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), $payload)
        ->assertRedirect(route('operations.rostering.index', ['week' => '2026-05-04']));

    // Second apply within the hour is idempotent — it bounces back to the
    // Templates tab with a status note instead of creating duplicate shifts.
    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), $payload)
        ->assertRedirect(route('operations.rostering.index', ['tab' => 'templates']));

    expect(Shift::count())->toBe(1);
    $shift = Shift::first();

    expect($shift->user_id)->toBe($assignee->id);
    expect($shift->status)->toBe('scheduled');
    expect($shift->created_by)->toBe($actor->id);
});

it('blocks conflicting proposed template rows before creating shifts', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $assignee = rosteringTemplateAssignee($site);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
    ]);

    RosterTemplateShift::factory()->count(2)->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'user_id' => $assignee->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-04',
            'confirm_warnings' => true,
        ])
        ->assertSessionHasErrors('preflight_blocks');

    expect(Shift::count())->toBe(0);
});

it('surfaces preflight warnings before applying unassigned template rows', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
    ]);

    RosterTemplateShift::factory()->unassigned()->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-04',
        ])
        ->assertSessionHasErrors('preflight_warnings');

    expect(Shift::count())->toBe(0);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-04',
            'confirm_warnings' => true,
        ])
        ->assertRedirect(route('operations.rostering.index', ['week' => '2026-05-04']));

    expect(Shift::count())->toBe(1);
});

it('stamps the pattern across cadence cycles', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
        'template_type' => 'fortnightly',
    ]);

    RosterTemplateShift::factory()->unassigned()->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-04',
            'cycles' => 3,
            'confirm_warnings' => true,
        ])
        ->assertRedirect(route('operations.rostering.index', ['week' => '2026-05-04']));

    // Fortnightly cadence advances 2 weeks per cycle.
    $dates = Shift::query()->orderBy('starts_at')->get()
        ->map(fn (Shift $shift) => $shift->starts_at->toDateString())
        ->all();

    expect($dates)->toBe(['2026-05-04', '2026-05-18', '2026-06-01']);
});

it('snaps a non-Monday week_start to the Monday anchor', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
    ]);

    RosterTemplateShift::factory()->unassigned()->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);

    // 2026-05-06 is a Wednesday; the Monday row must still land on Monday 05-04.
    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-06',
            'confirm_warnings' => true,
        ])
        ->assertRedirect(route('operations.rostering.index', ['week' => '2026-05-04']));

    expect(Shift::first()->starts_at->toDateString())->toBe('2026-05-04');
});

it('carries is_lone_worker from a template shift onto the generated shift', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $assignee = rosteringTemplateAssignee($site);
    $template = RosterTemplate::factory()->create([
        'created_by' => $actor->id,
    ]);

    RosterTemplateShift::factory()->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'user_id' => $assignee->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
        'is_lone_worker' => true,
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-04',
            'confirm_warnings' => true,
        ])
        ->assertRedirect(route('operations.rostering.index', ['week' => '2026-05-04']));

    expect(Shift::count())->toBe(1);
    expect(Shift::first()->is_lone_worker)->toBeTrue();
});

function rosteringTemplateActor(Site $site): User
{
    $actor = rosteringTemplateAssignee($site);
    $role = Role::create([
        'name' => 'rostering-template-test-'.uniqid(),
        'label' => 'Rostering template test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $permission = Permission::firstOrCreate(
        ['key' => 'roster_templates.update'],
        ['description' => 'Update roster templates', 'group' => 'Rostering', 'module' => 'operations'],
    );

    $role->permissions()->sync([$permission->id]);
    $actor->roles()->attach($role);

    return $actor;
}

function rosteringTemplateAssignee(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user;
}
