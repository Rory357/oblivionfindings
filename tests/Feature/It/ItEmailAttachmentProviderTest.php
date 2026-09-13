<?php

use App\Contracts\CalendarOAuthToken;
use App\Domain\It\Exceptions\ItInboundContentException;
use App\Services\GoogleGmailService;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\MicrosoftGraphService;
use Illuminate\Support\Facades\Http;

function itAttachmentProviderToken(): CalendarOAuthToken
{
    return new class implements CalendarOAuthToken
    {
        public function getAccessToken(): ?string
        {
            return 'synthetic-access';
        }

        public function getRefreshToken(): ?string
        {
            return null;
        }

        public function needsRefresh(): bool
        {
            return false;
        }

        public function storeRefreshedToken(string $accessToken, ?string $refreshToken, ?int $expiresInSeconds): void
        {
            throw new RuntimeException('Unexpected token refresh.');
        }
    };
}

function itAttachmentGraphRow(string $id = 'file/1'): array
{
    return ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => $id, 'name' => 'evidence.txt',
        'size' => 3, 'contentType' => 'text/plain', 'isInline' => true];
}

function itAttachmentProviderMessage(string $provider): array
{
    $headers = [['name' => 'From', 'value' => 'worker@demo.test'], ['name' => 'Message-ID', 'value' => '<file-message@demo.test>']];

    return $provider === 'google' ? ['id' => 'message-1', 'payload' => ['mimeType' => 'multipart/mixed', 'headers' => $headers, 'parts' => [
        ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode('Outer report'), 'size' => 12]],
        ['mimeType' => 'text/plain', 'filename' => 'evidence.txt', 'body' => ['attachmentId' => 'file/1', 'size' => 3]],
    ]]] : ['id' => 'message-1', 'hasAttachments' => false, 'from' => ['emailAddress' => ['address' => 'worker@demo.test']],
        'internetMessageId' => '<file-message@demo.test>', 'internetMessageHeaders' => $headers,
        'body' => ['contentType' => 'text', 'content' => 'Outer report']];
}

beforeEach(fn () => Http::preventStrayRequests());

test('existing providers retrieve manifests and exact file bytes separately from the outer report', function (string $provider) {
    $messageUrl = $provider === 'google' ? 'https://gmail.googleapis.com/gmail/v1/users/me/messages/message-1'
        : 'https://graph.microsoft.com/v1.0/users/support%40demo.test/messages/message-1';
    $manifest = $messageUrl.'/attachments';
    $content = $manifest.'/file%2F1';
    $attempts = 0;
    Http::fake(function ($request) use ($provider, $messageUrl, $manifest, $content, &$attempts) {
        $path = explode('?', $request->url())[0];
        if ($path === $content) {
            if (++$attempts === 1) {
                return Http::response([], 503);
            }

            return Http::response($provider === 'google' ? ['size' => 3, 'data' => 'YWJj'] : [...itAttachmentGraphRow(), 'contentBytes' => 'YWJj']);
        }
        if ($path === $manifest && $provider === 'microsoft') {
            return Http::response(['value' => [itAttachmentGraphRow()]]);
        }
        if ($path === $messageUrl) {
            return Http::response(itAttachmentProviderMessage($provider));
        }
        throw new RuntimeException('Unexpected synthetic attachment URL.');
    });
    $service = $provider === 'google' ? new GoogleGmailService(itAttachmentProviderToken()) : new MicrosoftGraphService(itAttachmentProviderToken());
    $message = $service->readMessage('support@demo.test', 'message-1', includeAttachments: true);
    expect($message['text'])->toBe('Outer report')->and(count($message['attachments']))->toBe(1)
        ->and($message['attachments'][0]->name)->toBe('evidence.txt');
    expect(fn () => $service->readAttachmentContents('support@demo.test', 'message-1', $message['attachments'][0]))
        ->toThrow(MailboxProviderFailure::class, 'temporarily unavailable');
    expect($service->readAttachmentContents('support@demo.test', 'message-1', $message['attachments'][0]))->toBe('abc')
        ->and($attempts)->toBe(2);
    Http::assertSentCount($provider === 'google' ? 3 : 4);
})->with(['google', 'microsoft']);

test('Graph attachment pages retain every allowed file and reject unsafe continuation scopes', function (?string $badUrl) {
    $base = 'https://graph.microsoft.com/v1.0/users/support%40demo.test/messages/message-1';
    $next = $badUrl ?? $base.'/attachments?$skiptoken=opaque';
    Http::fake(function ($request) use ($base, $next) {
        if ($request->url() === $next && str_starts_with($next, $base.'/attachments?')) {
            return Http::response(['value' => [itAttachmentGraphRow('file-2')]]);
        }
        if (str_starts_with($request->url(), $base.'/attachments?')) {
            return Http::response(['value' => [itAttachmentGraphRow()], '@odata.nextLink' => $next]);
        }
        if (str_starts_with($request->url(), $base.'?')) {
            return Http::response(itAttachmentProviderMessage('microsoft'));
        }
        throw new RuntimeException('An unapproved continuation was requested.');
    });
    $service = new MicrosoftGraphService(itAttachmentProviderToken());
    if ($badUrl !== null) {
        expect(fn () => $service->readMessage('support@demo.test', 'message-1', true))->toThrow(MailboxProviderFailure::class);
        Http::assertSentCount(2);
    } else {
        expect(count($service->readMessage('support@demo.test', 'message-1', true)['attachments']))->toBe(2);
        Http::assertSentCount(3);
    }
})->with([null, 'https://unapproved.invalid/attachments?page=2', 'https://graph.microsoft.com/v1.0/users/other%40demo.test/messages/message-1/attachments?page=2', 'https://graph.microsoft.com/v1.0/users/support%40demo.test/messages/other/attachments?page=2']);

test('Graph attachment pagination cannot repeat a cursor or silently drop an excess file', function (string $mode) {
    $base = 'https://graph.microsoft.com/v1.0/users/support%40demo.test/messages/message-1';
    Http::fake(function ($request) use ($base, $mode) {
        if (str_starts_with($request->url(), $base.'/attachments?')) {
            return Http::response($mode === 'excess' ? ['value' => array_map(fn ($i) => itAttachmentGraphRow('file-'.$i), range(1, 6))]
                : ['value' => [], '@odata.nextLink' => $base.'/attachments?$skiptoken=same']);
        }

        return Http::response(itAttachmentProviderMessage('microsoft'));
    });
    expect(fn () => (new MicrosoftGraphService(itAttachmentProviderToken()))->readMessage('support@demo.test', 'message-1', true))
        ->toThrow($mode === 'excess' ? ItInboundContentException::class : MailboxProviderFailure::class);
    Http::assertSentCount($mode === 'excess' ? 2 : 3);
})->with(['excess', 'cycle']);
