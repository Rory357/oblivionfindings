<?php

use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItAutomationRunOutcome;
use App\Jobs\PollItMailboxJob;
use App\Models\ItAutomationRun;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function itPagingConnection(string $provider): ItMailboxConnection
{
    return ItMailboxConnection::create([
        'provider' => $provider, 'status' => 'connected',
        'account_email' => 'inbox@example.test', 'access_token' => 'synthetic-access',
        'refresh_token' => 'synthetic-refresh', 'token_expires_at' => now()->addHour(),
    ]);
}

function itPagingMessage(string $provider, string $id, bool $withMessageId = true): array
{
    if ($provider === 'google') {
        return ['id' => $id, 'payload' => [
            'mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Private synthetic message')],
            'headers' => [
                ['name' => 'From', 'value' => 'unknown@example.test'],
                ['name' => 'Subject', 'value' => 'Synthetic intake'],
                ...($withMessageId ? [['name' => 'Message-ID', 'value' => '<'.$id.'@example.test>']] : []),
            ],
        ]];
    }

    return [
        'id' => $id, 'from' => ['emailAddress' => ['address' => 'unknown@example.test']],
        'subject' => 'Synthetic intake', 'body' => ['contentType' => 'text', 'content' => 'Private synthetic message'],
        'internetMessageId' => $withMessageId ? '<'.$id.'@example.test>' : null,
    ];
}

/** Fake both discovery and detail endpoints; acks mutate the simulated unread set. */
function itPagingFake(string $provider, array &$unread, array &$calls, int $pageSize = 25, ?Closure $ack = null): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($provider, &$unread, &$calls, $pageSize, $ack) {
        $url = $request->url();
        if ($provider === 'microsoft' && preg_match('~/messages/[^/?]+/attachments(?:\?|$)~', $url)) {
            return Http::response(['value' => []]);
        }
        $isList = $request->method() === 'GET' && ($provider === 'google'
            ? str_contains($url, '/users/me/messages?') : str_contains($url, '/mailFolders/inbox/messages'));
        if ($isList) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $offset = (int) ($query['pageToken'] ?? $query['$skip'] ?? 0);
            $calls[] = 'list:'.$offset;
            $ids = array_slice(array_values($unread), $offset, $pageSize);
            $next = $offset + count($ids) < count($unread) ? (string) ($offset + count($ids)) : null;
            if ($provider === 'google') {
                return Http::response(['messages' => array_map(fn ($id) => ['id' => $id], $ids),
                    ...($next !== null ? ['nextPageToken' => $next] : [])]);
            }

            return Http::response(['value' => array_map(fn ($id) => ['id' => $id], $ids),
                ...($next !== null ? ['@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/inbox%40example.test/mailFolders/inbox/messages?$skip='.$next] : [])]);
        }
        preg_match('~/messages/([^/?]+)~', $url, $matches);
        $id = rawurldecode($matches[1] ?? '');
        if ($request->method() === 'GET') {
            $calls[] = 'read:'.$id;

            return Http::response(itPagingMessage($provider, $id, ! str_starts_with($id, 'no-mid')));
        }
        $calls[] = 'ack:'.$id;
        if ($ack && ($failure = $ack($id))) {
            return $failure;
        }
        $unread = array_values(array_diff($unread, [$id]));

        return Http::response([], 200);
    });
}

test('drains 27 messages across pages before mutating the unread list', function (string $provider) {
    $connection = itPagingConnection($provider);
    $unread = array_map(fn ($i) => 'message-'.$i, range(1, 27));
    $calls = [];
    itPagingFake($provider, $unread, $calls);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));

    expect(array_slice($calls, 0, 2))->toBe(['list:0', 'list:25']);
    expect($unread)->toBe([]);
    expect(ItInboundEmail::query()->count())->toBe(27);
    expect(ItInboundEmail::query()->whereNotNull('acknowledged_at')->count())->toBe(27);
    expect(ItInboundEmail::query()->where('quarantine_reason', 'sender_unknown')->count())->toBe(27);
    expect(ItInboundEmail::query()->whereNotNull('body_preview')->exists())->toBeFalse();
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
    $receipt = ItInboundEmail::query()->first();
    expect($receipt->toArray())->not->toHaveKeys(['remote_message_id', 'transport_key', 'mailbox_scope_hash']);
    expect(DB::table('it_inbound_emails')->value('remote_message_id'))->not->toBe($receipt->remote_message_id);
    expect(ItInboundEmail::query()->whereNotNull('transport_key')->count())->toBe(27);
    if ($provider === 'microsoft') {
        Http::assertSent(fn ($request) => $request->hasHeader('Prefer', 'IdType="ImmutableId"'));
        foreach (Http::recorded() as [$request]) {
            expect($request->hasHeader('Prefer', 'IdType="ImmutableId"'))->toBeTrue();
        }
    }
})->with(['google', 'microsoft']);

