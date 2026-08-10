<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->recipient = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->recipient->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $this->site = Site::factory()->create([
        'name' => 'Recognition Dialog Site',
    ]);

    foreach ([$this->hr, $this->recipient] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
    }
});

test('the Give-recognition modal payload creates a kudos and a linked feed post', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->recipient->id,
            'category' => 'innovation',
            'message' => 'Brilliant idea on the handover process.',
        ])
        ->assertRedirect();

    $kudos = HrKudos::query()
        ->where('to_user_id', $this->recipient->id)
        ->where('category', 'innovation')
        ->first();

    expect($kudos)->not->toBeNull();
    expect($kudos->message)->toBe('Brilliant idea on the handover process.');
    expect($kudos->feed_post_id)->not->toBeNull();

    // The kudos also surfaces on the community feed as a kudos post.
    $this->assertDatabaseHas('hr_feed_posts', [
        'id' => $kudos->feed_post_id,
        'post_type' => 'kudos',
    ]);
});

test('a kudos category outside the allowed set is rejected', function () {
    $this->actingAs($this->hr)
        ->post('/hr/feed/kudos', [
            'to_user_id' => $this->recipient->id,
            'category' => 'not_a_real_category',
            'message' => 'x',
        ])
        ->assertSessionHasErrors('category');
});
