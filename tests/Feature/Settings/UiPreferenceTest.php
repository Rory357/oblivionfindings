<?php

use App\Models\User;
use App\Models\UserUiPreference;

test('ui preference updates require authentication', function () {
    $this->put('/settings/ui-preferences/sites.profile.pinned-tabs', [
        'value' => ['hazards'],
    ])->assertRedirect('/login');
});

test('an authenticated user can persist an array preference', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/sites/10')
        ->put('/settings/ui-preferences/sites.profile.pinned-tabs', [
            'value' => ['hazards', 'documents'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/sites/10');

    expect(
        UserUiPreference::query()
            ->whereBelongsTo($user)
            ->where('key', 'sites.profile.pinned-tabs')
            ->sole()
            ->value,
    )->toBe(['hazards', 'documents']);
});

test('saving the same key updates rather than duplicates', function () {
    $user = User::factory()->create();

    UserUiPreference::query()->create([
        'user_id' => $user->id,
        'key' => 'sites.profile.pinned-tabs',
        'value' => ['hazards'],
    ]);

    $this->actingAs($user)
        ->put('/settings/ui-preferences/sites.profile.pinned-tabs', [
            'value' => ['documents'],
        ])
        ->assertSessionHasNoErrors();

    expect(
        UserUiPreference::query()
            ->whereBelongsTo($user)
            ->where('key', 'sites.profile.pinned-tabs')
            ->count(),
    )->toBe(1);
    expect(
        UserUiPreference::query()
            ->whereBelongsTo($user)
            ->where('key', 'sites.profile.pinned-tabs')
            ->sole()
            ->value,
    )->toBe(['documents']);
});

test('preference keys and values are strictly validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/ui-preferences/Sites Profile', [
            'value' => ['hazards'],
        ])
        ->assertSessionHasErrors('key');

    $this->actingAs($user)
        ->put('/settings/ui-preferences/sites.profile.pinned-tabs', [
            'value' => 'hazards',
        ])
        ->assertSessionHasErrors('value');

    $this->assertDatabaseCount('user_ui_preferences', 0);
});

test('one user never updates another users preference', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    UserUiPreference::query()->create([
        'user_id' => $owner->id,
        'key' => 'sites.profile.pinned-tabs',
        'value' => ['hazards'],
    ]);

    $this->actingAs($otherUser)
        ->put('/settings/ui-preferences/sites.profile.pinned-tabs', [
            'value' => ['documents'],
        ])
        ->assertSessionHasNoErrors();

    expect(
        UserUiPreference::query()
            ->whereBelongsTo($owner)
            ->where('key', 'sites.profile.pinned-tabs')
            ->sole()
            ->value,
    )->toBe(['hazards']);
    expect(
        UserUiPreference::query()
            ->whereBelongsTo($otherUser)
            ->where('key', 'sites.profile.pinned-tabs')
            ->sole()
            ->value,
    )->toBe(['documents']);
});
