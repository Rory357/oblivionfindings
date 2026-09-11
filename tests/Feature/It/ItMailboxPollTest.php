<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItEmailAttachments;
use App\Jobs\PollItMailboxJob;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Integration\MailboxResponseBody;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * E4 — the mailbox poller: connected mailbox → unread Graph mail → tickets,
 * mark-read, retry-safe dedupe. All HTTP faked.
 */

function itPollRequestSender(string $email): User
{
    $site = Site::factory()->create();
    $sender = User::factory()->create(['email' => $email]);
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'it.request'],
        ['description' => 'Create IT requests', 'group' => 'it', 'module' => 'Operations'],
    );
    $role = Role::query()->create([
        'name' => 'mailbox-requester-'.str()->uuid(),
        'label' => 'Mailbox requester',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->attach($permission);
    $sender->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $sender->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'created_by' => $sender->id,
        'updated_by' => $sender->id,
    ]);

    return $sender;
}

function itPollConnection(array $overrides = []): ItMailboxConnection
{
    return ItMailboxConnection::create(array_merge([
        'provider' => ItMailboxConnection::PROVIDER_MICROSOFT,
        'status' => ItMailboxConnection::STATUS_CONNECTED,
        'access_token' => 'access-123',
        'refresh_token' => 'refresh-456',
        'token_expires_at' => now()->addHour(),
        'account_email' => 'admin@example.test',
        'mailbox_email' => 'support@example.test',
    ], $overrides));
}

function itPollGraphMessage(array $overrides = []): array
{
    return array_merge([
        'id' => 'AAMkAGraphId1',
        'subject' => 'Printer jammed',
        'from' => ['emailAddress' => ['address' => 'worker@example.test']],
        'body' => ['contentType' => 'text', 'content' => 'Stuck all morning.'],
        'bodyPreview' => 'Stuck all morning.',
        'internetMessageId' => '<msg1@mail.example.test>',
    ], $overrides);
}

test('oversized message downloads retain their discovery receipt and retry without acknowledging or duplicating', function (string $provider) {
    Http::preventStrayRequests();
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection(['provider' => $provider, 'account_email' => 'support@example.test', 'mailbox_email' => null]);
    $oversized = true;
    $reads = $acknowledgements = 0;
    Http::fake(function ($request) use ($provider, &$oversized, &$reads, &$acknowledgements) {
        if ($provider === 'microsoft' && preg_match('~/messages/[^/?]+/attachments(?:\?|$)~', $request->url())) {
            return Http::response(['value' => []]);
        }
        if ($request->method() === 'PATCH' || str_contains($request->url(), '/modify')) {
            $acknowledgements++;

            return Http::response([], 200);
        }
        if (str_contains($request->url(), '/messages/bounded-message')) {
            $reads++;
            if ($oversized) {
                $limit = $provider === 'google' ? ItEmailAttachments::MESSAGE_RESPONSE_BYTES : MailboxResponseBody::DEFAULT_LIMIT;

                return Http::response('Untrusted oversized response', 200, ['Content-Length' => (string) ($limit + 1)]);
            }

            return Http::response($provider === 'microsoft' ? itPollGraphMessage(['id' => 'bounded-message']) : [
                'id' => 'bounded-message', 'payload' => ['mimeType' => 'text/plain', 'headers' => [
                    ['name' => 'From', 'value' => 'worker@example.test'],
                    ['name' => 'Subject', 'value' => 'Printer jammed'],
                    ['name' => 'Message-ID', 'value' => '<bounded@mail.example.test>'],
                ], 'body' => ['data' => base64_encode('Complete recovered report.')]],
            ], 200);
        }
        if ($request->method() === 'GET' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/messages')) {
            return Http::response([$provider === 'microsoft' ? 'value' : 'messages' => [['id' => 'bounded-message']]], 200);
        }
        throw new RuntimeException('Unexpected synthetic mailbox request.');
    });
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($connection->fresh()->last_poll_failure_code)->toBe('response_too_large')
        ->and($connection->fresh()->last_polled_at)->toBeNull()
        ->and(ItInboundEmail::sole()->status)->toBe('pending')
        ->and(ItInboundEmail::sole()->acknowledged_at)->toBeNull()
        ->and(ItTicket::count())->toBe(0)->and($acknowledgements)->toBe(0);
    $oversized = false;
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($connection->fresh()->status)->toBe('connected')
        ->and($connection->fresh()->last_polled_at)->not->toBeNull()
        ->and(ItInboundEmail::sole()->status)->toBe('processed')
        ->and(ItInboundEmail::sole()->acknowledged_at)->not->toBeNull()
        ->and(ItTicket::count())->toBe(1)->and($reads)->toBe(2)->and($acknowledgements)->toBe(1);
})->with(['google', 'microsoft']);

