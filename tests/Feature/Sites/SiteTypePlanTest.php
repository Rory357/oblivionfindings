<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteTypePlan;
use App\Models\User;
use App\Services\Sites\SiteTypePlanService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function siteTypePlanUser(string $roleName = 'admin'): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

function sampleSitePlanLayout(string $label = 'Bedroom 1'): array
{
    return [
        'schema_version' => 1,
        'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
        'grid' => ['enabled' => true, 'size' => 20, 'snap' => true],
        'rooms' => [
            [
                'id' => 'room-1',
                'label' => $label,
                'shape' => 'rect',
                'x' => 0.1,
                'y' => 0.1,
                'width' => 0.25,
                'height' => 0.2,
            ],
        ],
        'walls' => [],
        'doors' => [],
        'windows' => [],
        'labels' => [],
    ];
}

function createSiteTypePlanForTest(Site $site, string $status = 'draft', array $pins = []): int
{
    $planId = DB::table('site_type_plans')->insertGetId([
        'tenant_id' => $site->tenant_id,
        'site_id' => $site->id,
        'site_type' => $site->type,
        'status' => $status,
        'version' => 1,
        'layout' => json_encode(sampleSitePlanLayout()),
        'published_at' => $status === 'published' ? now() : null,
        'published_by_user_id' => null,
        'created_by_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($pins as $index => $pin) {
        DB::table('site_type_plan_pins')->insert(array_merge([
            'tenant_id' => $site->tenant_id,
            'site_type_plan_id' => $planId,
            'kind' => 'custom_marker',
            'label' => 'Marker',
            'x' => 0.5,
            'y' => 0.5,
            'rotation_deg' => 0,
            'sort_order' => $index,
            'created_at' => now(),
            'updated_at' => now(),
        ], $pin));
    }

    return $planId;
}

test('site plan draft can be saved published duplicated and republished', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create([
        'type' => 'house',
        'emergency_plan_location' => null,
        'medication_storage_location' => null,
    ]);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/draft", [
            'layout' => sampleSitePlanLayout(),
            'notes' => 'Initial house plan',
        ])
        ->assertOk()
        ->assertJsonPath('plan.status', 'draft');

    $this->assertDatabaseHas('site_type_plans', [
        'site_id' => $site->id,
        'site_type' => 'house',
        'status' => 'draft',
        'version' => 1,
    ]);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/publish")
        ->assertOk()
        ->assertJsonPath('plan.status', 'published')
        ->assertJsonPath('plan.version', 1);

    $publishedId = DB::table('site_type_plans')
        ->where('site_id', $site->id)
        ->where('status', 'published')
        ->value('id');

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/duplicate-to-draft")
        ->assertOk()
        ->assertJsonPath('plan.status', 'draft');

    $draftId = DB::table('site_type_plans')
        ->where('site_id', $site->id)
        ->where('status', 'draft')
        ->value('id');

    expect($draftId)->not()->toBe($publishedId);

    $this->actingAs($user)
        ->putJson("/sites/{$site->id}/plan/draft", [
            'layout' => sampleSitePlanLayout('Bedroom 2'),
            'notes' => 'Second published version',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/publish")
        ->assertOk()
        ->assertJsonPath('plan.status', 'published')
        ->assertJsonPath('plan.version', 2);

    $this->assertDatabaseHas('site_type_plans', [
        'id' => $publishedId,
        'status' => 'archived',
        'version' => 1,
    ]);

    $this->assertDatabaseHas('site_type_plans', [
        'id' => $draftId,
        'status' => 'published',
        'version' => 2,
    ]);
});

test('plan layout normalisation upgrades legacy layout with schema and export defaults', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);

    $legacyLayout = sampleSitePlanLayout();
    unset($legacyLayout['schema_version']);
    $legacyLayout['doors'] = [
        [
            'id' => 'front-door',
            'x' => 0.2,
            'y' => 0.55,
            'swing' => 'left',
        ],
    ];

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/draft", [
            'layout' => $legacyLayout,
            'notes' => 'Legacy shape should be upgraded',
        ])
        ->assertOk()
        ->assertJsonPath('plan.layout.schema_version', 2)
        ->assertJsonPath('plan.layout.export.paper', 'a4')
        ->assertJsonPath('plan.layout.export.orientation', 'landscape')
        ->assertJsonPath('plan.layout.doors.0.subkind', 'single_swing')
        ->assertJsonPath('plan.layout.doors.0.swing_side', 'left')
        ->assertJsonPath('plan.layout.doors.0.swing_direction', 'in');
});