test('resumes a bounded scan in another job without acknowledging an incomplete discovery chain', function (string $provider) {
    $connection = itPagingConnection($provider);
    $unread = array_map(fn ($i) => 'message-'.$i, range(1, 12));
    $calls = [];
    itPagingFake($provider, $unread, $calls, pageSize: 2);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($calls)->toBe(['list:0', 'list:2', 'list:4', 'list:6', 'list:8']);
    expect(ItInboundEmail::query()->where('status', 'pending')->count())->toBe(10);
    expect($connection->fresh()->last_polled_at)->toBeNull();
    expect($connection->fresh()->poll_claim_token)->toBeNull();
    expect($connection->fresh()->inbox_scan_cursor)->not->toBeNull();
    expect(ItAutomationRunOutcome::state(ItAutomationRun::query()->latest('id')->firstOrFail()))->toBe('pending');
    expect($connection->fresh()->toArray())->not->toHaveKey('inbox_scan_cursor');
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($calls[5])->toBe('list:10');
    expect($unread)->toBe([]);
    expect(ItInboundEmail::query()->whereNotNull('acknowledged_at')->count())->toBe(12);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
    expect(ItAutomationRunOutcome::state(ItAutomationRun::query()->latest('id')->firstOrFail()))->toBe('succeeded');
})->with(['google', 'microsoft']);

test('retries a missing-message-id acknowledgement without re-fetching or duplicating quarantine and continues other messages', function (string $provider) {
    $connection = itPagingConnection($provider);
    $unread = ['no-mid-1', 'message-2'];
    $calls = [];
    $failed = false;
    itPagingFake($provider, $unread, $calls, ack: function ($id) use (&$failed) {
        if ($id === 'no-mid-1' && ! $failed) {
            $failed = true;

            return Http::response(['private' => 'must not be recorded'], 503);
        }

        return null;
    });
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($unread)->toBe(['no-mid-1']);
    expect(ItInboundEmail::query()->count())->toBe(2);
    expect($connection->fresh()->last_polled_at)->toBeNull();
    expect(ItInboundEmail::query()->where('quarantine_reason', 'missing_message_id')->value('transport_failure_code'))->toBe('unavailable');
    $beforeRetry = count($calls);
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect(array_slice($calls, $beforeRetry))->toBe(['list:0', 'ack:no-mid-1']);
    expect(ItInboundEmail::query()->count())->toBe(2);
    expect(ItInboundEmail::query()->whereNotNull('acknowledged_at')->count())->toBe(2);
    expect($connection->fresh()->status)->toBe('connected');
})->with(['google', 'microsoft']);

test('rejects unsafe Graph continuations before sending credentials', function (string $continuation) {
    $connection = itPagingConnection('microsoft');
    Http::preventStrayRequests();
    Http::fake();
    expect(fn () => (new MicrosoftGraphService($connection))->discoverUnread('inbox@example.test', now(), $continuation))
        ->toThrow(MailboxProviderFailure::class);
    Http::assertNothingSent();
})->with([
    'https://example.test/v1.0/users/inbox%40example.test/mailFolders/inbox/messages?$skip=2',
    'http://graph.microsoft.com/v1.0/users/inbox%40example.test/mailFolders/inbox/messages?$skip=2',
    'https://graph.microsoft.com/v1.0/users/other%40example.test/mailFolders/inbox/messages?$skip=2',
    'https://graph.microsoft.com/v1.0/me/messages?$skip=2',
    'https://user@graph.microsoft.com/v1.0/users/inbox%40example.test/mailFolders/inbox/messages?$skip=2',
    'https://graph.microsoft.com:443/v1.0/users/inbox%40example.test/mailFolders/inbox/messages?$skip=2',
]);

test('a repeated continuation fails without acknowledging or silently completing the scan', function () {
    $connection = itPagingConnection('google');
    Http::preventStrayRequests();
    Http::fake(['gmail.googleapis.com/*' => Http::response(['messages' => [['id' => 'one']], 'nextPageToken' => 'same-token'])]);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($connection->fresh()->last_poll_failure_code)->toBe('invalid_response');
    expect($connection->fresh()->last_polled_at)->toBeNull();
    expect(ItInboundEmail::query()->where('status', 'pending')->count())->toBe(1);
    Http::assertSentCount(2);
});

