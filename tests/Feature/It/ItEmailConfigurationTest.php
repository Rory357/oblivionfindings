<?php

use App\Domain\It\Presenters\ItTicketCommentDeliveryPresenter;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItOutboundMailer;
use App\Mail\MailNotSubmitted;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\It\EmailConfigurationTestNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Services\EmailConfiguration;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Str;

function emailConfigurationPayload(User $actor, ItMailboxConnection $connection, array $overrides = []): array
{
    return array_replace([
        'expected_actor_id' => $actor->id, 'expected_version' => 0,
        'provider' => 'google', 'smtp_host' => 'smtp.example.test', 'smtp_port' => 587,
        'smtp_encryption' => 'tls', 'smtp_username' => '', 'smtp_password' => '', 'clear_smtp_password' => false,
        'from_address' => 'support@example.test', 'from_name' => 'Synthetic Support',
        'support_enabled' => true, 'support_connection_id' => $connection->id,
        'support_connection_version' => $connection->configuration_version, 'public_reply_mode' => 'link_only',
    ], $overrides);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false, 'mail.default' => 'array']);
    $this->seed(RbacSeeder::class);
    $this->owner = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->owner->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    $this->connection = ItMailboxConnection::create([
        'provider' => 'google', 'status' => 'connected', 'account_email' => 'support@example.test',
        'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
        'token_expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
    ])->fresh();
    $this->payload = emailConfigurationPayload($this->owner, $this->connection);
});

test('canonical settings save preserves unrelated fields and encrypts secrets without returning or auditing them', function () {
    AppSetting::create(['key' => EmailConfiguration::KEY, 'value' => ['unrelated_field' => 'preserve']]);
    $response = $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['smtp_password' => 'synthetic-secret']));
    $response->assertOk()->assertJsonPath('data.settings.configuration_version', 1)->assertJsonPath('data.smtp_password_saved', true)
        ->assertJsonPath('data.capture_mode', 'array')->assertJsonMissingPath('data.settings.smtp_password')
        ->assertJsonMissingPath('data.settings.support_connection_scope_hash');
    expect($response->getContent())->not->toContain('synthetic-secret', 'synthetic-access', 'synthetic-refresh');
    $secret = AppSetting::where('key', EmailConfiguration::PASSWORD_KEY)->sole()->value;
    expect($secret)->not->toBe('synthetic-secret')->and(Crypt::decryptString($secret))->toBe('synthetic-secret')
        ->and(AppSetting::where('key', EmailConfiguration::KEY)->sole()->value['unrelated_field'])->toBe('preserve');
    $audit = AuditLog::where('action', 'settings.email.updated')->sole();
    expect(json_encode($audit->toArray()))->not->toContain('synthetic-secret', 'synthetic-access', 'synthetic-refresh');
    $this->getJson('/settings/email')->assertOk()->assertJsonPath('data.settings.configuration_version', 1);
});

test('stale edits actor substitution and field validation never replace saved configuration or credentials', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['smtp_password' => 'first-secret']))->assertOk();
    $this->putJson('/settings/email', array_replace($this->payload, ['smtp_password' => 'stale-secret']))->assertConflict();
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1, 'expected_actor_id' => $this->owner->id + 1]))->assertForbidden();
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1, 'from_name' => "Unsafe\r\nBcc: another@example.test"]))->assertUnprocessable();
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1, 'clear_smtp_password' => true, 'smtp_password' => 'ambiguous-secret']))->assertUnprocessable();
    expect(app(EmailConfiguration::class)->current()['configuration_version'])->toBe(1)
        ->and(app(EmailConfiguration::class)->smtpPassword(1))->toBe('first-secret')
        ->and(AuditLog::where('action', 'settings.email.updated')->count())->toBe(1);
});

