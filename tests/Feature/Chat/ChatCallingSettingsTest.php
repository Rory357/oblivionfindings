<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Chat\ChatRelayCredentials;
use App\Services\Chat\OblivionRelay;
use App\Services\Chat\SelfHostedCalling;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A settings administrator, with the calling permission unless $calling is false. */
function chatCallingAdmin(bool $calling = true): User
{
    $user = User::factory()->create(['role' => 'staff', 'approved_at' => now()]);
    $role = Role::create(['name' => 'chat_calling_'.Str::random(8), 'label' => 'Chat calling', 'level' => 50, 'type' => 'custom']);
    $keys = $calling ? ['settings.access.manage', 'chat.calling.manage'] : ['settings.access.manage'];
    foreach ($keys as $key) {
        $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'chat', 'module' => 'System'])->id);
    }
    $user->roles()->attach($role->id);

    return $user->fresh();
}

function chatCallingRelayValues(): array
{
    return [
        'relay_urls' => ['turn:relay.example.test:3479?transport=udp', 'turns:relay.example.test:443?transport=tcp'],
        'relay_control_urls' => ['http://127.0.0.1:9479'],
        'relay_control_key_id' => 'k2026a',
        'relay_credential_seconds' => 1800,
    ];
}

beforeEach(function () {
    config([
        'chat.relay.urls' => [], 'chat.relay.secret' => '', 'chat.relay.health_url' => '',
        'chat.relay.control.urls' => [], 'chat.relay.control.secret' => '', 'chat.relay.control.key_id' => '',
        'chat.calling.url' => 'wss://calls.server.test', 'chat.calling.api_url' => 'https://calls.server.test',
        'chat.calling.api_key' => 'server-key', 'chat.calling.api_secret' => str_repeat('s', 40),
    ]);
});