test('polling a connected mailbox turns unread mail into tickets and marks it read', function () {
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection();

    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
            'value' => [itPollGraphMessage()],
        ], 200),
        'graph.microsoft.com/v1.0/users/*/messages/*/attachments*' => Http::response(['value' => []]),
        'graph.microsoft.com/v1.0/users/*/messages/*' => fn ($request) => Http::response($request->method() === 'GET' ? itPollGraphMessage() : [], 200),
    ]);

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    $ticket = ItTicket::query()->firstWhere('title', 'Printer jammed');
    expect($ticket)->not->toBeNull();
    expect($ticket->source)->toBe('email');
    expect(ItInboundEmail::query()->where('it_ticket_id', $ticket->id)->where('status', 'processed')->count())->toBe(1);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['isRead'] === true);
});

test('an already-ingested message is not ticketed twice but is still marked read', function () {
    itPollRequestSender('worker@example.test');
    itPollConnection();
    $original = app(InboundEmailIngestor::class)->ingest([
        'from' => 'worker@example.test', 'subject' => 'Printer jammed', 'text' => 'Stuck all morning.',
        'message_id' => '<msg1@mail.example.test>',
    ]);

    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
            'value' => [itPollGraphMessage()],
        ], 200),
        'graph.microsoft.com/v1.0/users/*/messages/*/attachments*' => Http::response(['value' => []]),
        'graph.microsoft.com/v1.0/users/*/messages/*' => fn ($request) => Http::response($request->method() === 'GET' ? itPollGraphMessage() : [], 200),
    ]);

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    expect(ItTicket::query()->count())->toBe(1);
    expect(ItInboundEmail::query()->where('status', 'processed')->count())->toBe(1);
    expect(ItInboundEmail::query()->where('status', 'duplicate')->count())->toBe(1); // distinct transport receipt, same result
    expect(ItInboundEmail::query()->where('status', 'duplicate')->sole()->duplicate_of_id)->toBe($original->id);
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'); // still silenced for next poll
});

test('disconnected connections are skipped without any HTTP', function () {
    itPollConnection(['status' => ItMailboxConnection::STATUS_DISCONNECTED]);

    Http::fake();

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    Http::assertNothingSent();
    expect(ItMailboxConnection::query()->where('status', ItMailboxConnection::STATUS_ERROR)->count())->toBe(0);
});

