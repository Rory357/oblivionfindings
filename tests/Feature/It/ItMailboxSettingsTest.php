<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\Services\ItMailboxPollState;
use App\Http\Controllers\Settings\ItMailboxSettingsController;
use App\Http\Requests\Settings\UpdateItMailboxRequest;
use App\Jobs\PollItMailboxJob;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;

/*
 * E6a — the support-mailbox connect/disconnect backend (mirrors the
 * calendar-sync OAuth flow; gated on the existing connection-management permission).
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
    Http::preventStrayRequests();
    // These backend feature tests render the Inertia shell without contacting an SSR process.
    config(['inertia.ssr.enabled' => false]);
    $this->seed(RbacSeeder::class);
    $this->admin = itMailboxSettingsUser('admin');
    $this->worker = itMailboxSettingsUser('support_worker');
});

test('the delegated mailbox mutation uses its dedicated form request', function () {
    $parameter = (new ReflectionMethod(ItMailboxSettingsController::class, 'updateMailbox'))
        ->getParameters()[0];

    expect($parameter->getType()?->getName())->toBe(UpdateItMailboxRequest::class);
});

test('local attachment failures expose safe recovery guidance without requiring new provider authorization', function (string $code, string $guidance) {
    $connection = itSettingsConnection(['status' => 'error']);
    $connection->forceFill(['last_poll_failure_code' => $code, 'last_error' => 'PRIVATE scanner path or provider secret',
        'next_poll_at' => now()->addMinute()])->save();
    $this->actingAs($this->admin)->get('/settings/it-mailbox')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.microsoft.last_error', fn ($message) => str_contains($message, $guidance) && ! str_contains($message, 'PRIVATE'))
            ->where('connections.microsoft.authorization_required', false)
            ->where('connections.microsoft.can_poll', false));
    $this->travel(61)->seconds();
    $this->get('/settings/it-mailbox')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('connections.microsoft.can_poll', true));
    $this->actingAs($this->worker)->get('/settings/it-mailbox')->assertForbidden();
})->with([
    ['scanner_unavailable', 'Check the scanner service'],
    ['scanner_failed', 'Check the scanner service'],
    ['scanner_timeout', 'Check the scanner service'],
    ['attachment_storage_unavailable', 'Check private storage'],
    ['attachment_cleanup_pending', 'temporary file cleanup is pending'],
]);

function itSettingsConnection(array $overrides = []): ItMailboxConnection
{
    return ItMailboxConnection::create(array_merge([
        'provider' => ItMailboxConnection::PROVIDER_MICROSOFT,
        'status' => ItMailboxConnection::STATUS_CONNECTED,
        'access_token' => 'access-123',
        'refresh_token' => 'refresh-456',
        'token_expires_at' => now()->addHour(),
        'account_email' => 'admin@example.test',
    ], $overrides));
}

function itSettingsCommand(ItMailboxConnection $connection, array $fields = []): array
{
    $connection->refresh();

    return ['connection_id' => $connection->id, 'expected_version' => $connection->configuration_version, ...$fields];
}

function itSettingsAllowMailboxRead(): void
{
    Http::fake(['graph.microsoft.com/*' => Http::response(['value' => []])]);
}

test('the mailbox settings surface is admin-gated', function () {
    $this->actingAs($this->worker)->get('/settings/it-mailbox')->assertForbidden();
    $this->actingAs($this->worker)
        ->put('/settings/it-mailbox/mailbox/microsoft', ['mailbox_email' => 'support@example.test'])
        ->assertForbidden();
    $this->actingAs($this->worker)->post('/settings/it-mailbox/poll-now')->assertForbidden();
    $this->actingAs($this->worker)->get('/settings/it-mailbox/connect/microsoft')->assertForbidden();

    $this->withoutExceptionHandling();
    $this->actingAs($this->admin)
        ->get('/settings/it-mailbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('connections.microsoft')
            ->has('connections.google')
            ->where('connections.microsoft.status', null));
});

test('the support mailbox connection is an application-wide setting', function () {
    $otherSite = Site::factory()->create();
    $otherAdmin = itMailboxSettingsUser('admin');
    HrEmployeeProfile::factory()->create([
        'user_id' => $otherAdmin->id,
        'primary_site_id' => $otherSite->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'created_by' => $otherAdmin->id,
        'updated_by' => $otherAdmin->id,
    ]);
    $connection = itSettingsConnection();
    itSettingsAllowMailboxRead();

    $this->actingAs($otherAdmin)
        ->get('/settings/it-mailbox')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.microsoft.status', ItMailboxConnection::STATUS_CONNECTED)
            ->where('connections.microsoft.account_email', 'admin@example.test'));

    $this->actingAs($otherAdmin)
        ->put('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => 'helpdesk@example.test']))
        ->assertRedirect(route('settings.it-mailbox'));

    expect(ItMailboxConnection::query()->sole()->mailbox_email)->toBe('helpdesk@example.test');
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
    $connection = itSettingsConnection();

    $this->actingAs($this->admin)
        ->delete('/settings/it-mailbox/connect/microsoft', itSettingsCommand($connection))
        ->assertRedirect(route('settings.it-mailbox'));

    expect(ItMailboxConnection::query()->count())->toBe(0);
});

test('the delegated support mailbox can be set, validated and cleared', function () {
    $connection = itSettingsConnection();
    itSettingsAllowMailboxRead();

    $this->actingAs($this->admin)
        ->put('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => 'support@example.test']))
        ->assertRedirect(route('settings.it-mailbox'));
    expect(ItMailboxConnection::query()->first()->mailbox_email)->toBe('support@example.test');

    $this->actingAs($this->admin)
        ->from('/settings/it-mailbox')
        ->put('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => 'not-an-email']))
        ->assertSessionHasErrors('mailbox_email');

    $this->actingAs($this->admin)
        ->put('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => null]))
        ->assertRedirect(route('settings.it-mailbox'));
    expect(ItMailboxConnection::query()->first()->mailboxEmail())->toBe('admin@example.test');
});

test('setting a mailbox that is no longer connected returns a recoverable conflict', function () {
    $this->actingAs($this->admin)
        ->putJson('/settings/it-mailbox/mailbox/microsoft', ['connection_id' => 999999, 'expected_version' => 1, 'mailbox_email' => 'support@example.test'])
        ->assertConflict();
    Http::assertNothingSent();
});

test('poll-now dispatches the mailbox poller', function () {
    Queue::fake();
    $connection = itSettingsConnection();

    $this->actingAs($this->admin)
        ->post('/settings/it-mailbox/poll-now', itSettingsCommand($connection, ['provider' => 'microsoft']))
        ->assertRedirect(route('settings.it-mailbox'));

    Queue::assertPushed(PollItMailboxJob::class, fn ($job) => $job->connectionId === $connection->id && $job->configurationVersion === 1);
});

test('mailbox address verification waits for active ownership then replaces old success evidence', function () {
    $connection = itSettingsConnection(['last_polled_at' => now()->subHour()]);
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id);
    $input = itSettingsCommand($connection, ['mailbox_email' => 'changed@example.test']);
    $this->actingAs($this->admin)->putJson('/settings/it-mailbox/mailbox/microsoft', $input)->assertConflict();
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
    Http::assertNothingSent();
    $state->pause($claim);
    itSettingsAllowMailboxRead();
    $this->putJson('/settings/it-mailbox/mailbox/microsoft', $input)->assertOk()->assertJsonPath('status', 'saved');
    expect($connection->fresh()->configuration_version)->toBe(2);
    expect($connection->fresh()->last_polled_at)->toBeNull();
    expect($connection->fresh()->poll_claim_token)->toBeNull();
    expect(fn () => $state->complete($claim))->toThrow(ItMailboxPollSuperseded::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'settings.it_mailbox.address_changed', 'user_id' => $this->admin->id]);
});

test('OAuth reconnect clears authentication failure and invalidates stale workers', function () {
    $connection = itSettingsConnection();
    $state = app(ItMailboxPollState::class);
    $claim = $state->claim($connection->id);
    $connection->forceFill(['last_poll_failure_code' => 'authentication', 'status' => 'error'])->save();
    $oauthUser = (new Laravel\Socialite\Two\User)->map(['email' => 'new-approved@example.test', 'name' => 'Synthetic account']);
    $oauthUser->token = 'synthetic-reconnected';
    $oauthUser->refreshToken = 'synthetic-refresh';
    $oauthUser->expiresIn = 3600;
    Socialite::shouldReceive('driver')->with('microsoft')->andReturnSelf();
    Socialite::shouldReceive('redirectUrl')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturn($oauthUser);
    $this->actingAs($this->admin)->get('/settings/it-mailbox/callback/microsoft')->assertRedirect(route('settings.it-mailbox'));
    expect($connection->fresh()->configuration_version)->toBe(2);
    expect($connection->fresh()->last_poll_failure_code)->toBeNull();
    expect($connection->fresh()->access_token)->toBe('synthetic-reconnected');
    expect(fn () => $state->complete($claim))->toThrow(ItMailboxPollSuperseded::class);
    expect($state->claim($connection->id))->not->toBeNull();
});

test('provider denied delegated address cannot change the saved mailbox or expose raw provider errors', function () {
    $connection = itSettingsConnection(['mailbox_email' => 'approved@example.test', 'last_polled_at' => now()->subHour()]);
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => 'PRIVATE provider details'], 403)]);
    $response = $this->actingAs($this->admin)->putJson('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => 'denied@example.test']));
    $response->assertUnprocessable()->assertJsonValidationErrors('mailbox_email');
    expect($response->getContent())->not->toContain('PRIVATE');
    expect($connection->fresh()->mailbox_email)->toBe('approved@example.test');
    expect($connection->fresh()->configuration_version)->toBe(1);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
    expect($connection->fresh()->last_poll_attempt_at)->toBeNull();
    expect($connection->fresh()->poll_claim_token)->toBeNull();
    $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.it_mailbox.address_changed']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'denied%40example.test'));
});

test('stale changes and stale disconnect cannot target a reconnected account', function () {
    $connection = itSettingsConnection();
    $input = itSettingsCommand($connection, ['mailbox_email' => 'candidate@example.test']);
    $connection->forceFill(['configuration_version' => 2, 'account_email' => 'new@example.test'])->save();
    $this->actingAs($this->admin)->putJson('/settings/it-mailbox/mailbox/microsoft', $input)->assertConflict();
    $this->deleteJson('/settings/it-mailbox/connect/microsoft', $input)->assertConflict();
    expect($connection->fresh()->account_email)->toBe('new@example.test');
    expect($connection->fresh()->configuration_version)->toBe(2);
    Http::assertNothingSent();
});

test('Gmail cannot be redirected by a delegated-address payload', function () {
    $connection = itSettingsConnection(['provider' => 'google']);
    $this->actingAs($this->admin)->putJson('/settings/it-mailbox/mailbox/google', itSettingsCommand($connection, ['mailbox_email' => 'other@example.test']))->assertUnprocessable();
    expect($connection->fresh()->mailbox_email)->toBeNull();
    expect($connection->fresh()->configuration_version)->toBe(1);
    Http::assertNothingSent();
});

test('reconnection during provider verification cannot be overwritten or have its replacement lease cleared', function () {
    $connection = itSettingsConnection();
    Http::fake(['graph.microsoft.com/*' => function () use ($connection) {
        $connection->forceFill(['configuration_version' => 2, 'access_token' => 'new-authorization',
            'poll_claim_token' => '00000000-0000-4000-8000-000000000001', 'poll_claim_expires_at' => now()->addMinutes(2)])->save();

        return Http::response(['value' => []]);
    }]);
    $this->actingAs($this->admin)->putJson('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => 'candidate@example.test']))->assertConflict();
    expect($connection->fresh()->mailbox_email)->toBeNull();
    expect($connection->fresh()->access_token)->toBe('new-authorization');
    expect($connection->fresh()->poll_claim_token)->toBe('00000000-0000-4000-8000-000000000001');
});

test('mailbox recovery projection conceals legacy raw failures and reports pending work from canonical records', function () {
    $connection = itSettingsConnection(['status' => 'error', 'last_error' => 'PRIVATE token provider body']);
    $connection->forceFill(['next_poll_at' => now()->addMinute()])->save();
    $receipt = new ItInboundEmail;
    $receipt->forceFill(['from_email' => '', 'status' => 'pending', 'mailbox_scope_hash' => $connection->mailboxScopeHash(),
        'remote_message_id' => 'PRIVATE-remote'])->save();
    $response = $this->actingAs($this->admin)->getJson('/settings/it-mailbox')->assertOk()
        ->assertJsonPath('connections.microsoft.can_poll', false)
        ->assertJsonPath('connections.microsoft.counts.awaiting_processing', 1)
        ->assertJsonPath('connections.microsoft.counts.awaiting_acknowledgement', 0);
    expect($response->getContent())->not->toContain('PRIVATE')->not->toContain('access_token')->not->toContain('refresh_token')->not->toContain('inbox_scan_cursor');
});

test('manual polling respects retry time and binds queued work to the reviewed connection version', function () {
    Queue::fake();
    $connection = itSettingsConnection();
    $connection->forceFill(['next_poll_at' => now()->addMinute()])->save();
    $input = itSettingsCommand($connection, ['provider' => 'microsoft']);
    $this->actingAs($this->admin)->postJson('/settings/it-mailbox/poll-now', $input)->assertConflict();
    Queue::assertNothingPushed();
    $this->travel(61)->seconds();
    $this->postJson('/settings/it-mailbox/poll-now', $input)->assertOk()->assertJsonPath('status', 'requested');
    Queue::assertPushed(PollItMailboxJob::class, fn ($job) => $job->connectionId === $connection->id && $job->configurationVersion === 1);
    $connection->forceFill(['configuration_version' => 2])->save();
    expect(app(ItMailboxPollState::class)->claim($connection->id, 1))->toBeNull();
    expect($connection->fresh()->last_poll_attempt_at)->toBeNull();
});

test('a mailbox verification rate limit preserves configuration and applies its provider delay', function () {
    $connection = itSettingsConnection(['mailbox_email' => 'approved@example.test']);
    Http::fake(['graph.microsoft.com/*' => Http::response([], 429, ['Retry-After' => '180'])]);
    $this->actingAs($this->admin)->putJson('/settings/it-mailbox/mailbox/microsoft', itSettingsCommand($connection, ['mailbox_email' => 'candidate@example.test']))
        ->assertUnprocessable()->assertJsonValidationErrors('mailbox_email');
    expect($connection->fresh()->mailbox_email)->toBe('approved@example.test');
    expect($connection->fresh()->configuration_version)->toBe(1);
    expect($connection->fresh()->poll_claim_token)->toBeNull();
    expect($connection->fresh()->last_poll_attempt_at)->toBeNull();
    expect($connection->fresh()->next_poll_at->timestamp)->toBeGreaterThanOrEqual(now()->addSeconds(179)->timestamp);
});
