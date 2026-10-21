<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Services\Chat\ChatMessageDelivery;
use App\Services\Chat\ChatNotificationPreferences;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->person = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    $this->role = Role::create(['name' => 'chat_preferences_'.Str::random(8), 'label' => 'Chat preferences', 'level' => 50, 'type' => 'custom']);
    $this->person->roles()->attach($this->role->id);
    $this->enabled = ['enabled' => true, 'inapp' => true, 'email' => false, 'push' => false];
});

it('shows Chat preferences and agrees with dispatch when disabled and enabled roles disagree', function () {
    $second = Role::create(['name' => 'chat_disabled_'.Str::random(8), 'label' => 'Disabled role', 'level' => 50, 'type' => 'custom']);
    $this->person->roles()->attach($second->id);
    RoleNotificationPreference::create(['role_id' => $this->role->id, 'key' => 'chat.message.received', 'enabled' => true, 'channel_inapp' => false]);
    RoleNotificationPreference::create(['role_id' => $second->id, 'key' => 'chat.message.received', 'enabled' => false, 'channel_inapp' => true]);
    $this->actingAs($this->person)->get(route('settings.notifications'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/notifications')->where('groups.Chat', ChatNotificationPreferences::EVENTS)
        ->where('roleDefaults', fn ($defaults) => $defaults['chat.message.received']['enabled'] && ! $defaults['chat.message.received']['inapp']));
    expect(app(ChatMessageDelivery::class)->inAppEnabled($this->person, 'chat.message.received'))->toBeFalse();
    $this->putJson(route('settings.notifications.update'), ['prefs' => ['chat.message.received' => $this->enabled]])->assertRedirect();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($this->person, 'chat.message.received'))->toBeTrue();
    $this->putJson(route('settings.notifications.update'), ['prefs' => ['chat.message.received' => null]])->assertRedirect();
    expect(UserNotificationPreference::where('user_id', $this->person->id)->where('key', 'chat.message.received')->exists())->toBeFalse();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($this->person, 'chat.message.received'))->toBeFalse();
    RoleNotificationPreference::where('role_id', $this->role->id)->update(['channel_inapp' => true]);
    expect(app(ChatMessageDelivery::class)->inAppEnabled($this->person, 'chat.message.received'))->toBeTrue();
});

it('keeps Chat overrides personal and rejects unavailable transports and unknown events atomically', function () {
    $other = User::factory()->create();
    UserNotificationPreference::create(['user_id' => $other->id, 'key' => 'chat.mentioned', 'enabled' => false, 'channel_inapp' => false]);
    $this->actingAs($this->person)->putJson(route('settings.notifications.update'), ['prefs' => [
        'chat.message.received' => false, 'chat.mentioned' => [...$this->enabled, 'push' => true],
    ]])->assertUnprocessable();
    expect(UserNotificationPreference::where('user_id', $this->person->id)->count())->toBe(0);
    $this->putJson(route('settings.notifications.update'), ['prefs' => ['chat.family.reply' => [...$this->enabled, 'email' => true]]])->assertUnprocessable();
    $this->putJson(route('settings.notifications.update'), ['prefs' => ['chat.unsupported' => $this->enabled]])->assertUnprocessable();
    $this->putJson(route('settings.notifications.update'), ['user_id' => $other->id, 'prefs' => ['chat.mentioned' => $this->enabled]])->assertRedirect();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($this->person, 'chat.mentioned'))->toBeTrue();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($other, 'chat.mentioned'))->toBeFalse();
});

it('requires settings authority for Chat role defaults and rejects partial saves', function () {
    $this->actingAs($this->person)->putJson(route('settings.notifications.roles.update'), ['matrix' => [$this->role->id => ['chat.mentioned' => false]]])->assertForbidden();
    $permission = Permission::firstOrCreate(['key' => 'settings.access.manage'], ['description' => 'Manage settings', 'group' => 'settings', 'module' => 'Settings']);
    $this->role->permissions()->attach($permission->id);
    $this->actingAs($this->person->fresh())->putJson(route('settings.notifications.roles.update'), ['matrix' => [$this->role->id => [
        'chat.message.received' => false, 'chat.mentioned' => [...$this->enabled, 'push' => true],
    ]]])->assertUnprocessable();
    expect(RoleNotificationPreference::where('role_id', $this->role->id)->count())->toBe(0);
    $this->putJson(route('settings.notifications.roles.update'), ['matrix' => [$this->role->id => ['chat.mentioned' => false]]])->assertRedirect();
    expect(app(ChatMessageDelivery::class)->inAppEnabled($this->person, 'chat.mentioned'))->toBeFalse();
    $this->get(route('settings.notifications.roles'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/notification-defaults')->where('groups.Chat', ChatNotificationPreferences::EVENTS));
});