test('secret replacement preservation and removal share the versioned configuration transaction', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['smtp_password' => 'first-secret']))->assertOk();
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1]))->assertOk();
    expect(app(EmailConfiguration::class)->smtpPassword(2))->toBe('first-secret');
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 2, 'smtp_password' => 'replacement-secret']))->assertOk();
    expect(app(EmailConfiguration::class)->smtpPassword(3))->toBe('replacement-secret');
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 3, 'clear_smtp_password' => true]))->assertOk();
    expect(AppSetting::where('key', EmailConfiguration::PASSWORD_KEY)->exists())->toBeFalse();
    expect(fn () => app(EmailConfiguration::class)->smtpPassword(3))->toThrow(DomainException::class);
});

test('required audit failure rolls back both settings and the password', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['smtp_password' => 'first-secret']))->assertOk();
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function ($audit) {
        if ($audit->action === 'settings.email.updated') {
            throw new RuntimeException('Synthetic audit failure');
        }
    });
    $request = Request::create('/settings/email', 'PUT', array_replace($this->payload, ['expected_version' => 1, 'smtp_password' => 'rolled-back-secret']));
    $request->setUserResolver(fn () => $this->owner);
    try {
        expect(fn () => app(EmailConfiguration::class)->save($request))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect(app(EmailConfiguration::class)->current()['configuration_version'])->toBe(1)
        ->and(app(EmailConfiguration::class)->smtpPassword(1))->toBe('first-secret');
});

test('settings view permission alone cannot manage support credentials or see private test history', function () {
    $viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $role = Role::create(['name' => 'email-view-'.Str::uuid(), 'label' => 'Synthetic settings viewer', 'level' => 50, 'type' => 'custom']);
    $role->permissions()->attach(Permission::where('key', 'settings.access.manage')->firstOrFail());
    $viewer->roles()->attach($role);
    $this->actingAs($viewer)->getJson('/settings/email')->assertOk()->assertJsonPath('data.can_manage', false)
        ->assertJsonPath('data.connections', [])->assertJsonPath('data.last_test', null);
    $this->putJson('/settings/email', emailConfigurationPayload($viewer, $this->connection))->assertForbidden();
    $this->postJson('/settings/email/test', ['request_uuid' => (string) Str::uuid(), 'expected_version' => 1, 'expected_actor_id' => $viewer->id])->assertForbidden();
    $this->getJson('/settings/email/test/'.Str::uuid())->assertForbidden();
    $viewer->roles()->detach();
    $this->actingAs($viewer->fresh())->getJson('/settings/email')->assertForbidden();
    expect(AppSetting::where('key', EmailConfiguration::KEY)->exists())->toBeFalse()->and(ItEmailDelivery::count())->toBe(0);
});

test('changed and insufficient support connections cannot enable delivery but can be disabled', function () {
    $this->connection->update(['scopes' => ['https://www.googleapis.com/auth/gmail.readonly']]);
    $this->actingAs($this->owner)->putJson('/settings/email', $this->payload)->assertUnprocessable();
    $this->connection->forceFill(['configuration_version' => 2])->save();
    $this->putJson('/settings/email', $this->payload)->assertUnprocessable();
    $this->putJson('/settings/email', array_replace($this->payload, ['support_enabled' => false]))->assertOk()
        ->assertJsonPath('data.settings.support_enabled', false)->assertJsonPath('data.settings.support_connection_id', null);
    expect(ItEmailDelivery::count())->toBe(0);
});

test('an explicit configuration test uses the canonical ledger and recovers its accepted local result without resending', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', $this->payload)->assertOk();
    $uuid = (string) Str::uuid();
    $input = ['request_uuid' => $uuid, 'expected_version' => 1, 'expected_actor_id' => $this->owner->id];
    $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.capture_mode', 'array');
    $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.attempt_count', 1);
    $this->getJson('/settings/email/test/'.$uuid)->assertOk()->assertJsonPath('data.request_uuid', $uuid);
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->it_ticket_id)->toBeNull()->and($delivery->rfc_message_id)->not->toBeNull()
        ->and($delivery->provider_message_id)->toBeNull()->and($delivery->delivered_at)->toBeNull()
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(AuditLog::where('action', 'settings.email.test_requested')->count())->toBe(1);
    // An accepted request stays recoverable after another settings edit.
    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1, 'support_enabled' => false]))->assertOk();
    $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.status', 'accepted');
    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
    $other = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'admin')->firstOrFail());
    $this->actingAs($other)->getJson('/settings/email/test/'.$uuid)->assertNotFound();
    Http::assertNothingSent();
});

