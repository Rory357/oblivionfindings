<?php

use App\Domain\It\InboundEmailIngestor;
use App\Jobs\PollItMailboxJob;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * E4 — the mailbox poller: connected mailbox → unread Graph mail → tickets,
 * mark-read, retry-safe dedupe. All HTTP faked.
 */

function itPollConnection(array $overrides = []): ItMailboxConnection
{
    return ItMailboxConnection::create(array_merge([
        'tenant_id' => 1,
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

test('polling a connected mailbox turns unread mail into tickets and marks it read', function () {
    User::factory()->create(['email' => 'worker@example.test', 'organization_id' => 1]);
    $connection = itPollConnection();

    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
            'value' => [itPollGraphMessage()],
        ], 200),
        'graph.microsoft.com/v1.0/users/*/messages/*' => Http::response([], 200),
    ]);

    (new PollItMailboxJob)->handle(new InboundEmailIngestor);

    $ticket = ItTicket::query()->firstWhere('title', 'Printer jammed');
    expect($ticket)->not->toBeNull();
    expect($ticket->source)->toBe('email');
    expect(ItInboundEmail::query()->where('it_ticket_id', $ticket->id)->where('status', 'processed')->count())->toBe(1);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['isRead'] === true);
});

test('an already-ingested message is not ticketed twice but is still marked read', function () {
    User::factory()->create(['email' => 'worker@example.test', 'organization_id' => 1]);
    itPollConnection();
    ItInboundEmail::create([
        'tenant_id' => 1,
        'from_email' => 'worker@example.test',
        'message_id' => '<msg1@mail.example.test>',
        'status' => 'processed',
        'received_at' => now(),
    ]);

    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
            'value' => [itPollGraphMessage()],
        ], 200),
        'graph.microsoft.com/v1.0/users/*/messages/*' => Http::response([], 200),
    ]);

    (new PollItMailboxJob)->handle(new InboundEmailIngestor);

    expect(ItTicket::query()->count())->toBe(0);
    expect(ItInboundEmail::query()->count())->toBe(1); // no duplicate log row
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'); // still silenced for next poll
});

test('disconnected and non-microsoft connections are skipped without any HTTP', function () {
    itPollConnection(['status' => ItMailboxConnection::STATUS_DISCONNECTED]);
    // Connected google row — Gmail read lands in E5; skipped, not errored.
    itPollConnection(['provider' => ItMailboxConnection::PROVIDER_GOOGLE]);

    Http::fake();

    (new PollItMailboxJob)->handle(new InboundEmailIngestor);

    Http::assertNothingSent();
    expect(ItMailboxConnection::query()->where('status', ItMailboxConnection::STATUS_ERROR)->count())->toBe(0);
});
