<?php

use App\Models\CalendarSync;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('triggering a per-user calendar sync records last_synced_at and does not write a non-existent column', function () {
    $user = User::factory()->create();
    $sync = CalendarSync::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'sync_direction' => 'push',
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('operations.calendar_sync.trigger', $sync))
        ->assertRedirect();

    expect($sync->refresh()->last_synced_at)->not->toBeNull();
});
