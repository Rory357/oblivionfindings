<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\RosteringController;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
});

it('creates roster templates with related shifts from the factory helper', function () {
    $template = RosterTemplate::factory()->withShifts(5)->create();

    expect($template->templateShifts()->count())->toBe(5);
});

it('exposes exact Site scoped client and service context options to template managers', function () {
    $site = Site::factory()->create(['name' => 'Accessible roster Site']);
    $foreignSite = Site::factory()->create([
        'name' => 'Inactive foreign roster Site',
        'is_active' => false,
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Accessible',
        'last_name' => 'Client',
        'status' => 'active',
    ]);
    Client::factory()->create([
        'site_id' => $foreignSite->id,
        'first_name' => 'Foreign',
        'last_name' => 'Client',
        'status' => 'active',
    ]);
    $globalContext = ServiceContext::factory()->create([
        'name' => 'Global template context',
        'site_id' => null,
    ]);
    $siteContext = ServiceContext::factory()->create([
        'name' => 'Site template context',
        'site_id' => $site->id,
    ]);
    ServiceContext::factory()->create([
        'name' => 'Foreign template context',
        'site_id' => $foreignSite->id,
    ]);
    $actor = rosteringTemplateActor($site);
    $viewPermission = Permission::firstOrCreate(
        ['key' => 'rostering.viewAny'],
        ['description' => 'View rostering', 'group' => 'Rostering', 'module' => 'operations'],
    );
    $actor->permissionOverrides()->syncWithoutDetaching([
        $viewPermission->id => ['allowed' => true],
    ]);
    $actor->unsetRelation('permissionOverrides');
    $actor->unsetRelation('roles');

    $this->actingAs($actor)
        ->get(route('operations.rostering.index', ['tab' => 'templates']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/rostering/index')
            ->has('clients', 1)
            ->where('clients.0.id', $client->id)
            ->where('clients.0.site_id', $site->id)
            ->has('serviceContexts', 2)
            ->where('serviceContexts.0.id', $globalContext->id)
            ->where('serviceContexts.0.site_id', null)
            ->where('serviceContexts.1.id', $siteContext->id)
            ->where('serviceContexts.1.site_id', $site->id));
});

it('requires canonical client ownership when storing a template row', function () {
    $site = Site::factory()->create();
    $serviceContext = ServiceContext::factory()->create([
        'site_id' => $site->id,
        'is_active' => true,
    ]);
    $actor = rosteringTemplateActor($site);
    $permission = Permission::firstOrCreate(
        ['key' => 'roster_templates.create'],
        ['description' => 'Create roster templates', 'group' => 'Rostering', 'module' => 'operations'],
    );
    $actor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $actor->unsetRelation('permissionOverrides');
    $actor->unsetRelation('roles');

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.store'), [
            'name' => 'Clientless template',
            'template_type' => 'weekly',
            'template_shifts' => [[
                'client_id' => null,
                'service_context_id' => $serviceContext->id,
                'user_id' => null,
                'day_of_week' => 0,
                'start_time' => '09:00',
                'end_time' => '13:00',
            ]],
        ])
        ->assertSessionHasErrors('template_shifts.0.client_id');

    expect(RosterTemplate::count())->toBe(0)
        ->and(RosterTemplateShift::count())->toBe(0)
        ->and(Shift::count())->toBe(0);
});

it('rejects an existing clientless template before apply creates a shift', function () {
    $site = Site::factory()->create();
    $serviceContext = ServiceContext::factory()->create([
        'site_id' => $site->id,
        'is_active' => true,
    ]);
    $actor = rosteringTemplateActor($site);
    $template = RosterTemplate::factory()->create(['created_by' => $actor->id]);
    RosterTemplateShift::factory()->unassigned()->create([
        'roster_template_id' => $template->id,
        'client_id' => null,
        'service_context_id' => $serviceContext->id,
        'day_of_week' => 0,
        'start_time' => '09:00',
        'end_time' => '13:00',
    ]);

    $this->actingAs($actor)
        ->post(route('operations.rostering.templates.apply', $template), [
            'week_start' => '2026-05-04',
            'confirm_warnings' => true,
        ])
        ->assertSessionHasErrors('template_shifts');

    expect(Shift::count())->toBe(0);
});

it('rejects inactive and cross Site service contexts for a client owned template row', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $inactiveContext = ServiceContext::factory()->create([
        'site_id' => $site->id,
        'is_active' => false,
    ]);
    $otherSiteContext = ServiceContext::factory()->create([
        'site_id' => $otherSite->id,
        'is_active' => true,
    ]);
    $actor = rosteringTemplateActor($site);
    $actor->hrEmployeeProfile->forceFill([
        'secondary_site_ids' => [$otherSite->id],
    ])->save();
    $permission = Permission::firstOrCreate(
        ['key' => 'roster_templates.create'],
        ['description' => 'Create roster templates', 'group' => 'Rostering', 'module' => 'operations'],
    );
    $actor->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
    $actor->unsetRelation('permissionOverrides');
    $actor->unsetRelation('roles');
    $actor->unsetRelation('hrEmployeeProfile');

    foreach ([$inactiveContext, $otherSiteContext] as $context) {
        $this->actingAs($actor)
            ->post(route('operations.rostering.templates.store'), [
                'name' => 'Invalid context '.$context->id,
                'template_type' => 'weekly',
                'template_shifts' => [[
                    'client_id' => $client->id,
                    'service_context_id' => $context->id,
                    'user_id' => null,
                    'day_of_week' => 0,
                    'start_time' => '09:00',
                    'end_time' => '13:00',
                ]],
            ])
            ->assertSessionHasErrors('template_shifts');
    }

    expect(RosterTemplate::count())->toBe(0)
        ->and(RosterTemplateShift::count())->toBe(0)
        ->and(Shift::count())->toBe(0);
});

it('applies a template once per actor and week through the lifecycle assignment path', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $actor = rosteringTemplateActor($site, canOverrideEligibility: true);
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
    $actor = rosteringTemplateActor($site, canOverrideEligibility: true);
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

it('serializes is_lone_worker for the template edit wizard', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $template = RosterTemplate::factory()->create();
    RosterTemplateShift::factory()->create([
        'roster_template_id' => $template->id,
        'client_id' => $client->id,
        'service_context_id' => null,
        'is_lone_worker' => true,
    ]);
    $method = new ReflectionMethod(RosteringController::class, 'buildRosterTemplates');
    $method->setAccessible(true);
    $payload = collect($method->invoke(app(RosteringController::class)))
        ->firstWhere('id', $template->id);

    expect($payload)->not->toBeNull()
        ->and($payload['template_shifts'][0]['is_lone_worker'])->toBeTrue();
});

function rosteringTemplateActor(Site $site, bool $canOverrideEligibility = false): User
{
    $actor = rosteringTemplateAssignee($site);
    $role = Role::create([
        'name' => 'rostering-template-test-'.uniqid(),
        'label' => 'Rostering template test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $permissions = collect([Permission::firstOrCreate(
        ['key' => 'roster_templates.update'],
        ['description' => 'Update roster templates', 'group' => 'Rostering', 'module' => 'operations'],
    )]);

    if ($canOverrideEligibility) {
        $permissions->push(Permission::firstOrCreate(
            ['key' => 'shifts.overrideEligibility'],
            [
                'description' => 'Override eligibility warnings for shift assignment',
                'group' => 'shifts',
                'module' => 'operations',
            ],
        ));
    }

    $role->permissions()->sync($permissions->pluck('id')->all());
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