test('configured API delivery distinguishes explicit rejection from lost acceptance and never resubmits a replay', function (string $provider, int $status, string $expected) {
    $this->connection->update(['provider' => $provider, 'scopes' => $provider === 'google' ? ['https://www.googleapis.com/auth/gmail.modify'] : ['Mail.Send']]);
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['provider' => $provider]))->assertOk();
    // Exercise the configured API transport, with HTTP strictly faked. No SMTP operation occurs.
    config(['mail.default' => 'smtp']);
    $attempts = 0;
    Http::fake(function () use ($status, &$attempts) {
        $attempts++;
        if ($status === 0) {
            throw new ConnectionException('Synthetic provider private detail');
        }

        return Http::response(['error' => 'Synthetic provider private detail'], $status);
    });
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => 1, 'expected_actor_id' => $this->owner->id];
    $response = $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.status', $expected)->assertJsonPath('data.capture_mode', null);
    expect($response->getContent())->not->toContain('Synthetic provider private detail');
    $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.status', $expected);
    $delivery = ItEmailDelivery::query()->sole();
    expect($attempts)->toBe(1)->and($delivery->accepted_at)->toBeNull()->and($delivery->delivered_at)->toBeNull()
        ->and($delivery->last_error)->not->toContain('Synthetic provider private detail');
    expect($delivery->failed_at !== null)->toBe($expected === 'failed');
})->with([
    ['google', 400, 'failed'], ['google', 403, 'failed'], ['google', 429, 'failed'], ['google', 500, 'sending'], ['google', 0, 'sending'],
    ['microsoft', 400, 'failed'], ['microsoft', 403, 'failed'], ['microsoft', 429, 'failed'], ['microsoft', 500, 'sending'], ['microsoft', 0, 'sending'],
]);

test('an uncertain provider outcome preserves immutable submission evidence through settings change and reconciliation', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', $this->payload)->assertOk();
    config(['mail.default' => 'smtp']);
    Http::fake(fn () => Http::response(['error' => 'Synthetic lost acknowledgement'], 500));
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => 1, 'expected_actor_id' => $this->owner->id];
    $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.status', 'sending');
    $delivery = ItEmailDelivery::query()->sole();
    $snapshot = $delivery->notification_context['submission_snapshot'];
    expect($delivery->provider)->toBe('google')->and($snapshot['configuration_version'])->toBe(1)
        ->and($snapshot['configured_provider'])->toBe('google')->and($snapshot['transport'])->toBe('google');
    expect($snapshot['from'][0])->toMatchArray(['address' => 'support@example.test', 'name' => 'Synthetic Support']);
    expect(function () use ($delivery): void {
        $current = $delivery->fresh();
        $context = $current->notification_context;
        $context['submission_snapshot']['configured_provider'] = 'tampered';
        $current->forceFill(['notification_context' => $context])->save();
    })->toThrow(LogicException::class);
    expect(function () use ($delivery): void {
        $delivery->fresh()->forceFill(['provider' => 'smtp'])->save();
    })->toThrow(LogicException::class);

    $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1, 'from_name' => 'Synthetic Support V2']))->assertOk();
    $reconciled = app(ItEmailDeliveryService::class)->recordProviderStatus(
        $delivery->notification_uuid,
        'failed',
        'Synthetic confirmed provider rejection',
        'synthetic-confirmed-rejection',
    );
    expect($reconciled->status)->toBe('failed')->and($reconciled->notification_context['submission_snapshot'])->toBe($snapshot)
        ->and($reconciled->provider)->toBe('google');
});