it('keeps the calling settings to administrators with the calling permission', function () {
    $settingsOnly = chatCallingAdmin(calling: false);
    $this->actingAs($settingsOnly)->withHeader('X-Chat-Actor', (string) $settingsOnly->id);
    $this->getJson(route('settings.chat.calling'))->assertForbidden();
    $this->putJson(route('settings.chat.calling.update'), ['version' => 0, 'password' => 'password', 'values' => chatCallingRelayValues()])->assertForbidden();
    $this->get(route('settings.chat'))->assertInertia(fn ($page) => $page->where('chatCallingManager', false));
    expect(DB::table('chat_calling_settings')->count())->toBe(0);

    $admin = chatCallingAdmin();
    $this->actingAs($admin)->withHeader('X-Chat-Actor', (string) $admin->id);
    $this->get(route('settings.chat'))->assertInertia(fn ($page) => $page->where('chatCallingManager', true));
    $this->getJson(route('settings.chat.calling'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('version', 0)
        ->assertJsonPath('fields.calling_url', ['on_page' => false, 'value' => null, 'server' => 'wss://calls.server.test'])
        ->assertJsonPath('fields.calling_api_secret', ['on_page' => false, 'on_server' => true])
        ->assertJsonPath('status.relay', false);
});

it('asks for the password, saves encrypted, never returns or audits a key, and drives the relay', function () {
    $admin = chatCallingAdmin();
    $this->actingAs($admin)->withHeader('X-Chat-Actor', (string) $admin->id);
    $relayKey = str_repeat('R', 30).Str::random(34);
    $controlKey = str_repeat('C', 30).Str::random(34);
    $body = ['version' => 0, 'values' => chatCallingRelayValues(), 'secrets' => ['relay_secret' => $relayKey, 'relay_control_secret' => $controlKey]];

    $this->putJson(route('settings.chat.calling.update'), $body)->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->putJson(route('settings.chat.calling.update'), [...$body, 'password' => 'not-the-password'])->assertUnprocessable()
        ->assertJsonPath('message', 'That password isn’t right. Enter the password you sign in with.');
    expect(DB::table('chat_calling_settings')->count())->toBe(0)
        ->and(AuditLog::where('action', 'chat.calling.updated')->count())->toBe(0);

    $response = $this->putJson(route('settings.chat.calling.update'), [...$body, 'password' => 'password'])->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('fields.relay_secret', ['on_page' => true, 'on_server' => false])
        ->assertJsonPath('fields.relay_urls.value', chatCallingRelayValues()['relay_urls'])
        ->assertJsonPath('updated_by', $admin->name)
        ->assertJsonPath('status.relay', true)
        ->assertJsonPath('status.control', true);
    expect($response->getContent())->not->toContain($relayKey)->not->toContain($controlKey);

    $stored = DB::table('chat_calling_settings')->value('settings');
    expect($stored)->not->toContain($relayKey)->not->toContain('relay.example.test');

    $audit = AuditLog::where('action', 'chat.calling.updated')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and(json_encode($audit->meta))->not->toContain($relayKey)->not->toContain($controlKey)
        ->and(collect($audit->meta['changes'])->firstWhere('field', 'relay_secret'))->toBe(['field' => 'relay_secret', 'change' => 'added']);

    // The relay classes read the saved values, without any .env change.
    $relay = app(ChatRelayCredentials::class);
    $entry = $relay->for($admin, (string) Str::uuid());
    expect($relay->configured())->toBeTrue()
        ->and($relay->seconds())->toBe(1800)
        ->and($entry['urls'][0])->toBe('turn:relay.example.test:3479?transport=udp')
        ->and($entry['credential'])->toBe(base64_encode(hash_hmac('sha1', $entry['username'], $relayKey, true)))
        ->and(app(OblivionRelay::class)->controlUrls())->toBe(['http://127.0.0.1:9479']);

    $this->getJson(route('settings.chat.administration.audit'))->assertOk()
        ->assertJsonPath('entries.0.kind', 'calling')
        ->assertJsonPath('entries.0.calling_changes.0.field', 'relay_urls');
    expect($this->getJson(route('settings.chat.administration.audit'))->getContent())->not->toContain($relayKey);
});

it('refuses a stale version, equal relay and control keys, and addresses that are not the relay', function () {
    $admin = chatCallingAdmin();
    $this->actingAs($admin)->withHeader('X-Chat-Actor', (string) $admin->id);
    $key = Str::random(64);
    $put = fn (array $body) => $this->putJson(route('settings.chat.calling.update'), ['version' => 0, 'password' => 'password', 'values' => [], ...$body]);

    $put(['secrets' => ['relay_secret' => $key, 'relay_control_secret' => $key]])->assertUnprocessable()
        ->assertJsonPath('message', 'The relay key and the control key must be different. Generate a new control key.');
    $put(['values' => ['relay_urls' => ['https://relay.example.test']]])->assertUnprocessable()->assertJsonValidationErrors('values.relay_urls.0');
    $put(['values' => ['relay_control_urls' => ['http://203.0.113.5:9479']]])->assertUnprocessable()->assertJsonValidationErrors('values.relay_control_urls.0');
    $put(['values' => ['relay_health_url' => 'http://10.0.0.5:9478']])->assertUnprocessable()->assertJsonValidationErrors('values.relay_health_url');
    $put(['values' => ['calling_url' => 'wss://example.livekit.cloud']])->assertUnprocessable()->assertJsonValidationErrors('values.calling_url');
    $put(['values' => ['relay_credential_seconds' => 7200]])->assertUnprocessable()->assertJsonValidationErrors('values.relay_credential_seconds');
    $put(['secrets' => ['relay_secret' => 'too short']])->assertUnprocessable()->assertJsonValidationErrors('secrets.relay_secret');
    $put(['values' => ['chat_enabled' => true]])->assertUnprocessable()->assertJsonValidationErrors('values');
    expect(DB::table('chat_calling_settings')->count())->toBe(0);

    $put(['values' => ['relay_control_key_id' => 'k2026a']])->assertOk()->assertJsonPath('version', 1);
    $put(['values' => ['relay_control_key_id' => 'k2026b']])->assertConflict()->assertJsonPath('code', 'chat_calling_conflict');
    expect(config('chat.relay.control.key_id'))->toBe('k2026a');
});

it('falls back to the server’s values when a page value is cleared', function () {
    $admin = chatCallingAdmin();
    $this->actingAs($admin)->withHeader('X-Chat-Actor', (string) $admin->id);
    $pageSecret = Str::random(64);

    $this->putJson(route('settings.chat.calling.update'), ['version' => 0, 'password' => 'password',
        'values' => ['calling_url' => 'wss://calls.page.test'], 'secrets' => ['calling_api_secret' => $pageSecret]])->assertOk();
    $calling = app(SelfHostedCalling::class);
    expect($calling->configured())->toBeTrue()
        ->and(config('chat.calling.url'))->toBe('wss://calls.page.test')
        ->and(config('chat.calling.api_secret'))->toBe($pageSecret);

    $this->putJson(route('settings.chat.calling.update'), ['version' => 1, 'password' => 'password',
        'values' => ['calling_url' => null], 'clear_secrets' => ['calling_api_secret']])->assertOk()
        ->assertJsonPath('fields.calling_url.on_page', false)
        ->assertJsonPath('fields.calling_api_secret', ['on_page' => false, 'on_server' => true]);
    app(SelfHostedCalling::class);
    expect(config('chat.calling.url'))->toBe('wss://calls.server.test')
        ->and(config('chat.calling.api_secret'))->toBe(str_repeat('s', 40))
        ->and(collect(AuditLog::where('action', 'chat.calling.updated')->latest('id')->first()->meta['changes'])->firstWhere('field', 'calling_api_secret'))
        ->toBe(['field' => 'calling_api_secret', 'change' => 'removed']);
});
