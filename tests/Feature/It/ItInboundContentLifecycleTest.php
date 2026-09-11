<?php

use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use Illuminate\Support\Facades\Http;

test('the normalized webhook channel also quarantines automatic messages without notification intent', function (array $metadata, string $reason) {
    Http::preventStrayRequests();
    $sender = User::factory()->create();
    config(['it.inbound_mail.secret' => 'synthetic-test-secret']);
    $message = ['from' => $sender->email, 'subject' => 'Automatic synthetic content', 'text' => 'Private synthetic body',
        'message_id' => '<automatic@example.test>', ...$metadata];
    $this->postJson('/api/it/email/inbound', $message, ['X-IT-Inbound-Secret' => 'synthetic-test-secret'])
        ->assertOk()->assertExactJson(['status' => 'quarantined']);
    $result = ItInboundEmail::query()->sole();
    expect($result->status)->toBe('quarantined')->and($result->quarantine_reason)->toBe($reason)
        ->and($result->body_preview)->toBeNull()->and($result->it_ticket_id)->toBeNull()
        ->and(ItTicket::query()->count())->toBe(0)->and(ItEmailDelivery::query()->count())->toBe(0);
})->with([
    [['auto_submitted' => 'auto-replied'], 'automatic_message'],
    [['auto_submitted' => 'auto-generated'], 'automatic_message'],
    [['content_type' => 'multipart/report; report-type=delivery-status'], 'delivery_report'],
    [['auto_submitted' => '(reply) auto-replied (automatic)'], 'automatic_message'],
    [['return_path' => '(empty) <>'], 'automatic_message'],
]);

test('Gmail external body uses the exact message path and a failed body read remains retryable', function () {
    Http::preventStrayRequests();
    $connection = ItMailboxConnection::create([
        'provider' => 'google', 'status' => 'connected', 'account_email' => 'synthetic@example.test',
        'access_token' => 'synthetic-token', 'token_expires_at' => now()->addHour(),
    ]);
    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        if (str_contains($request->url(), '/attachments/opaque%2Fbody')) {
            $attempts++;

            return $attempts === 1 ? Http::response([], 503) : Http::response(['data' => base64_encode('Complete body'), 'size' => 13]);
        }

        return Http::response(['id' => 'exact-message', 'snippet' => 'Short preview', 'payload' => [
            'mimeType' => 'text/plain', 'headers' => [['name' => 'From', 'value' => 'sender@example.test']],
            'body' => ['attachmentId' => 'opaque/body', 'size' => 13],
        ]]);
    });
    $service = new GoogleGmailService($connection);
    expect(fn () => $service->readMessage('synthetic@example.test', 'exact-message'))->toThrow(MailboxProviderFailure::class);
    expect($service->readMessage('synthetic@example.test', 'exact-message')['text'])->toBe('Complete body');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/exact-message/attachments/opaque%2Fbody'));
    expect(ItTicket::query()->count())->toBe(0);
});

test('a Gmail detail returned for another transport identity is never ingested or acknowledged', function () {
    Http::preventStrayRequests();
    $connection = ItMailboxConnection::create([
        'provider' => 'google', 'status' => 'connected', 'account_email' => 'synthetic@example.test',
        'access_token' => 'synthetic-token', 'token_expires_at' => now()->addHour(),
    ]);
    Http::fake(['*' => Http::response(['id' => 'wrong', 'payload' => [
        'mimeType' => 'text/plain', 'headers' => [], 'body' => ['data' => base64_encode('Wrong record')],
    ]])]);
    expect(fn () => (new GoogleGmailService($connection))->readMessage('synthetic@example.test', 'expected'))
        ->toThrow(MailboxProviderFailure::class);
    Http::assertSentCount(1);
});