test('polling a connected gmail mailbox turns unread mail into tickets and clears UNREAD', function () {
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection([
        'provider' => ItMailboxConnection::PROVIDER_GOOGLE,
        'account_email' => 'support@example.test',
        'mailbox_email' => null, // Gmail reads the connected account's own inbox
    ]);

    $body = rtrim(strtr(base64_encode('Gmail body text.'), '+/', '-_'), '=');
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/*/modify*' => Http::response([], 200),
        'gmail.googleapis.com/gmail/v1/users/me/messages/*' => Http::response([
            'id' => 'gm-1',
            'snippet' => 'Gmail body text.',
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [
                    ['name' => 'From', 'value' => 'Worker <worker@example.test>'],
                    ['name' => 'Subject', 'value' => 'Laptop battery dead'],
                    ['name' => 'Message-ID', 'value' => '<gm1@mail.example.test>'],
                ],
                'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => $body]],
                ],
            ],
        ], 200),
        'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
            'messages' => [['id' => 'gm-1']],
        ], 200),
    ]);

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    $ticket = ItTicket::query()->firstWhere('title', 'Laptop battery dead');
    expect($ticket)->not->toBeNull();
    expect($ticket->source)->toBe('email');
    expect($ticket->description)->toBe('Gmail body text.');
    expect($connection->fresh()->last_polled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/gm-1/modify')
        && $request['removeLabelIds'] === ['UNREAD']);
});

test('provider rejection records a safe failed poll without reporting an empty successful inbox', function () {
    $connection = itPollConnection();
    Log::spy();
    Http::preventStrayRequests();
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['message' => 'PRIVATE provider token']], 403)]);

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    $connection->refresh();
    expect($connection->status)->toBe(ItMailboxConnection::STATUS_ERROR);
    expect($connection->last_polled_at)->toBeNull();
    expect($connection->last_error)->toContain('denied mailbox access')->not->toContain('PRIVATE');
    expect(ItInboundEmail::query()->count())->toBe(0);
    Log::shouldHaveReceived('error')->once()->with('IT mailbox poll failed.', [
        'connection_id' => $connection->id, 'failure_code' => 'permission',
    ]);
});

test('failed mark-read retains ingested work but does not stamp a successful poll', function () {
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection();
    Http::preventStrayRequests();
    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response(['value' => [itPollGraphMessage()]]),
        'graph.microsoft.com/v1.0/users/*/messages/*/attachments*' => Http::response(['value' => []]),
        'graph.microsoft.com/v1.0/users/*/messages/*' => fn ($request) => $request->method() === 'GET'
            ? Http::response(itPollGraphMessage()) : Http::response(['error' => 'PRIVATE'], 503),
    ]);

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    expect(ItTicket::query()->where('title', 'Printer jammed')->count())->toBe(1);
    expect(ItInboundEmail::query()->where('status', 'processed')->count())->toBe(1);
    expect($connection->fresh()->last_polled_at)->toBeNull();
    expect($connection->fresh()->status)->toBe(ItMailboxConnection::STATUS_ERROR);
    expect($connection->fresh()->last_error)->not->toContain('PRIVATE');
});

test('an acknowledgement retry recovers automatically after cooldown without duplicating the ticket', function () {
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection();
    Http::preventStrayRequests();
    $acks = 0;
    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response(['value' => [itPollGraphMessage()]]),
        'graph.microsoft.com/v1.0/users/*/messages/*/attachments*' => Http::response(['value' => []]),
        'graph.microsoft.com/v1.0/users/*/messages/*' => function ($request) use (&$acks) {
            if ($request->method() === 'GET') {
                return Http::response(itPollGraphMessage());
            }

            return Http::response([], ++$acks === 1 ? 503 : 200);
        },
    ]);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($connection->fresh()->last_poll_failure_code)->toBe('unavailable');
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($acks)->toBe(1);
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($acks)->toBe(2);
    expect($connection->fresh()->status)->toBe('connected');
    expect($connection->fresh()->last_error)->toBeNull();
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
    expect(ItTicket::query()->where('title', 'Printer jammed')->count())->toBe(1);
    expect(ItInboundEmail::query()->where('status', 'processed')->count())->toBe(1);
});

test('disconnect during provider read prevents later ingestion and acknowledgement', function () {
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection();
    Http::preventStrayRequests();
    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => function () use ($connection) {
            $connection->delete();

            return Http::response(['value' => [itPollGraphMessage()]]);
        },
    ]);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect(ItTicket::query()->count())->toBe(0);
    expect(ItInboundEmail::query()->count())->toBe(0);
    expect(ItMailboxConnection::query()->count())->toBe(0);
    Http::assertSentCount(1);
});
