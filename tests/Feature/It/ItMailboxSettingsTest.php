<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Domain\It\Services\ItMailboxPollState;
use App\Http\Controllers\Settings\ItMailboxSettingsController;
use App\Http\Requests\Settings\UpdateItMailboxRequest;
use App\Jobs\PollItMailboxJob;
use App\Mail\MailNotSubmitted;
use App\Mail\SupportMailboxSender;
use App\Models\AppSetting;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\EmailConfiguration;
use App\Services\Medication\BackupDelivery\BackupEmailSender;
use App\Services\Medication\BackupDelivery\BackupMailTransport;
use Database\Seeders\RbacSeeder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Google\Provider as GoogleProvider;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

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
    $oauthUser->approvedScopes = ['https://graph.microsoft.com/Mail.ReadWrite'];

    Socialite::shouldReceive('driver')->with('microsoft')->andReturnSelf();
    Socialite::shouldReceive('scopes')->andReturnSelf();
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
    $oauthUser->approvedScopes = ['https://graph.microsoft.com/Mail.ReadWrite'];
    Socialite::shouldReceive('driver')->with('microsoft')->andReturnSelf();
    Socialite::shouldReceive('scopes')->andReturnSelf();
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

/** Exercise the installed token-response parser with an entirely synthetic Guzzle queue. */
function itSettingsFakeOAuthProvider(string $provider, mixed $granted, array &$history): void
{
    $token = ['access_token' => 'synthetic-consent-access', 'refresh_token' => 'synthetic-consent-refresh', 'expires_in' => 3600];
    if ($granted !== null) {
        $token['scope'] = $granted;
    }
    $identity = $provider === 'microsoft'
        ? ['id' => 'synthetic-user', 'displayName' => 'Synthetic Account', 'userPrincipalName' => 'admin@example.test']
        : ['sub' => 'synthetic-user', 'name' => 'Synthetic Account', 'email' => 'admin@example.test', 'picture' => null];
    $handler = HandlerStack::create(new MockHandler([
        new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode($token, JSON_THROW_ON_ERROR)),
        new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode($identity, JSON_THROW_ON_ERROR)),
    ]));
    $handler->push(Middleware::history($history));
    $client = new GuzzleClient(['handler' => $handler]);
    Socialite::shouldReceive('driver')->once()->with($provider)->andReturnUsing(function () use ($provider, $client) {
        $arguments = [request(), 'synthetic-client', 'synthetic-client-secret', route('settings.it-mailbox.callback', $provider)];
        if ($provider === 'microsoft') {
            $driver = Mockery::mock(MicrosoftProvider::class, $arguments)->makePartial();
            // ID-token/JWKS verification is outside this scope test; token exchange,
            // state verification, identity mapping and approvedScopes parsing remain real.
            $driver->shouldReceive('getRoles')->andReturn([]);
        } else {
            $driver = new GoogleProvider(...$arguments);
        }

        return $driver->setHttpClient($client);
    });
}

test('connection consent requests sending rights without changing existing recorded grants', function (string $provider) {
    config(["services.{$provider}.client_id" => 'synthetic-client', "services.{$provider}.client_secret" => 'synthetic-client-secret']);
    $recorded = $provider === 'microsoft' ? ['Mail.ReadWrite', 'Mail.ReadWrite.Shared'] : ['https://www.googleapis.com/auth/gmail.readonly'];
    $connection = itSettingsConnection(['provider' => $provider, 'scopes' => $recorded])->fresh();
    $before = $connection->getRawOriginal();
    $history = [];
    Socialite::shouldReceive('driver')->once()->with($provider)->andReturnUsing(function () use ($provider, &$history) {
        $class = $provider === 'microsoft' ? MicrosoftProvider::class : GoogleProvider::class;
        $handler = HandlerStack::create(new MockHandler([]));
        $handler->push(Middleware::history($history));

        return (new $class(request(), 'synthetic-client', 'synthetic-secret', route('settings.it-mailbox.callback', $provider)))
            ->setHttpClient(new GuzzleClient(['handler' => $handler]));
    });
    $response = $this->actingAs($this->admin)->get("/settings/it-mailbox/connect/{$provider}")->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['prompt'])->toBe('consent')->and($query['state'])->toBeString()->not->toBe('');
    $scopes = explode(' ', $query['scope']);
    if ($provider === 'microsoft') {
        expect($scopes)->toContain('https://graph.microsoft.com/Mail.ReadWrite', 'https://graph.microsoft.com/Mail.ReadWrite.Shared',
            'https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/Mail.Send.Shared', 'offline_access');
    } else {
        expect($scopes)->toContain('https://www.googleapis.com/auth/gmail.modify')
            ->and($query['access_type'])->toBe('offline');
    }
    expect($connection->fresh()->getRawOriginal())->toBe($before)->and($history)->toBe([]);
    Http::assertNothingSent();
})->with(['microsoft', 'google']);

