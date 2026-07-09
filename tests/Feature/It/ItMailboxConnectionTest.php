<?php

use App\Models\ItMailboxConnection;
use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * E3 — the support-mailbox OAuth connection (mirrors CalendarSyncConnection).
 */

function itMailboxConnection(array $overrides = []): ItMailboxConnection
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

test('tokens are encrypted at rest and hidden from serialisation', function () {
    $connection = itMailboxConnection();

    $raw = DB::table('it_mailbox_connections')->where('id', $connection->id)->first();
    expect($raw->access_token)->not->toBe('access-123');   // ciphertext in the column
    expect($connection->access_token)->toBe('access-123'); // transparent decrypt
    expect($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('needsRefresh trips inside the 5-minute expiry window and on a missing expiry', function () {
    expect(itMailboxConnection()->needsRefresh())->toBeFalse(); // expires in 1h

    $stale = itMailboxConnection([
        'provider' => ItMailboxConnection::PROVIDER_GOOGLE, // dodge the (tenant, provider) unique
        'token_expires_at' => now()->addMinutes(2),
    ]);
    expect($stale->needsRefresh())->toBeTrue();

    $stale->update(['token_expires_at' => null]);
    expect($stale->fresh()->needsRefresh())->toBeTrue();
});

test('storeRefreshedToken keeps the old refresh token when the provider does not rotate it', function () {
    $connection = itMailboxConnection();

    $connection->storeRefreshedToken('new-access', null, 1800);

    $connection->refresh();
    expect($connection->access_token)->toBe('new-access');
    expect($connection->refresh_token)->toBe('refresh-456'); // preserved
    expect($connection->token_expires_at->isFuture())->toBeTrue();
});

test('mailboxEmail falls back to the consenting account', function () {
    expect(itMailboxConnection()->mailboxEmail())->toBe('support@example.test');

    $own = itMailboxConnection([
        'provider' => ItMailboxConnection::PROVIDER_GOOGLE,
        'mailbox_email' => null,
    ]);
    expect($own->mailboxEmail())->toBe('admin@example.test');
});

test('the connection drives MicrosoftGraphService as its OAuth token', function () {
    Http::fake([
        'graph.microsoft.com/*' => Http::response(['value' => []], 200),
    ]);

    $connection = itMailboxConnection();
    $service = new MicrosoftGraphService($connection);

    expect($service->listUnreadMessages($connection->mailboxEmail()))->toBe([]);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer access-123'));
});
