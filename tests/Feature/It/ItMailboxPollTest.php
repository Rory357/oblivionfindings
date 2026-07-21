<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\InboundEmailIngestor;
use App\Jobs\PollItMailboxJob;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/*
 * E4 — the mailbox poller: connected mailbox → unread Graph mail → tickets,
 * mark-read, retry-safe dedupe. All HTTP faked.
 */

function itPollRequestSender(string $email): User
{
    $site = Site::factory()->create();
    $sender = User::factory()->create(['email' => $email, 'organization_id' => 1]);
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
    itPollRequestSender('worker@example.test');
    $connection = itPollConnection();

    Http::fake([
        'graph.microsoft.com/v1.0/users/*/mailFolders/inbox/messages*' => Http::response([
            'value' => [itPollGraphMessage()],
        ], 200),
        'graph.microsoft.com/v1.0/users/*/messages/*' => Http::response([], 200),
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

    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    expect(ItTicket::query()->count())->toBe(0);
    expect(ItInboundEmail::query()->count())->toBe(1); // no duplicate log row
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