test('callback records actual provider grants and sending eligibility without a requested-scope fallback',
    function (string $provider, mixed $granted, ?string $mailbox, array $expected, bool $eligible) {
        $existing = itSettingsConnection(['provider' => $provider, 'mailbox_email' => $mailbox, 'scopes' => ['legacy-read-only']]);
        $history = [];
        itSettingsFakeOAuthProvider($provider, $granted, $history);
        $this->actingAs($this->admin)->withSession(['state' => 'synthetic-consent-state'])
            ->get("/settings/it-mailbox/callback/{$provider}?state=synthetic-consent-state&code=synthetic-code")
            ->assertRedirect(route('settings.it-mailbox'))->assertSessionHasNoErrors();
        $connection = $existing->fresh();
        expect($connection->scopes)->toBe($expected)
            ->and($connection->configuration_version)->toBe(2)
            ->and($connection->mailbox_email)->toBe($mailbox)
            ->and($connection->access_token)->toBe('synthetic-consent-access')
            ->and(SupportMailboxSender::configurationIssue($connection, $provider) === null)->toBe($eligible)
            ->and($history)->toHaveCount(2);
        expect($history[0]['request']->getMethod())->toBe('POST')
            ->and($history[1]['request']->getMethod())->toBe('GET')
            ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer synthetic-consent-access');
        parse_str((string) $history[0]['request']->getBody(), $exchange);
        expect($exchange['code'])->toBe('synthetic-code');
        if ($provider === 'microsoft') {
            expect(explode(' ', $exchange['scope']))->toContain('https://graph.microsoft.com/Mail.Send', 'https://graph.microsoft.com/Mail.Send.Shared');
        }
        Http::assertNothingSent();
    })->with([
        'own Microsoft send consent' => ['microsoft', 'Mail.ReadWrite Mail.Send', null, ['Mail.ReadWrite', 'Mail.Send'], true],
        'shared Microsoft send consent' => ['microsoft', 'Mail.ReadWrite.Shared Mail.Send.Shared', 'support@example.test', ['Mail.ReadWrite.Shared', 'Mail.Send.Shared'], true],
        'own consent cannot authorize a shared sender' => ['microsoft', 'Mail.ReadWrite Mail.Send', 'support@example.test', ['Mail.ReadWrite', 'Mail.Send'], false],
        'legacy Microsoft read consent' => ['microsoft', 'Mail.ReadWrite Mail.ReadWrite.Shared', null, ['Mail.ReadWrite', 'Mail.ReadWrite.Shared'], false],
        'missing provider scope evidence' => ['microsoft', null, null, [], false],
        'scope dictionary is not granted-list evidence' => ['microsoft', ['granted' => 'Mail.Send'], null, [], false],
        'nonstring scope is invalid evidence' => ['microsoft', ['Mail.Send', false], null, [], false],
        'control characters are invalid scope evidence' => ['microsoft', ["Mail.Send\nMail.ReadWrite"], null, [], false],
        'Google modify retains sending eligibility' => ['google', 'openid https://www.googleapis.com/auth/gmail.modify', null, ['openid', 'https://www.googleapis.com/auth/gmail.modify'], true],
        'Google read only remains ineligible' => ['google', 'https://www.googleapis.com/auth/gmail.readonly', null, ['https://www.googleapis.com/auth/gmail.readonly'], false],
    ]);

