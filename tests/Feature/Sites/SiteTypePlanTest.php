<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
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

test('plan write routes require site update permission', function () {
    $user = User::factory()->create(['approved_at' => now()]);
    $site = Site::factory()->create();

    $this->actingAs($user)
        ->postJson("/sites/{$site->id}/plan/draft", [
            'layout' => sampleSitePlanLayout(),
        ])
        ->assertForbidden();
});

