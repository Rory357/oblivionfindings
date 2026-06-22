<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrFeedPost;
use App\Domain\Hr\Models\HrKudos;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $hrRoleId = Role::query()->where('name', 'hr')->first()->id;

    // Two HR users (both hold hr.recognition.view + give).
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([$hrRoleId]);

    $this->hr2 = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr2->roles()->syncWithoutDetaching([$hrRoleId]);

    $this->r1 = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->r2 = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('multi-recipient kudos creates one kudos and feed post per recipient', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_ids' => [$this->r1->id, $this->r2->id],
            'category' => 'teamwork',
            'impact' => 'impressive',
            'message' => 'Amazing teamwork on the audit.',
        ])
        ->assertRedirect();

    expect(HrKudos::where('from_user_id', $this->hr->id)->count())->toBe(2);
    expect(HrFeedPost::where('post_type', 'kudos')->count())->toBe(2);

    foreach ([$this->r1, $this->r2] as $recipient) {
        $kudos = HrKudos::where('to_user_id', $recipient->id)->first();
        expect($kudos)->not->toBeNull();
        expect($kudos->impact)->toBe('impressive');
        expect($kudos->category)->toBe('teamwork');
        expect($kudos->feed_post_id)->not->toBeNull();
    }
});

test('a single-recipient kudos stays back-compatible and defaults the impact', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->r1->id,
            'category' => 'teamwork',
            'message' => 'Solid shift.',
        ])
        ->assertRedirect();

    expect(HrKudos::where('to_user_id', $this->r1->id)->value('impact'))->toBe('good_job');
});

test('an invalid impact is rejected', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->r1->id,
            'category' => 'teamwork',
            'impact' => 'legendary',
            'message' => 'x',
        ])
        ->assertSessionHasErrors('impact');

    expect(HrKudos::count())->toBe(0);
});

test('reacting to a kudos toggles the reaction on and off', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Nice work.',
    ]);
    $kudos = HrKudos::firstOrFail();

    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertRedirect();
    $this->assertDatabaseHas('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->hr->id,
        'emoji' => 'heart',
    ]);

    // Toggling the same emoji removes it.
    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'heart'])
        ->assertRedirect();
    $this->assertDatabaseMissing('hr_kudos_reactions', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->hr->id,
        'emoji' => 'heart',
    ]);
});

test('an unknown reaction emoji is rejected', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Nice work.',
    ]);
    $kudos = HrKudos::firstOrFail();

    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/react", ['emoji' => 'rocket'])
        ->assertSessionHasErrors('emoji');
});

test('only the giver or receiver can reply to a kudos thread', function () {
    $this->actingAs($this->hr)->post('/hr/feed/kudos', [
        'to_user_id' => $this->r1->id,
        'category' => 'teamwork',
        'message' => 'Nice work.',
    ]);
    $kudos = HrKudos::firstOrFail();

    // The giver (hr) can reply.
    $this->actingAs($this->hr)
        ->post("/hr/feed/kudos/{$kudos->id}/reply", ['body' => 'You earned it!'])
        ->assertRedirect();
    $this->assertDatabaseHas('hr_kudos_replies', [
        'kudos_id' => $kudos->id,
        'user_id' => $this->hr->id,
    ]);

    // A non-participant who still holds the give permission is forbidden.
    $this->actingAs($this->hr2)
        ->post("/hr/feed/kudos/{$kudos->id}/reply", ['body' => 'Me too'])
        ->assertForbidden();
});

test('the employee picker is scoped to the tenant (no cross-tenant leak)', function () {
    // hr + r1 belong to tenant 1; a third employee belongs to tenant 2.
    HrEmployeeProfile::factory()->create(['user_id' => $this->hr->id, 'tenant_id' => 1, 'is_active' => true]);
    HrEmployeeProfile::factory()->create(['user_id' => $this->r1->id, 'tenant_id' => 1, 'is_active' => true]);

    $otherTenantUser = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create(['user_id' => $otherTenantUser->id, 'tenant_id' => 2, 'is_active' => true]);

    $this->actingAs($this->hr)
        ->get('/hr/feed')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->has('employees', 2));
});

test('the feed index exposes the recognition payload shape', function () {
    $this->actingAs($this->hr)
        ->get('/hr/feed')
        ->assertInertia(fn ($page) => $page
            ->component('hr/feed/index')
            ->has('metrics')
            ->has('announcements')
            ->has('leaderboard')
            ->has('kudosImpacts')
            ->has('sites'));
});

test('self-service /hr/my/kudos accepts multiple recipients and an impact', function () {
    $this->actingAs($this->hr)
        ->post('/hr/my/kudos', [
            'to_user_ids' => [$this->r1->id, $this->r2->id],
            'category' => 'going_above',
            'impact' => 'exceptional',
            'message' => 'You both went above and beyond.',
        ])
        ->assertRedirect();

    expect(HrKudos::where('impact', 'exceptional')->count())->toBe(2);
});
