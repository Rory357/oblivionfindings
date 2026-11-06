<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Site;
use App\Models\User;
use App\Support\SecurityPolicy;
use Inertia\Testing\AssertableInertia as Assert;

// The browser starts Oblivion Live only when the page says it is on (resources/js/app.tsx).
// While it is off, asking for a ticket would only 404 on every page view, and Chat polls anyway.

beforeEach(function () {
    config(['chat.shared_experience' => true, 'chat.live' => [
        'enabled' => false, 'path' => '/chat-live', 'internal_url' => 'http://127.0.0.1:8092',
        'secret' => 'chat-live-flag-test-secret-not-for-deployment', 'key_id' => 'k-test',
        'previous_secret' => null, 'previous_key_id' => null, 'allowed_origins' => [],
    ]]);
    SecurityPolicy::flushCache();
});

function liveFlagStaff(): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => Site::factory()->create()->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);

    return $user->fresh();
}

it('tells the page Live is off, so the browser asks for no ticket', function () {
    $staff = liveFlagStaff();

    $this->actingAs($staff)->get(route('chat.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chatSharedExperience', true)->where('chatLive', false));
    // A ticket request made anyway still answers 404 while Live is off (spec section 3).
    $this->actingAs($staff)->withHeader('X-Chat-Actor', (string) $staff->id)
        ->postJson(route('chat.live.ticket'))->assertNotFound();
});

it('tells the page Live is on once it is switched on and fully configured', function () {
    config(['chat.live.enabled' => true]);

    $this->actingAs(liveFlagStaff())->get(route('chat.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chatLive', true));
});

it('keeps Live off on the page when it is switched on but missing its secret', function () {
    config(['chat.live.enabled' => true, 'chat.live.secret' => null]);

    $this->actingAs(liveFlagStaff())->get(route('chat.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('chatLive', false));
});

it('keeps Live off on the page when shared Chat is off', function () {
    config(['chat.shared_experience' => false, 'chat.live.enabled' => true]);

    $this->actingAs(liveFlagStaff())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('chatLive', false));
});
