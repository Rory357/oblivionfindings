<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function siteEmergencyPlanUser(): User
{
    $user = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', 'admin')->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

function publishedPlanForSite(Site $site, array $pins = []): int
{
    $planId = DB::table('site_type_plans')->insertGetId([
        'tenant_id' => $site->tenant_id,
        'site_id' => $site->id,
        'site_type' => $site->type,
        'status' => 'published',
        'version' => 1,
        'layout' => json_encode([
            'schema_version' => 1,
            'canvas' => ['width' => 1000, 'height' => 700, 'unit' => 'rel'],
            'rooms' => [
                [
                    'id' => 'living',
                    'label' => 'Living',
                    'shape' => 'rect',
                    'x' => 0.08,
                    'y' => 0.12,
                    'width' => 0.32,
                    'height' => 0.28,
                ],
            ],
            'walls' => [],
            'doors' => [],
            'windows' => [],
            'labels' => [],
        ]),
        'published_at' => now(),
        'published_by_user_id' => null,
        'created_by_user_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ($pins as $pin) {
        DB::table('site_type_plan_pins')->insert(array_merge([
            'tenant_id' => $site->tenant_id,
            'site_type_plan_id' => $planId,
            'kind' => 'custom_marker',
            'label' => 'Marker',
            'x' => 0.5,
            'y' => 0.5,
            'rotation_deg' => 0,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $pin));
    }

    return $planId;
}

test('emergency plan page 404s without a published plan', function () {
    $user = siteEmergencyPlanUser();
    $site = Site::factory()->create(['type' => 'house']);

    $this->actingAs($user)
        ->get("/sites/{$site->id}/emergency-plan")
        ->assertNotFound();
});

test('emergency plan pdf renders for supported paper sizes when plan is ready', function () {
    $user = siteEmergencyPlanUser();
    $site = Site::factory()->create([
        'name' => 'Kauri House',
        'type' => 'house',
        'phone' => '09 555 0100',
        'address_line_1' => '12 Kauri Street',
        'city' => 'Auckland',
    ]);

    SiteContact::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'On Call',
        'type' => 'emergency',
        'phone' => '021 000 111',
        'is_primary' => true,
    ]);

    publishedPlanForSite($site, [
        ['kind' => 'assembly_point', 'label' => 'Front driveway', 'x' => 0.82, 'y' => 0.88],
        ['kind' => 'emergency_exit', 'label' => 'Kitchen exit', 'x' => 0.18, 'y' => 0.92],
    ]);

    foreach (['a3', 'a4', 'a5'] as $paper) {
        $this->actingAs($user)
            ->get("/sites/{$site->id}/emergency-plan.pdf?paper={$paper}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
});

test('emergency plan pdf refuses export until assembly point and exit exist', function () {
    $user = siteEmergencyPlanUser();
    $site = Site::factory()->create(['type' => 'house']);

    publishedPlanForSite($site, [
        ['kind' => 'assembly_point', 'label' => 'Car park', 'x' => 0.8, 'y' => 0.8],
    ]);

    $this->actingAs($user)
        ->get("/sites/{$site->id}/emergency-plan.pdf?paper=a4")
        ->assertStatus(409);
});

test('emergency contacts payload includes site contacts and NZ 111 line', function () {
    $user = siteEmergencyPlanUser();
    $site = Site::factory()->create(['type' => 'house']);

    SiteContact::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'name' => 'Site Lead',
        'type' => 'site_lead',
        'phone' => '021 222 333',
    ]);

    publishedPlanForSite($site, [
        ['kind' => 'assembly_point', 'label' => 'Mailbox', 'x' => 0.8, 'y' => 0.8],
        ['kind' => 'emergency_exit', 'label' => 'Front door', 'x' => 0.1, 'y' => 0.9],
    ]);

    $this->actingAs($user)
        ->get("/sites/{$site->id}/emergency-plan")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/emergency-plan/index')
            ->where('contacts.0.name', 'Emergency services')
            ->where('contacts.0.phone', '111')
            ->where('contacts.1.name', 'Site Lead')
        );
});