test('Microsoft reconnect requires central mailbox reselection before protected backup submission', function (?string $mailbox, string $grant) {
    config(['mail.default' => 'smtp', 'emar-catalogue-backups.send_enabled' => true]);
    $connection = itSettingsConnection(['mailbox_email' => $mailbox, 'scopes' => ['Mail.ReadWrite', 'Mail.ReadWrite.Shared']])->fresh();
    AppSetting::create(['key' => EmailConfiguration::KEY, 'value' => [
        'configuration_version' => 1, 'provider' => 'microsoft', 'from_name' => 'Approved Sender', 'from_address' => 'ignored@example.test',
        'it_support' => ['enabled' => false, 'connection_id' => $connection->id, 'connection_version' => 1, 'connection_scope_hash' => $connection->mailboxScopeHash()],
    ]]);
    expect(app(BackupEmailSender::class)->readiness()['email_ready'])->toBeFalse();
    $history = [];
    itSettingsFakeOAuthProvider('microsoft', $grant, $history);
    $this->actingAs($this->admin)->withSession(['state' => 'synthetic-consent-state'])
        ->get('/settings/it-mailbox/callback/microsoft?state=synthetic-consent-state&code=synthetic-code')
        ->assertRedirect(route('settings.it-mailbox'))->assertSessionHasNoErrors();
    $connection->refresh();
    expect($connection->configuration_version)->toBe(2)
        ->and($connection->mailbox_email)->toBe($mailbox)
        ->and(SupportMailboxSender::configurationIssue($connection, 'microsoft'))->toBeNull()
        ->and(app(BackupEmailSender::class)->readiness()['email_ready'])->toBeFalse();
    expect(fn () => app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED-SYNTHETIC', '2026-10-07'))
        ->toThrow(MailNotSubmitted::class);
    Http::assertNothingSent();

    $this->putJson('/settings/email', [
        'expected_actor_id' => $this->admin->id, 'expected_version' => 1, 'provider' => 'microsoft', 'smtp_host' => null,
        'smtp_port' => 587, 'smtp_encryption' => 'tls', 'smtp_username' => null, 'smtp_password' => null, 'clear_smtp_password' => false,
        'from_address' => null, 'from_name' => 'Approved Sender', 'support_enabled' => false,
        'support_connection_id' => $connection->id, 'support_connection_version' => 2, 'public_reply_mode' => 'link_only',
    ])->assertOk();
    expect(app(BackupEmailSender::class)->readiness()['email_ready'])->toBeTrue();
    Http::fake(['graph.microsoft.com/*' => Http::response([], 202)]);
    app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED-SYNTHETIC', '2026-10-07');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/me/sendMail')
        && str_contains(base64_decode($request->body(), true), 'From: Approved Sender <'.$connection->mailboxEmail().'>'));
    expect($history)->toHaveCount(2);
})->with([
    'connected account' => [null, 'Mail.ReadWrite Mail.Send'],
    'approved shared mailbox' => ['support@example.test', 'Mail.ReadWrite.Shared Mail.Send.Shared'],
]);

test('OAuth state mismatch cannot persist token or consent evidence', function () {
    $connection = itSettingsConnection(['scopes' => ['Mail.ReadWrite']])->fresh();
    $before = $connection->getRawOriginal();
    $history = [];
    itSettingsFakeOAuthProvider('microsoft', 'Mail.Send', $history);
    $this->actingAs($this->admin)->withSession(['state' => 'synthetic-approved-state'])
        ->get('/settings/it-mailbox/callback/microsoft?state=wrong-state&code=synthetic-code')
        ->assertRedirect(route('settings.it-mailbox'))->assertSessionHasErrors('microsoft');
    expect($connection->fresh()->getRawOriginal())->toBe($before)->and($history)->toBe([]);
    Http::assertNothingSent();
});

test('callback rechecks secret management permission after the provider returns', function () {
    $connection = itSettingsConnection(['scopes' => ['Mail.ReadWrite']])->fresh();
    $before = $connection->getRawOriginal();
    $oauthUser = (new Laravel\Socialite\Two\User)->map(['email' => 'admin@example.test', 'name' => 'Synthetic Account']);
    $oauthUser->token = 'synthetic-access';
    $oauthUser->refreshToken = 'synthetic-refresh';
    $oauthUser->expiresIn = 3600;
    $oauthUser->approvedScopes = ['Mail.Send'];
    Socialite::shouldReceive('driver')->with('microsoft')->andReturnSelf();
    Socialite::shouldReceive('scopes')->andReturnSelf();
    Socialite::shouldReceive('redirectUrl')->andReturnSelf();
    Socialite::shouldReceive('user')->andReturnUsing(function () use ($oauthUser) {
        $permission = Permission::where('key', 'integrations.manage_secrets')->sole();
        $this->admin->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);

        return $oauthUser;
    });
    $this->actingAs($this->admin)->get('/settings/it-mailbox/callback/microsoft')->assertForbidden();
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.it_mailbox.connected']);
    Http::assertNothingSent();
});
