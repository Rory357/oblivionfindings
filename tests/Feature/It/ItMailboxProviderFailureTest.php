<?php

use App\Contracts\CalendarOAuthToken;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\Integration\MailboxResponseBody;
use App\Services\MicrosoftGraphService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function itFailureToken(bool $expired = false): CalendarOAuthToken
{
    return new class($expired) implements CalendarOAuthToken
    {
        public ?string $access = 'synthetic-access';

        public ?string $refresh = 'synthetic-refresh';

        public array $stored = [];

        public function __construct(public bool $expired) {}

        public function getAccessToken(): ?string
        {
            return $this->access;
        }

        public function getRefreshToken(): ?string
        {
            return $this->refresh;
        }

        public function needsRefresh(): bool
        {
            return $this->expired;
        }

        public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void
        {
            $this->stored[] = [$accessToken, $refreshToken, $expiresInSeconds];
            $this->access = $accessToken;
            $this->refresh = $refreshToken ?? $this->refresh;
            $this->expired = false;
        }
    };
}

function itFailureService(string $provider, CalendarOAuthToken $token): GoogleGmailService|MicrosoftGraphService
{
    return $provider === 'google' ? new GoogleGmailService($token) : new MicrosoftGraphService($token);
}

test('oversized provider bodies cannot become successful empty inboxes', function (string $provider, bool $declared) {
    $size = MailboxResponseBody::DEFAULT_LIMIT + 1;
    Http::fake(['*' => Http::response(str_repeat('x', $size), 200, $declared ? ['Content-Length' => (string) $size] : [])]);
    try {
        itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test');
        $this->fail('An oversized response cannot be a successful poll.');
    } catch (MailboxProviderFailure $failure) {
        expect($failure->reason)->toBe('response_too_large')->and($failure->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with(['google', 'microsoft'])->with([true, false]);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.microsoft.tenant' => 'organizations',
        'services.microsoft.client_id' => 'synthetic-microsoft-client',
        'services.microsoft.client_secret' => 'synthetic-microsoft-secret',
        'services.google.client_id' => 'synthetic-google-client',
        'services.google.client_secret' => 'synthetic-google-secret',
    ]);
});

test('mailbox list failures preserve typed safe reasons without provider response content', function (string $provider, int $status, string $reason) {
    Http::fake(['*' => Http::response(['error' => ['message' => 'PRIVATE token body subject']], $status)]);
    try {
        itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test');
        $this->fail('A failed provider read must not be an empty successful inbox.');
    } catch (MailboxProviderFailure $failure) {
        expect($failure->reason)->toBe($reason);
        expect($failure->getMessage())->not->toContain('PRIVATE');
        expect($failure->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with(['google', 'microsoft'])->with([
    [401, 'authentication'], [403, 'permission'], [503, 'unavailable'], [400, 'rejected'],
]);

test('rate limits retain Retry-After without immediately repeating provider requests', function (string $provider) {
    Http::fake(['*' => Http::response([], 429, ['Retry-After' => '123'])]);
    try {
        itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test');
        $this->fail('Expected a rate limit.');
    } catch (ProviderRateLimited $failure) {
        expect($failure->retryAfterSeconds)->toBe(123);
    }
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);

test('timeouts do not leak request URLs in mailbox failures', function (string $provider) {
    Http::fake(fn () => throw new ConnectionException('PRIVATE credential and mailbox URL'));
    expect(fn () => itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'temporarily unavailable');
})->with(['google', 'microsoft']);

test('expired mailbox tokens without refresh cannot fall through to the old access token', function (string $provider) {
    Http::fake();
    $token = itFailureToken(true);
    $token->refresh = null;
    expect(fn () => itFailureService($provider, $token)->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'authorization expired');
    Http::assertNothingSent();
    expect($token->stored)->toBe([]);
})->with(['google', 'microsoft']);

test('revoked refresh is safe and never sends a subsequent mailbox request', function (string $provider) {
    Http::fake(['*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'PRIVATE token'], 400)]);
    $token = itFailureToken(true);
    expect(fn () => itFailureService($provider, $token)->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'authorization expired');
    Http::assertSentCount(1);
    expect($token->stored)->toBe([]);
})->with(['google', 'microsoft']);

test('malformed refresh success never overwrites valid token storage', function (string $provider, array $payload) {
    Http::fake(['*' => Http::response($payload, 200)]);
    $token = itFailureToken(true);
    expect(fn () => itFailureService($provider, $token)->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'invalid response');
    expect($token->stored)->toBe([]);
    Http::assertSentCount(1);
})->with(['google', 'microsoft'])->with([
    [['expires_in' => 3600]],
    [['access_token' => '', 'expires_in' => 3600]],
    [['access_token' => 'new', 'expires_in' => 0]],
    [['access_token' => 'new', 'expires_in' => 3600, 'refresh_token' => []]],
    [['access_token' => 'new', 'expires_in' => PHP_INT_MAX]],
]);

test('successful refresh stores rotation and the next mail read uses the new token', function (string $provider) {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600]),
        'gmail.googleapis.com/*' => Http::response('{}'),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);
    $token = itFailureToken(true);
    expect(itFailureService($provider, $token)->listUnreadMessages('support@example.test'))->toBe([]);
    expect($token->stored)->toBe([['new-access', 'new-refresh', 3600]]);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer new-access'));
    Http::assertSentCount(2);
})->with(['google', 'microsoft']);

test('missing refresh client configuration fails locally without sending credentials', function (string $provider) {
    Http::fake();
    config(["services.{$provider}.client_secret" => null]);
    expect(fn () => itFailureService($provider, itFailureToken(true))->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'client configuration is missing');
    Http::assertNothingSent();
})->with(['google', 'microsoft']);

test('unrepresentable retry intervals are rejected as invalid provider metadata', function (string $provider) {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => (string) PHP_INT_MAX])]);
    expect(fn () => itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'invalid response');
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);

test('mark-read failures never return successful acknowledgement', function (string $provider) {
    Http::fake(['*' => Http::response(['error' => 'PRIVATE'], 503)]);
    expect(fn () => itFailureService($provider, itFailureToken())->markRead('support@example.test', 'message-1'))
        ->toThrow(MailboxProviderFailure::class, 'temporarily unavailable');
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);

test('invalid JSON list response cannot become a successful empty inbox', function (string $provider) {
    Http::fake(['*' => Http::response('<html>provider proxy error</html>', 200)]);
    expect(fn () => itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'invalid response');
})->with(['google', 'microsoft']);

test('Gmail detail failure does not silently drop a listed message', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/message-1*' => Http::response([], 503),
        'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response(['messages' => [['id' => 'message-1']]]),
    ]);
    expect(fn () => itFailureService('google', itFailureToken())->listUnreadMessages())
        ->toThrow(MailboxProviderFailure::class, 'temporarily unavailable');
    Http::assertSentCount(2);
});

test('mailbox requests use bounded timeouts and reject redirects without a follow-up', function (string $provider) {
    Http::fake(function ($request, $options) {
        expect($options['connect_timeout'])->toBe(5);
        expect($options['timeout'])->toBe(20);
        expect($options['allow_redirects'])->toBeFalse();

        return Http::response('', 302, ['Location' => 'https://unapproved.example.test/collect']);
    });
    expect(fn () => itFailureService($provider, itFailureToken())->listUnreadMessages('support@example.test'))
        ->toThrow(MailboxProviderFailure::class, 'rejected the request');
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);
