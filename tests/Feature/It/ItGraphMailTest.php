<?php

use App\Contracts\CalendarOAuthToken;
use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\Http;

/*
 * E2 — Graph mail-read for the OAuth mailbox poller. Faked HTTP throughout:
 * no token refresh, no live Graph.
 */

function itGraphMailService(): MicrosoftGraphService
{
    $token = new class implements CalendarOAuthToken
    {
        public function getAccessToken(): ?string
        {
            return 'fake-access-token';
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

    return new MicrosoftGraphService($token);
}

test('listUnreadMessages filters unread and normalises for the ingestor', function () {
    Http::fake([
        'graph.microsoft.com/v1.0/users/support%40example.test/mailFolders/inbox/messages*' => Http::response([
            'value' => [
                [
                    'id' => 'AAMkAGraphId1',
                    'subject' => 'Printer jammed',
                    'from' => ['emailAddress' => ['address' => 'worker@example.test', 'name' => 'Worker']],
                    'body' => ['contentType' => 'html', 'content' => '<p>It has been <b>stuck</b> all morning.</p>'],
                    'bodyPreview' => 'It has been stuck all morning.',
                    'internetMessageId' => '<msg1@mail.example.test>',
                ],
                [
                    'id' => 'AAMkAGraphId2',
                    'subject' => 'Re: IT-000012 still broken',
                    'from' => ['emailAddress' => ['address' => 'other@example.test']],
                    'body' => ['contentType' => 'text', 'content' => 'Any update?'],
                    'bodyPreview' => 'Any update?',
                    'internetMessageId' => '<msg2@mail.example.test>',
                ],
            ],
        ], 200),
    ]);

    $messages = itGraphMailService()->listUnreadMessages('support@example.test');

    expect($messages)->toHaveCount(2);
    expect($messages[0])->toMatchArray([
        'graph_id' => 'AAMkAGraphId1',
        'from' => 'worker@example.test',
        'subject' => 'Printer jammed',
        'text' => 'It has been stuck all morning.', // html stripped
        'message_id' => '<msg1@mail.example.test>',
        'in_reply_to' => null,
    ]);
    expect($messages[1]['text'])->toBe('Any update?'); // text body passes through

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/users/support%40example.test/mailFolders/inbox/messages')
            && str_contains($request->url(), rawurlencode('isRead eq false'))
            && $request->hasHeader('Authorization', 'Bearer fake-access-token');
    });
});

test('listUnreadMessages returns empty on a Graph error and drops malformed rows', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => 'denied'], 403)]);
    expect(itGraphMailService()->listUnreadMessages('support@example.test'))->toBe([]);

    Http::fake([
        'graph.microsoft.com/*' => Http::response([
            'value' => [
                ['id' => '', 'from' => ['emailAddress' => ['address' => 'x@example.test']]],
                ['id' => 'ok', 'from' => ['emailAddress' => ['address' => '']]],
            ],
        ], 200),
    ]);
    expect(itGraphMailService()->listUnreadMessages('support@example.test'))->toBe([]);
});

test('markRead PATCHes isRead=true on the mailbox message', function () {
    Http::fake(['graph.microsoft.com/*' => Http::response([], 200)]);

    expect(itGraphMailService()->markRead('support@example.test', 'AAMkAGraphId1'))->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->method() === 'PATCH'
            && str_contains($request->url(), '/users/support%40example.test/messages/AAMkAGraphId1')
            && $request['isRead'] === true;
    });
});
