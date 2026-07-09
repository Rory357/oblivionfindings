<?php

use App\Contracts\CalendarOAuthToken;
use App\Services\GoogleGmailService;
use Illuminate\Support\Facades\Http;

/*
 * E5 — Gmail read for the OAuth mailbox poller. Faked HTTP throughout.
 */

function itGmailService(): GoogleGmailService
{
    $token = new class implements CalendarOAuthToken
    {
        public function getAccessToken(): ?string
        {
            return 'fake-google-token';
        }

        public function getRefreshToken(): ?string
        {
            return null;
        }

        public function needsRefresh(): bool
        {
            return false;
        }

        public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void {}
    };

    return new GoogleGmailService($token);
}

function itGmailB64(string $text): string
{
    return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
}

test('listUnreadMessages fetches each stub and normalises headers and bodies', function () {
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages/gm-1*' => Http::response([
            'snippet' => 'plain snippet',
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [
                    ['name' => 'From', 'value' => 'Worker <worker@example.test>'],
                    ['name' => 'Subject', 'value' => 'Printer jammed'],
                    ['name' => 'Message-ID', 'value' => '<gm1@mail>'],
                    ['name' => 'In-Reply-To', 'value' => '<orig@mail>'],
                ],
                'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => itGmailB64('Plain wins.')]],
                    ['mimeType' => 'text/html', 'body' => ['data' => itGmailB64('<p>Html loses.</p>')]],
                ],
            ],
        ], 200),
        'gmail.googleapis.com/gmail/v1/users/me/messages/gm-2*' => Http::response([
            'snippet' => 'fallback snippet',
            'payload' => [
                'mimeType' => 'text/html',
                'headers' => [
                    ['name' => 'From', 'value' => 'bare@example.test'], // no display name
                    ['name' => 'Subject', 'value' => 'Html only'],
                ],
                'body' => ['data' => itGmailB64('<b>Bold &amp; html</b> body')],
            ],
        ], 200),
        'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
            'messages' => [['id' => 'gm-1'], ['id' => 'gm-2']],
        ], 200),
    ]);

    $messages = itGmailService()->listUnreadMessages();

    expect($messages)->toHaveCount(2);
    expect($messages[0])->toMatchArray([
        'remote_id' => 'gm-1',
        'from' => 'worker@example.test', // parsed out of "Name <addr>"
        'subject' => 'Printer jammed',
        'text' => 'Plain wins.', // text/plain preferred over html
        'message_id' => '<gm1@mail>',
        'in_reply_to' => '<orig@mail>',
    ]);
    expect($messages[1]['from'])->toBe('bare@example.test');
    expect($messages[1]['text'])->toBe('Bold & html body'); // html stripped + entities decoded

    Http::assertSent(fn ($request) => str_contains($request->url(), rawurlencode('is:unread in:inbox'))
        && $request->hasHeader('Authorization', 'Bearer fake-google-token'));
});

test('listUnreadMessages returns empty on a Gmail error', function () {
    Http::fake(['gmail.googleapis.com/*' => Http::response(['error' => 'denied'], 403)]);

    expect(itGmailService()->listUnreadMessages())->toBe([]);
});

test('markRead removes the UNREAD label', function () {
    Http::fake(['gmail.googleapis.com/*' => Http::response([], 200)]);

    expect(itGmailService()->markRead('me', 'gm-1'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/users/me/messages/gm-1/modify')
        && $request['removeLabelIds'] === ['UNREAD']);
});