test('a public reply records a mixed provider outcome per recipient and retries only the rejected delivery', function () {
    $site = ensureCanonicalHrStaffProfile($this->owner);
    $assignee = User::factory()->create(['role' => 'hr', 'approved_at' => now(), 'email' => 'accepted-recipient@example.test']);
    $assignee->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($assignee, $site);
    $watcher = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'email' => 'rejected-recipient@example.test']);
    $watcher->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $watcher->permissionOverrides()->attach(Permission::where('key', 'it.view')->firstOrFail()->id, ['allowed' => true]);
    ensureCanonicalHrStaffProfile($watcher, $site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->owner->id,
        'assigned_to_user_id' => $assignee->id,
    ]);
    $ticket->watchers()->attach($watcher->id);
    $comment = ItTicketComment::create([
        'ticket_id' => $ticket->id,
        'author_user_id' => $this->owner->id,
        'body' => 'Synthetic public reply with per-recipient provider outcomes.',
        'is_internal' => false,
    ]);
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['public_reply_mode' => 'full_reply']))->assertOk();
    config(['mail.default' => 'smtp']);
    $attempts = 0;
    $rejections = 0;
    Http::fake(function ($request) use ($watcher, &$attempts, &$rejections) {
        $attempts++;
        $raw = $request->data()['raw'] ?? null;
        $mime = is_string($raw) ? base64_decode(strtr($raw, '-_', '+/'), true) : false;
        if (! is_string($mime)) {
            throw new RuntimeException('Synthetic provider request did not contain MIME content.');
        }

        $headers = preg_split("/\r?\n\r?\n/", $mime, 2)[0];
        $watcherIsRecipient = preg_match('/^To:.*'.preg_quote($watcher->email, '/').'/mi', $headers) === 1;
        if ($watcherIsRecipient && $rejections++ === 0) {
            return Http::response(['error' => 'Synthetic recipient rejection'], 400);
        }

        return Http::response(['id' => 'synthetic-provider-'.($attempts)], 200);
    });
    $deliveries = app(ItEmailDeliveryService::class);
    $deliveries->prepare([$assignee, $watcher], new TicketRepliedNotification($ticket, 'agent_side', $comment->id));

    expect($deliveries->dispatchPending())->toBe(2);
    $accepted = ItEmailDelivery::query()->where('recipient_user_id', $assignee->id)->sole();
    $rejected = ItEmailDelivery::query()->where('recipient_user_id', $watcher->id)->sole();
    expect($accepted->status)->toBe('accepted')->and($accepted->provider_message_id)->toBe('synthetic-provider-1')
        ->and($accepted->attempt_count)->toBe(1)
        ->and($rejected->status)->toBe('failed')->and($rejected->attempt_count)->toBe(1)
        ->and($attempts)->toBe(2);
    $acceptedSnapshot = $accepted->notification_context['submission_snapshot'];
    $rejectedSnapshot = $rejected->notification_context['submission_snapshot'];
    expect($accepted->provider)->toBe('google')->and($acceptedSnapshot['configuration_version'])->toBe(1)
        ->and($acceptedSnapshot['configured_provider'])->toBe('google')->and($acceptedSnapshot['content_mode'])->toBe('full_reply')
        ->and($rejected->provider)->toBe('google')->and($rejectedSnapshot)->toBe($acceptedSnapshot);
    expect($acceptedSnapshot['from'][0])->toMatchArray(['address' => 'support@example.test', 'name' => 'Synthetic Support']);
    expect($deliveries->dispatchPending())->toBe(0)->and($attempts)->toBe(2);
    $evidence = app(ItTicketCommentDeliveryPresenter::class)->presentMany($ticket, $this->owner, collect([$comment]))[$comment->id];
    expect($evidence['attempt_statuses']->accepted)->toBe(1)->and($evidence['attempt_statuses']->failed)->toBe(1);

    $this->putJson('/settings/email', array_replace($this->payload, [
        'expected_version' => 1,
        'public_reply_mode' => 'full_reply',
        'from_name' => 'Synthetic Support V2',
    ]))->assertOk();
    expect($accepted->fresh()->notification_context['submission_snapshot'])->toBe($acceptedSnapshot)
        ->and($rejected->fresh()->notification_context['submission_snapshot'])->toBe($rejectedSnapshot);
    expect(fn () => $deliveries->retry($accepted, $this->owner->fresh()))->toThrow(DomainException::class, 'Only failed or bounced email can be retried.')
        ->and(ItEmailDelivery::query()->count())->toBe(2)->and($attempts)->toBe(2);
    $retry = $deliveries->retry($rejected, $this->owner->fresh());
    expect($retry->status)->toBe('queued')->and($retry->retry_of_delivery_id)->toBe($rejected->id)
        ->and($retry->dispatch_requested_at)->not->toBeNull()->and($retry->provider)->toBeNull()
        ->and(array_key_exists('submission_snapshot', $retry->notification_context ?? []))->toBeFalse()
        ->and($rejected->fresh()->status)->toBe('retried')->and($rejected->fresh()->notification_context['submission_snapshot'])->toBe($rejectedSnapshot);
    expect($deliveries->dispatchPending(deliveryId: $retry->id))->toBe(1)
        ->and($retry->fresh()->status)->toBe('accepted')->and($retry->fresh()->attempt_count)->toBe(1)
        ->and($attempts)->toBe(3);
    $retrySnapshot = $retry->fresh()->notification_context['submission_snapshot'];
    expect($retry->fresh()->provider)->toBe('google')->and($retrySnapshot['configuration_version'])->toBe(2)
        ->and($retrySnapshot['configured_provider'])->toBe('google')->and($retrySnapshot['content_mode'])->toBe('full_reply');
    expect($retrySnapshot['from'][0])->toMatchArray(['address' => 'support@example.test', 'name' => 'Synthetic Support V2']);
    expect($deliveries->dispatchPending())->toBe(0)->and($attempts)->toBe(3);
});