test('published plan svg breaks walls behind attached doors and windows', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $service = app(SiteTypePlanService::class);

    $plan = $service->storeDraft($site, [
        'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
        'walls' => [
            [
                'id' => 'front-wall',
                'points' => [
                    ['x' => 0.1, 'y' => 0.5],
                    ['x' => 0.9, 'y' => 0.5],
                ],
                'thickness' => 4,
            ],
        ],
        'doors' => [
            [
                'id' => 'front-door',
                'x' => 0.45,
                'y' => 0.5,
                'width' => 0.1,
                'wall_id' => 'front-wall',
                'wall_segment_index' => 0,
                'wall_t' => 0.5,
            ],
        ],
        'windows' => [
            [
                'id' => 'front-window',
                'x' => 0.2,
                'y' => 0.5,
                'width' => 0.08,
                'wall_id' => 'front-wall',
                'wall_segment_index' => 0,
                'wall_t' => 0.25,
            ],
        ],
    ], null, null);

    $published = $service->publishDraft($site, null);
    expect($published)->toBeInstanceOf(SiteTypePlan::class);

    $svg = $service->renderLayoutSvg($published);

    expect($svg)
        ->toContain('x2="260.00"')
        ->toContain('x1="340.00"')
        ->toContain('x2="450.00"')
        ->toContain('x1="550.00"')
        ->toContain('fill="#e0f2fe"');
});

test('pin batch endpoint reconciles draft pins', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/draft", [
            'layout' => sampleSitePlanLayout(),
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                [
                    'kind' => 'medication_storage',
                    'label' => 'Locked medication cabinet',
                    'x' => 0.4,
                    'y' => 0.5,
                    'meta' => ['is_locked' => true],
                ],
                [
                    'kind' => 'assembly_point',
                    'label' => 'Front driveway',
                    'x' => 0.8,
                    'y' => 0.85,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'pins');

    $planId = DB::table('site_type_plans')
        ->where('site_id', $site->id)
        ->where('status', 'draft')
        ->value('id');

    expect(DB::table('site_type_plan_pins')->where('site_type_plan_id', $planId)->count())->toBe(2);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                [
                    'kind' => 'emergency_exit',
                    'label' => 'Kitchen exit',
                    'x' => 0.2,
                    'y' => 0.9,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'pins');

    $this->assertDatabaseMissing('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'medication_storage',
    ]);

    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'emergency_exit',
        'label' => 'Kitchen exit',
    ]);
});

test('emergency mode pin replacement preserves non emergency pins on draft', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);
    $planId = createSiteTypePlanForTest($site, 'draft', [
        ['kind' => 'medication_storage', 'label' => 'Medication safe', 'x' => 0.2, 'y' => 0.3],
        ['kind' => 'device', 'label' => 'Front camera', 'x' => 0.3, 'y' => 0.4],
        ['kind' => 'assembly_point', 'label' => 'Old assembly', 'x' => 0.8, 'y' => 0.8],
    ]);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'mode' => 'emergency',
            'replace' => true,
            'pins' => [
                ['kind' => 'emergency_exit', 'label' => 'Kitchen exit', 'x' => 0.15, 'y' => 0.92],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(3, 'pins');

    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'medication_storage',
        'label' => 'Medication safe',
    ]);
    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'device',
        'label' => 'Front camera',
    ]);
    $this->assertDatabaseMissing('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'assembly_point',
        'label' => 'Old assembly',
    ]);
    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'emergency_exit',
        'label' => 'Kitchen exit',
    ]);
});

test('emergency mode pin replacement clones published plan before replacing emergency pins', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);
    $publishedId = createSiteTypePlanForTest($site, 'published', [
        ['kind' => 'medication_storage', 'label' => 'Published medication safe', 'x' => 0.2, 'y' => 0.3],
        ['kind' => 'assembly_point', 'label' => 'Published assembly', 'x' => 0.8, 'y' => 0.8],
    ]);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'mode' => 'emergency',
            'replace' => true,
            'pins' => [
                ['kind' => 'emergency_exit', 'label' => 'Draft exit', 'x' => 0.15, 'y' => 0.92],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('typePlan.status', 'draft_over_published');

    $draftId = DB::table('site_type_plans')
        ->where('site_id', $site->id)
        ->where('status', 'draft')
        ->value('id');

    expect($draftId)->not()->toBeNull()->and($draftId)->not()->toBe($publishedId);

    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $publishedId,
        'kind' => 'assembly_point',
        'label' => 'Published assembly',
    ]);
    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $draftId,
        'kind' => 'medication_storage',
        'label' => 'Published medication safe',
    ]);
    $this->assertDatabaseMissing('site_type_plan_pins', [
        'site_type_plan_id' => $draftId,
        'kind' => 'assembly_point',
        'label' => 'Published assembly',
    ]);
    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $draftId,
        'kind' => 'emergency_exit',
        'label' => 'Draft exit',
    ]);
});

