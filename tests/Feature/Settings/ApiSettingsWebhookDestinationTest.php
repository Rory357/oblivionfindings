<?php

use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

final class ApiSettingsWebhookDnsResolver implements DnsResolver
{
    /** @var array<string, list<list<string>>> */
    private array $answers = [];

    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, list<string>|list<list<string>>> $answers */
    public function __construct(array $answers = [])
    {
        foreach ($answers as $host => $answer) {
            $this->answers[$host] = isset($answer[0]) && is_array($answer[0])
                ? $answer
                : [$answer];
        }
    }

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;

        return array_shift($this->answers[$host]) ?? [];
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    AppSetting::query()->where('key', 'settings.api.webhooks')->delete();
});

function apiSettingsWebhookAdmin(): User
{
    $admin = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    $admin->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'admin')->firstOrFail()->id,
    ]);

    return $admin;
}

/** @param array<string, list<string>|list<list<string>>> $answers */
function bindApiSettingsWebhookDnsResolver(array $answers): ApiSettingsWebhookDnsResolver
{
    $resolver = new ApiSettingsWebhookDnsResolver($answers);
    app()->instance(DnsResolver::class, $resolver);

    return $resolver;
}

function persistApiSettingsWebhook(string $url): string
{
    $id = 'webhook-test-id';
    AppSetting::query()->create([
        'key' => 'settings.api.webhooks',
        'value' => [[
            'id' => $id,
            'url' => $url,
            'events' => ['shift.completed'],
            'status' => 'active',
            'last_delivery' => null,
            'encrypted_secret' => Crypt::encryptString('whsec_test'),
        ]],
    ]);

    return $id;
}

it('rejects unsafe external destinations before persisting them', function (string $url) {
    Http::fake();

    $this->actingAs(apiSettingsWebhookAdmin())
        ->postJson(route('settings.api.webhooks.store'), [
            'url' => $url,
            'events' => ['shift.completed'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(AppSetting::query()->where('key', 'settings.api.webhooks')->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'loopback' => 'https://127.0.0.1/webhook',
    'localhost' => 'https://localhost/webhook',
    'cloud metadata' => 'https://169.254.169.254/latest/meta-data',
    'private network 10.x' => 'https://10.20.30.40/webhook',
    'private network 172.16.x' => 'https://172.16.0.1/webhook',
    'private network 192.168.x' => 'https://192.168.1.1/webhook',
    'loopback IPv6' => 'https://[::1]/webhook',
    'unencrypted transport' => 'http://93.184.216.34/webhook',
    'credential-bearing URL' => 'https://user:secret@93.184.216.34/webhook',
]);

it('canonicalizes an approved external destination before persisting it', function () {
    $resolver = bindApiSettingsWebhookDnsResolver([
        'hooks.example.test' => ['93.184.216.34'],
    ]);

    $this->actingAs(apiSettingsWebhookAdmin())
        ->postJson(route('settings.api.webhooks.store'), [
            'url' => 'https://Hooks.Example.Test.:443/events?kind=shift',
            'events' => ['shift.completed'],
        ])
        ->assertOk()
        ->assertJsonPath('webhook.url', 'https://hooks.example.test/events?kind=shift');

    $records = AppSetting::query()->where('key', 'settings.api.webhooks')->value('value');
    expect($records)->toBeArray()
        ->and($records[0]['url'])->toBe('https://hooks.example.test/events?kind=shift')
        ->and($resolver->calls)->toBe(['hooks.example.test' => 1]);
});

it('re-authorizes a stored destination before sending a test request', function () {
    $id = persistApiSettingsWebhook('https://rebind.example.test/webhook');
    $resolver = bindApiSettingsWebhookDnsResolver([
        'rebind.example.test' => ['127.0.0.1'],
    ]);
    Http::fake();

    $this->actingAs(apiSettingsWebhookAdmin())
        ->postJson(route('settings.api.webhooks.test', $id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Webhook test failed.')
        ->assertJsonValidationErrors('url');

    $records = AppSetting::query()->where('key', 'settings.api.webhooks')->value('value');
    expect($records[0]['last_delivery'])->toBeNull()
        ->and($resolver->calls)->toBe(['rebind.example.test' => 1]);
    Http::assertNothingSent();
});

it('pins an approved destination and re-authorizes a same-host redirect', function () {
    $id = persistApiSettingsWebhook('https://hooks.example.test/start');
    $resolver = bindApiSettingsWebhookDnsResolver([
        'hooks.example.test' => [
            ['93.184.216.34'],
            ['93.184.216.34'],
        ],
    ]);
    Http::fakeSequence()
        ->push('', 307, ['Location' => '/finish'])
        ->push('', 204);

    $this->actingAs(apiSettingsWebhookAdmin())
        ->postJson(route('settings.api.webhooks.test', $id))
        ->assertOk()
        ->assertJsonPath('message', 'Webhook test succeeded.');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://hooks.example.test/start');
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://hooks.example.test/finish');
    expect($resolver->calls)->toBe(['hooks.example.test' => 2]);
});

it('fails closed before following a cross-host redirect', function () {
    $id = persistApiSettingsWebhook('https://hooks.example.test/start');
    $resolver = bindApiSettingsWebhookDnsResolver([
        'hooks.example.test' => ['93.184.216.34'],
    ]);
    Http::fake([
        'https://hooks.example.test/*' => Http::response('', 307, [
            'Location' => 'https://other.example.test/private',
        ]),
    ]);

    $this->actingAs(apiSettingsWebhookAdmin())
        ->postJson(route('settings.api.webhooks.test', $id))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Webhook test failed.');

    Http::assertSentCount(1);
    expect($resolver->calls)->toBe(['hooks.example.test' => 1]);
});

it('rejects loopback localhost even if it matches app.url', function () {
    config()->set('app.url', 'http://localhost');
    config()->set('inertia.ssr.enabled', false);
    Http::fake();

    $this->actingAs(apiSettingsWebhookAdmin())
        ->postJson(route('settings.api.webhooks.store'), [
            'url' => 'http://localhost/',
            'events' => ['shift.completed'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(AppSetting::query()->where('key', 'settings.api.webhooks')->exists())->toBeFalse();
    Http::assertNothingSent();
});