test('uncertain test submission blocks a new request while permitting same request recovery', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', $this->payload)->assertOk();
    $input = ['request_uuid' => (string) Str::uuid(), 'expected_version' => 1, 'expected_actor_id' => $this->owner->id];
    $request = Request::create('/settings/email/test', 'POST', $input);
    $request->setUserResolver(fn () => $this->owner);
    $delivery = app(ItEmailDeliveryService::class)->prepareConfigurationTest($request, $input['request_uuid'], 1);
    $delivery->forceFill(['status' => 'sending', 'sending_at' => now(), 'attempt_count' => 1])->save();
    $this->postJson('/settings/email/test', array_replace($input, ['request_uuid' => (string) Str::uuid()]))->assertConflict();
    $this->postJson('/settings/email/test', $input)->assertOk()->assertJsonPath('data.status', 'sending');
    expect(ItEmailDelivery::count())->toBe(1)->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});

test('the final mail preflight blocks a support connection disconnected after mailer construction', function () {
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['provider' => 'smtp']))->assertOk();
    $uuid = (string) Str::uuid();
    $request = Request::create('/settings/email/test', 'POST');
    $request->setUserResolver(fn () => $this->owner);
    $deliveries = app(ItEmailDeliveryService::class);
    $delivery = $deliveries->prepareConfigurationTest($request, $uuid, 1);
    $notification = new EmailConfigurationTestNotification(1, 'array');
    $notification->id = $uuid;
    Event::until(new NotificationSending($this->owner, $notification, 'mail'));
    $mailer = app(ItOutboundMailer::class)->make(app(EmailConfiguration::class)->current());
    // The SMTP mailer has already captured the old From/Reply-To identity.
    $this->connection->update(['status' => 'disconnected']);
    try {
        $mailer->send(['raw' => 'Synthetic preflight race'], [
            '__it_email_configuration_version' => 1,
            '__laravel_notification' => EmailConfigurationTestNotification::class,
            '__laravel_notification_id' => $uuid,
        ], fn ($message) => $message->to($this->owner->email)->subject('Synthetic preflight race'));
        $this->fail('A disconnected support identity was submitted.');
    } catch (MailNotSubmitted $exception) {
        Event::dispatch(new NotificationFailed($this->owner, $notification, 'mail', ['exception' => $exception]));
    }
    expect($delivery->fresh()->status)->toBe('failed')->and($delivery->fresh()->rfc_message_id)->toBeNull()
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    Http::assertNothingSent();
});

