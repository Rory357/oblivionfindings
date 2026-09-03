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

final class WebhookDestinationTestDnsResolver implements DnsResolver
{
    /** @var array<string, list<string>|list<list<string>>> */
    private array $answers = [];

    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, list<string>|list<list<string>>> $answers */
    public function __construct(array $answers = [])
    {
        $this->answers = $answers;
    }

    public function resolve(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;

        $ans = $this->answers[$host] ?? [];
        if (isset($ans[0]) && is_array($ans[0])) {
            return array_shift($this->answers[$host]) ?? [];
        }

        return $ans;
    }
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    AppSetting::query()->where('key', 'settings.api.webhooks')->delete();
});

function webhookPolicyAdminUser(): User
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
function bindWebhookPolicyDnsResolver(array $answers): WebhookDestinationTestDnsResolver
{
    $resolver = new WebhookDestinationTestDnsResolver($answers);
    app()->instance(DnsResolver::class, $resolver);

    return $resolver;
}

function persistTestWebhookForPolicy(string $url): string
{
    $id = 'webhook-policy-test-id';
    AppSetting::query()->create([
        'key' => 'settings.api.webhooks',
        'value' => [[
            'id' => $id,
            'url' => $url,
            'events' => ['shift.completed'],
            'status' => 'active',
            'last_delivery' => null,
            'encrypted_secret' => Crypt::encryptString('whsec_policy_test'),
        ]],
    ]);

    return $id;
}

it('blocks test webhook pings to loopback, localhost, private RFC 1918, metadata, and IPv6 loopback destinations', function (string $url) {
    Http::fake();

    // 1. Assert test webhook ping endpoint directly blocks the unsafe destination
    $this->actingAs(webhookPolicyAdminUser())
        ->postJson(route('settings.api.webhooks.test.ping'), [
            'url' => $url,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    // 2. Assert parameterized test webhook ping with URL payload also blocks
    $this->postJson(route('settings.api.webhooks.test', 'ping'), [
        'url' => $url,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    // 3. Assert storing the webhook destination is also blocked
    $this->postJson(route('settings.api.webhooks.store'), [
        'url' => $url,
        'events' => ['shift.completed'],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    expect(AppSetting::query()->where('key', 'settings.api.webhooks')->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'loopback IPv4 127.0.0.1' => 'https://127.0.0.1/webhook',
    'loopback IPv4 unencrypted' => 'http://127.0.0.1/webhook',
    'localhost HTTPS' => 'https://localhost/webhook',
    'localhost HTTP' => 'http://localhost/webhook',
    'private RFC 1918 10.0.0.0/8' => 'https://10.0.0.1/webhook',
    'private RFC 1918 10.20.30.40' => 'https://10.20.30.40/webhook',
    'private RFC 1918 172.16.0.0/12 start' => 'https://172.16.0.1/webhook',
    'private RFC 1918 172.16.0.0/12 end' => 'https://172.31.255.1/webhook',
    'private RFC 1918 192.168.0.0/16 start' => 'https://192.168.0.1/webhook',
    'private RFC 1918 192.168.0.0/16 LAN' => 'https://192.168.1.1/webhook',
    'cloud metadata 169.254.169.254' => 'https://169.254.169.254/latest/meta-data',
    'loopback IPv6 ::1' => 'https://[::1]/webhook',
]);

it('blocks test webhook ping on stored webhooks that resolve or rebind to unsafe private addresses', function (string $unsafeIp) {
    $id = persistTestWebhookForPolicy('https://rebind.example.test/webhook');
    $resolver = bindWebhookPolicyDnsResolver([
        'rebind.example.test' => [$unsafeIp],
    ]);
    Http::fake();

    $this->actingAs(webhookPolicyAdminUser())
        ->postJson(route('settings.api.webhooks.test', $id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    $records = AppSetting::query()->where('key', 'settings.api.webhooks')->value('value');
    expect($records[0]['last_delivery'])->toBeNull()
        ->and($resolver->calls)->toBe(['rebind.example.test' => 1]);
    Http::assertNothingSent();
})->with([
    'rebind to 127.0.0.1' => '127.0.0.1',
    'rebind to 10.0.0.1' => '10.0.0.1',
    'rebind to 172.16.0.1' => '172.16.0.1',
    'rebind to 192.168.1.1' => '192.168.1.1',
    'rebind to 169.254.169.254' => '169.254.169.254',
    'rebind to ::1' => '::1',
]);

it('allows test webhook ping and storing for an approved public external HTTPS destination', function () {
    $resolver = bindWebhookPolicyDnsResolver([
        'hooks.example.test' => ['93.184.216.34'],
    ]);
    Http::fake([
        'https://hooks.example.test/*' => Http::response(['received' => true], 200),
    ]);

    $this->actingAs(webhookPolicyAdminUser())
        ->postJson(route('settings.api.webhooks.test.ping'), [
            'url' => 'https://hooks.example.test/events',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Webhook test succeeded.');

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.test/events');
});
