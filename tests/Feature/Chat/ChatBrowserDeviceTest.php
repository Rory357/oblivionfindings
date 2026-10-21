<?php

use App\Models\User;
use App\Services\Chat\ChatBrowserPushDelivery;
use Illuminate\Support\Str;

// One organisation: subscription ownership is always the authenticated person,
// including on a browser shared by workers or family members.
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->device = $this->owner->pushSubscriptions()->create([
        'provider' => 'webpush', 'token' => 'https://push.example.test/device',
        'keys' => ['p256dh' => 'browser-key', 'auth' => 'browser-auth'], 'enabled' => true,
    ]);
    $this->payload = [
        'provider' => 'webpush', 'actor_id' => $this->owner->id,
        'token' => $this->device->token,
        'keys' => ['auth' => 'browser-auth', 'p256dh' => 'browser-key'],
    ];
});

it('reports only the current account and exact device without revealing another owner', function () {
    $url = route('settings.notifications.push-subscriptions.status');
    $this->postJson($url, $this->payload)->assertUnauthorized();
    $this->actingAs($this->owner)->postJson($url, $this->payload)
        ->assertOk()->assertExactJson(['enabled' => true, 'chat_ready' => false])->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->other)->postJson($url, [...$this->payload, 'actor_id' => $this->other->id])
        ->assertOk()->assertExactJson(['enabled' => false, 'chat_ready' => false]);
    expect($this->device->fresh()->user_id)->toBe($this->owner->id);
    $this->actingAs($this->owner)->postJson($url, [...$this->payload, 'keys' => ['p256dh' => 'rotated', 'auth' => 'new']])
        ->assertOk()->assertExactJson(['enabled' => false, 'chat_ready' => false]);
    $this->device->update(['enabled' => false]);
    $this->postJson($url, $this->payload)->assertOk()->assertExactJson(['enabled' => false, 'chat_ready' => false]);
});

it('rejects stale account requests before binding or disabling a browser device', function () {
    $this->actingAs($this->other);
    $this->postJson(route('settings.notifications.push-subscriptions.store'), $this->payload)->assertConflict();
    $this->postJson(route('settings.notifications.push-subscriptions.status'), $this->payload)->assertConflict();
    $this->deleteJson(route('settings.notifications.push-subscriptions.destroy'), $this->payload)->assertConflict();
    expect($this->device->fresh()->user_id)->toBe($this->owner->id)
        ->and($this->device->fresh()->enabled)->toBeTrue();
    unset($this->payload['actor_id']);
    $this->postJson(route('settings.notifications.push-subscriptions.store'), $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('actor_id');
});

it('transfers a device only on explicit current-account registration and protects rotated keys', function () {
    $payload = [...$this->payload, 'actor_id' => $this->other->id];
    $this->actingAs($this->other)->postJson(route('settings.notifications.push-subscriptions.store'), $payload)->assertCreated();
    expect($this->device->fresh()->user_id)->toBe($this->other->id);
    $this->actingAs($this->owner)->deleteJson(route('settings.notifications.push-subscriptions.destroy'), $this->payload)->assertNoContent();
    expect($this->device->fresh()->enabled)->toBeTrue();
    $this->device->update(['keys' => ['p256dh' => 'new-key', 'auth' => 'new-auth']]);
    $this->actingAs($this->other)->deleteJson(route('settings.notifications.push-subscriptions.destroy'), $payload)->assertConflict();
    expect($this->device->fresh()->enabled)->toBeTrue();
    $payload['keys'] = $this->device->fresh()->keys;
    $this->deleteJson(route('settings.notifications.push-subscriptions.destroy'), $payload)->assertNoContent();
    expect($this->device->fresh()->enabled)->toBeFalse();
});

it('preserves the existing native subscription API', function () {
    $this->actingAs($this->owner)->postJson(route('settings.notifications.push-subscriptions.store'), [
        'token' => 'ExponentPushToken[fixture]', 'platform' => 'ios', 'chat_protocol_version' => 2,
    ])->assertCreated()->assertJsonPath('provider', 'expo');
    $native = $this->owner->pushSubscriptions()->where('provider', 'expo')->sole();
    expect($native->chat_protocol_version)->toBe(0)->and($native->chat_registration_id)->toBeNull();
    $this->deleteJson(route('settings.notifications.push-subscriptions.destroy'), [
        'token' => 'ExponentPushToken[fixture]',
    ])->assertNoContent();
    expect($this->owner->pushSubscriptions()->where('provider', 'expo')->sole()->enabled)->toBeFalse();
});

it('requires explicit browser upgrade and rotates the binding on every registration', function () {
    $store = route('settings.notifications.push-subscriptions.store');
    $status = route('settings.notifications.push-subscriptions.status');
    $this->actingAs($this->owner)->postJson($store, [...$this->payload, 'chat_protocol_version' => 2])->assertCreated();
    $ready = $this->device->fresh();
    $binding = ChatBrowserPushDelivery::fingerprint($ready);
    expect($ready->chat_protocol_version)->toBe(2)->and(Str::isUuid($ready->chat_registration_id))->toBeTrue();
    $this->postJson($status, $this->payload)->assertExactJson(['enabled' => true, 'chat_ready' => true]);
    expect($this->device->fresh()->chat_registration_id)->toBe($ready->chat_registration_id);
    $this->actingAs($this->other)->postJson($status, [...$this->payload, 'actor_id' => $this->other->id])
        ->assertExactJson(['enabled' => false, 'chat_ready' => false]);
    $this->actingAs($this->owner)->postJson($store, $this->payload)->assertCreated();
    $this->postJson($status, $this->payload)->assertExactJson(['enabled' => true, 'chat_ready' => false]);
    $this->postJson($store, [...$this->payload, 'chat_protocol_version' => 2])->assertCreated();
    expect(ChatBrowserPushDelivery::fingerprint($this->device->fresh()))->not->toBe($binding);
    $binding = ChatBrowserPushDelivery::fingerprint($this->device->fresh());
    $this->postJson($store, [...$this->payload, 'chat_protocol_version' => 2])->assertCreated();
    expect(ChatBrowserPushDelivery::fingerprint($this->device->fresh()))->not->toBe($binding);
});

it('rejects unsupported protocol registrations before changing ownership', function ($version) {
    $before = $this->device->fresh()->getAttributes();
    $this->actingAs($this->other)->postJson(route('settings.notifications.push-subscriptions.store'), [
        ...$this->payload, 'actor_id' => $this->other->id, 'chat_protocol_version' => $version,
    ])->assertUnprocessable()->assertJsonValidationErrors('chat_protocol_version');
    expect($this->device->fresh()->getAttributes())->toBe($before);
})->with([1, 3, null, 'invalid']);