test('queued configuration tests cannot run after permission configuration or capture mode changes', function (string $change) {
    $this->actingAs($this->owner)->putJson('/settings/email', $this->payload)->assertOk();
    $request = Request::create('/settings/email/test', 'POST');
    $request->setUserResolver(fn () => $this->owner);
    $delivery = app(ItEmailDeliveryService::class)->prepareConfigurationTest($request, (string) Str::uuid(), 1);
    if ($change === 'permission') {
        $this->owner->update(['approved_at' => null]);
    }
    if ($change === 'configuration') {
        $this->putJson('/settings/email', array_replace($this->payload, ['expected_version' => 1]))->assertOk();
    }
    if ($change === 'capture') {
        config(['mail.default' => 'smtp']);
    }
    app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id);
    expect($delivery->fresh()->status)->toBe('failed')->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    Http::assertNothingSent();
})->with(['permission', 'configuration', 'capture']);

test('configured public reply modes preserve safe HTML literal text and canonical support identity', function (string $mode) {
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['public_reply_mode' => $mode]))->assertOk();
    $recipient = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $recipient->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $site = ensureCanonicalHrStaffProfile($recipient);
    $ticket = ItTicket::factory()->create(['requester_user_id' => $recipient->id, 'site_id' => $site->id]);
    $body = "Synthetic public reply <script>alert(1)</script>\n[unsafe](javascript:alert(1)) & details";
    $comment = ItTicketComment::create(['ticket_id' => $ticket->id, 'author_user_id' => $this->owner->id, 'body' => $body, 'is_internal' => false]);
    app(ItEmailDeliveryService::class)->prepare($recipient, new TicketRepliedNotification($ticket, 'requester', $comment->id));
    app(ItEmailDeliveryService::class)->dispatchPending();
    expect(ItEmailDelivery::query()->sole()->status)->toBe('accepted');
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect($message->getFrom()[0]->getAddress())->toBe('support@example.test')
        ->and($message->getReplyTo()[0]->getAddress())->toBe('support@example.test')
        ->and($message->getTo()[0]->getAddress())->toBe($recipient->email)
        ->and($message->getHtmlBody())->not->toContain('<script>', 'href="javascript:');
    if ($mode === 'full_reply') {
        expect($message->getHtmlBody())->toContain('&lt;script&gt;', '[unsafe]')
            ->and($message->getTextBody())->toContain($body);
    } else {
        expect($message->getHtmlBody())->not->toContain('Synthetic public reply')->and($message->getTextBody())->not->toContain($body);
    }
    $defaultFrom = config('mail.from.address');
    Notifications::sendNow($this->owner, new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Unrelated notification')->line('Ordinary application message');
        }
    });
    expect(Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe($defaultFrom);
    Http::assertNothingSent();
})->with(['link_only', 'full_reply']);

test('a public reply becoming internal or inaccessible before dispatch never sends either channel', function (string $change) {
    $this->actingAs($this->owner)->putJson('/settings/email', array_replace($this->payload, ['public_reply_mode' => 'full_reply']))->assertOk();
    $recipient = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $recipient->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $site = ensureCanonicalHrStaffProfile($recipient);
    $ticket = ItTicket::factory()->create(['requester_user_id' => $recipient->id, 'site_id' => $site->id]);
    $comment = ItTicketComment::create(['ticket_id' => $ticket->id, 'author_user_id' => $this->owner->id, 'body' => 'Private synthetic reply', 'is_internal' => false]);
    app(ItEmailDeliveryService::class)->prepare($recipient, new TicketRepliedNotification($ticket, 'requester', $comment->id));
    if ($change === 'internal') {
        $comment->update(['is_internal' => true]);
    }
    if ($change === 'deleted') {
        $comment->delete();
    }
    if ($change === 'approval') {
        $recipient->update(['approved_at' => null]);
    }
    app(ItEmailDeliveryService::class)->dispatchPending();
    expect(ItEmailDelivery::query()->sole()->status)->toBe('failed')
        ->and($recipient->notifications()->count())->toBe(0)->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
})->with(['internal', 'deleted', 'approval']);