test('emergency mode pin replacement rejects non emergency kinds without altering pins', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);
    $planId = createSiteTypePlanForTest($site, 'draft', [
        ['kind' => 'medication_storage', 'label' => 'Medication safe', 'x' => 0.2, 'y' => 0.3],
        ['kind' => 'assembly_point', 'label' => 'Assembly', 'x' => 0.8, 'y' => 0.8],
    ]);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'mode' => 'emergency',
            'replace' => true,
            'pins' => [
                ['kind' => 'device', 'label' => 'Not allowed', 'x' => 0.15, 'y' => 0.92],
            ],
        ])
        ->assertUnprocessable();

    expect(DB::table('site_type_plan_pins')->where('site_type_plan_id', $planId)->count())->toBe(2);
    $this->assertDatabaseHas('site_type_plan_pins', [
        'site_type_plan_id' => $planId,
        'kind' => 'assembly_point',
        'label' => 'Assembly',
    ]);
});

test('pin validation accepts only taxonomy allowed subkinds', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);
    createSiteTypePlanForTest($site);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                ['kind' => 'fire_extinguisher', 'subkind' => 'co2', 'label' => 'CO2', 'x' => 0.2, 'y' => 0.3],
            ],
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                ['kind' => 'smoke_alarm', 'subkind' => 'co2', 'label' => 'Bad subkind', 'x' => 0.2, 'y' => 0.3],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pins.0.subkind');
});

test('device pin validation requires a current assignment to the site', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);
    $otherSite = Site::factory()->create(['type' => 'house']);
    createSiteTypePlanForTest($site);

    $assignedDevice = Device::factory()->security()->create(['tenant_id' => $site->tenant_id]);
    DeviceAssignment::query()->create([
        'device_id' => $assignedDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assigned_at' => now(),
    ]);

    $otherDevice = Device::factory()->security()->create(['tenant_id' => $site->tenant_id]);
    DeviceAssignment::query()->create([
        'device_id' => $otherDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $otherSite->id,
        'assigned_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                ['kind' => 'device', 'device_id' => $assignedDevice->id, 'label' => 'Assigned', 'x' => 0.2, 'y' => 0.3],
            ],
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                ['kind' => 'device', 'device_id' => $otherDevice->id, 'label' => 'Other site', 'x' => 0.2, 'y' => 0.3],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pins.0.device_id');

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                ['kind' => 'smoke_alarm', 'device_id' => $assignedDevice->id, 'label' => 'Not device', 'x' => 0.2, 'y' => 0.3],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pins.0.device_id');
});

test('path points are validated for evacuation routes only', function () {
    $user = siteTypePlanUser();
    $site = Site::factory()->create(['type' => 'house']);
    createSiteTypePlanForTest($site);

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                [
                    'kind' => 'evacuation_route',
                    'label' => 'Route',
                    'x' => 0.4,
                    'y' => 0.4,
                    'path_points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.9]],
                ],
            ],
        ])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                [
                    'kind' => 'evacuation_route',
                    'label' => 'Too short',
                    'x' => 0.4,
                    'y' => 0.4,
                    'path_points' => [['x' => 0.1, 'y' => 0.1]],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pins.0.path_points');

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/pins", [
            'replace' => true,
            'pins' => [
                [
                    'kind' => 'smoke_alarm',
                    'label' => 'Bad points',
                    'x' => 0.4,
                    'y' => 0.4,
                    'path_points' => [['x' => 0.1, 'y' => 0.1], ['x' => 0.9, 'y' => 0.9]],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('pins.0.path_points');
});

test('plan write routes require site update permission', function () {
    $user = User::factory()->create(['approved_at' => now()]);
    $site = Site::factory()->create();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/draft", [
            'layout' => sampleSitePlanLayout(),
        ])
        ->assertForbidden();
});
