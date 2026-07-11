<?php

use App\Http\Controllers\Settings\ItMailboxSettingsController;
use App\Http\Requests\Settings\UpdateItMailboxRequest;
use App\Jobs\PollItMailboxJob;
use App\Models\ItMailboxConnection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;

/*
 * E6a — the support-mailbox connect/disconnect backend (mirrors the
 * calendar-sync OAuth flow; gated on integrations.manage_tenant_secrets).
 */

function itMailboxSettingsUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->admin = itMailboxSettingsUser('admin');
    $this->worker = itMailboxSettingsUser('support_worker');
});

test('the delegated mailbox mutation uses its dedicated form request', function () {
    $parameter = (new ReflectionMethod(ItMailboxSettingsController::class, 'updateMailbox'))
        ->getParameters()[0];

    expect($parameter->getType()?->getName())->toBe(UpdateItMailboxRequest::class);
});

function itSettingsConnection(array $overrides = []): ItMailboxConnection
{
    return ItMailboxConnection::create(array_merge([
        'tenant_id' => 1,
        'provider' => ItMailboxConnection::PROVIDER_MICROSOFT,
        'status' => ItMailboxConnection::STATUS_CONNECTED,
        'access_token' => 'access-123',
        'refresh_token' => 'refresh-456',
        'token_expires_at' => now()->addHour(),
        'account_email' => 'admin@example.test',
    ], $overrides));
}

test('the mailbox settings surface is admin-gated', function () {
    $this->actingAs($this->worker)->get('/settings/it-mailbox')->assertForbidden();
    $this->actingAs($this->worker)
        ->put('/settings/it-mailbox/mailbox/microsoft', ['mailbox_email' => 'support@example.test'])
        ->assertForbidden();
    $this->actingAs($this->worker)->post('/settings/it-mailbox/poll-now')->assertForbidden();
    $this->actingAs($this->worker)->get('/settings/it-mailbox/connect/microsoft')->assertForbidden();

    $this->actingAs($this->admin)
        ->get('/settings/it-mailbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections.microsoft')
            ->has('connections.google')
            ->where('connections.microsoft.status', null));
});

test('the OAuth callback stores a connected mailbox row', function () {
    $oauthUser = (new Laravel\Socialite\Two\User)->map([
        'email' => 'admin@example.test',
        'name' => 'Demo Admin',
    ]);
    $oauthUser->token = 'live-access';
    $oauthUser->refreshToken = 'live-refresh';
    $oauthUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('microsoft')->andReturnSelf();
    Socialite::shouldReceive('redirectUrl')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);

    $this->actingAs($this->admin)
        ->get('/settings/it-mailbox/callback/microsoft')
        ->assertRedirect(route('settings.it-mailbox'));

    $connection = ItMailboxConnection::query()->firstWhere('provider', 'microsoft');
    expect($connection)->not->toBeNull();
    expect($connection->status)->toBe(ItMailboxConnection::STATUS_CONNECTED);
    expect($connection->account_email)->toBe('admin@example.test');
    expect($connection->access_token)->toBe('live-access');
    expect($connection->scopes)->toContain('https://graph.microsoft.com/Mail.ReadWrite');
});

test('disconnect deletes the connection row', function () {
    itSettingsConnection();

    $this->actingAs($this->admin)
        ->delete('/settings/it-mailbox/connect/microsoft')
        ->assertRedirect(route('settings.it-mailbox'));

    expect(ItMailboxConnection::query()->count())->toBe(0);
});

test('the delegated support mailbox can be set, validated and cleared', function () {
    itSettingsConnection();

    $this->actingAs($this->admin)
        ->put('/settings/it-mailbox/mailbox/microsoft', ['mailbox_email' => 'support@example.test'])
        ->assertRedirect(route('settings.it-mailbox'));
    expect(ItMailboxConnection::query()->first()->mailbox_email)->toBe('support@example.test');

    $this->actingAs($this->admin)
        ->from('/settings/it-mailbox')
        ->put('/settings/it-mailbox/mailbox/microsoft', ['mailbox_email' => 'not-an-email'])
        ->assertSessionHasErrors('mailbox_email');

    $this->actingAs($this->admin)
        ->put('/settings/it-mailbox/mailbox/microsoft', ['mailbox_email' => null])
        ->assertRedirect(route('settings.it-mailbox'));
    expect(ItMailboxConnection::query()->first()->mailboxEmail())->toBe('admin@example.test');
});

test('setting the mailbox before connecting flashes an error', function () {
    $this->actingAs($this->admin)
        ->put('/settings/it-mailbox/mailbox/microsoft', ['mailbox_email' => 'support@example.test'])
        ->assertRedirect(route('settings.it-mailbox'))
        ->assertSessionHas('error');
});

test('poll-now dispatches the mailbox poller', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/settings/it-mailbox/poll-now')
        ->assertRedirect(route('settings.it-mailbox'));

    Queue::assertPushed(PollItMailboxJob::class);
});