test('a repeatedly failing old message does not starve newly arrived mail or create duplicate transport receipts', function (string $provider) {
    $connection = itPagingConnection($provider);
    $unread = ['no-mid-old'];
    $calls = [];
    itPagingFake($provider, $unread, $calls, ack: fn ($id) => $id === 'no-mid-old' ? Http::response([], 503) : null);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    $unread[] = 'new-arrival';
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($unread)->toBe(['no-mid-old']);
    expect(ItInboundEmail::query()->count())->toBe(2);
    expect(ItInboundEmail::query()->where('quarantine_reason', 'missing_message_id')->count())->toBe(1);
    expect(ItInboundEmail::query()->whereNotNull('acknowledged_at')->count())->toBe(1);
    expect(array_count_values($calls)['read:no-mid-old'])->toBe(1);
    expect($connection->fresh()->last_polled_at)->toBeNull();
})->with(['google', 'microsoft']);

test('a lost acknowledgement retries the canonical remote identity even after the message disappears from unread results', function (string $provider) {
    $connection = itPagingConnection($provider);
    $unread = ['no-mid-uncertain'];
    $calls = [];
    $failed = false;
    itPagingFake($provider, $unread, $calls, ack: function () use (&$unread, &$failed) {
        if (! $failed) {
            $unread = [];
            $failed = true;

            return Http::response([], 503);
        }

        return null;
    });
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($unread)->toBe([]);
    expect(ItInboundEmail::query()->whereNull('acknowledged_at')->count())->toBe(1);
    $this->travel(61)->seconds();
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect(ItInboundEmail::query()->count())->toBe(1);
    expect(ItInboundEmail::query()->whereNotNull('acknowledged_at')->count())->toBe(1);
    expect(array_count_values($calls)['read:no-mid-uncertain'])->toBe(1);
    expect(array_count_values($calls)['ack:no-mid-uncertain'])->toBe(2);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
})->with(['google', 'microsoft']);

test('a failed receipt transaction remains pending and cannot be acknowledged until ingestion commits', function () {
    $connection = itPagingConnection('google');
    $unread = ['message-rollback'];
    $calls = [];
    itPagingFake('google', $unread, $calls);
    $originalDispatcher = ItInboundEmail::getEventDispatcher();
    $dispatcher = clone $originalDispatcher;
    ItInboundEmail::setEventDispatcher($dispatcher);
    $failed = false;
    $dispatcher->listen('eloquent.updating: '.ItInboundEmail::class, function (ItInboundEmail $receipt) use (&$failed) {
        if ($receipt->isDirty('status') && $receipt->status === 'quarantined' && ! $failed) {
            $failed = true;

            throw new RuntimeException('Synthetic transaction failure');
        }
    });
    try {
        (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
        expect($calls)->toBe(['list:0', 'read:message-rollback']);
        expect(ItInboundEmail::query()->value('status'))->toBe('pending');
        expect(ItInboundEmail::query()->value('acknowledgement_attempts'))->toBe(0);
        $this->travel(61)->seconds();
        (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
        expect(ItInboundEmail::query()->count())->toBe(1);
        expect(ItInboundEmail::query()->value('status'))->toBe('quarantined');
        expect(ItInboundEmail::query()->value('acknowledged_at'))->not->toBeNull();
        expect($connection->fresh()->last_polled_at)->not->toBeNull();
    } finally {
        ItInboundEmail::setEventDispatcher($originalDispatcher);
    }
});

test('changing the connected account cannot process old account receipts', function () {
    $connection = itPagingConnection('google');
    $unread = array_map(fn ($i) => 'old-'.$i, range(1, 6));
    $calls = [];
    itPagingFake('google', $unread, $calls, pageSize: 1);
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect(ItInboundEmail::query()->where('status', 'pending')->count())->toBe(5);
    $connection->refresh()->forceFill(['account_email' => 'other@example.test', 'configuration_version' => 2])->save();
    $unread = ['new-account'];
    $calls = [];
    (new PollItMailboxJob)->handle(app(InboundEmailIngestor::class));
    expect($calls)->toBe(['list:0', 'read:new-account', 'ack:new-account']);
    expect(ItInboundEmail::query()->where('status', 'pending')->count())->toBe(5);
    expect(ItInboundEmail::query()->whereNotNull('acknowledged_at')->count())->toBe(1);
    expect($connection->fresh()->last_polled_at)->not->toBeNull();
});
