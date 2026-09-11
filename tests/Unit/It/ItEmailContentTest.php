<?php

use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Services\ItEmailContent;

function itContentPart(string $text, string $mime = 'text/plain'): array
{
    return ['mimeType' => $mime, 'body' => ['data' => base64_encode($text), 'size' => strlen($text)]];
}

test('body selection ignores named text and forwarded attachments and prefers the plain alternative', function () {
    $payload = ['mimeType' => 'multipart/mixed', 'parts' => [
        [...itContentPart('Do not use this attached text'), 'filename' => 'diagnostic.txt'],
        ['mimeType' => 'message/rfc822', 'parts' => [itContentPart('Inner message')]],
        ['mimeType' => 'multipart/alternative', 'parts' => [itContentPart('<p>HTML alternative</p>', 'text/html'), itContentPart('Actual report')]],
        itContentPart('Additional inline details'),
    ]];
    expect((new ItEmailContent)->gmail($payload, fn () => throw new RuntimeException('Unexpected external read')))
        ->toBe("Actual report\n\nAdditional inline details");
});

test('external body is fetched completely and never replaced by a provider snippet', function () {
    $ids = [];
    $text = 'Complete external report';
    $payload = ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'opaque/body', 'size' => strlen($text)]];
    expect((new ItEmailContent)->gmail($payload, function ($id) use (&$ids, $text) {
        $ids[] = $id;

        return ['data' => base64_encode($text), 'size' => strlen($text)];
    }))->toBe($text)->and($ids)->toBe(['opaque/body']);
});

test('charset decoding and html block boundaries retain readable content', function () {
    $part = itContentPart("caf\xe9", 'text/plain');
    $part['headers'] = [['name' => 'Content-Type', 'value' => 'text/plain; charset="ISO-8859-1"']];
    expect((new ItEmailContent)->gmail($part, fn () => []))->toBe('café')
        ->and((new ItEmailContent)->graph(['contentType' => 'html', 'content' => '<style>.hidden{}</style><p>One</p><p>Two &amp; three</p><script>bad()</script>']))
        ->toBe("One\nTwo & three");
});

test('malformed base64 and incomplete declared body sizes are rejected', function (array $body) {
    expect(fn () => (new ItEmailContent)->decode($body))->toThrow(ItInboundContentException::class);
})->with([
    [['data' => '%%not-base64']], [['data' => 'A']], [['data' => 'AB==']],
    [['data' => 'YQ=']], [['data' => 'YQ==', 'size' => 2]], [['data' => ['YQ==']]],
    [['data' => 'YQ==', 'size' => -1]], [['size' => 1]],
]);

test('valid padded and unpadded base64url body data remains accepted', function (string $data) {
    expect((new ItEmailContent)->decode(['data' => $data, 'size' => 1]))->toBe('a');
})->with(['YQ', 'YQ==']);

test('body size and MIME structure limits fail before unbounded traversal or external fetch', function (string $case) {
    $part = itContentPart('x');
    if ($case === 'depth') {
        for ($i = 0; $i < 14; $i++) {
            $part = ['mimeType' => 'multipart/mixed', 'parts' => [$part]];
        }
    } elseif ($case === 'parts') {
        $part = ['mimeType' => 'multipart/mixed', 'parts' => array_fill(0, 101, $part)];
    } elseif ($case === 'text') {
        $part = itContentPart(str_repeat('x', 100001));
    } elseif ($case === 'external') {
        $part = ['mimeType' => 'text/plain', 'body' => ['attachmentId' => 'oversize', 'size' => 1048577]];
    } elseif ($case === 'missing') {
        $part = ['mimeType' => 'text/plain', 'body' => []];
    } elseif ($case === 'encoding') {
        $part = itContentPart("\xff");
    }
    expect(fn () => (new ItEmailContent)->gmail($part, fn () => throw new RuntimeException('Must not fetch')))
        ->toThrow(ItInboundContentException::class);
})->with(['depth', 'parts', 'text', 'external', 'missing', 'encoding']);

test('automatic and delivery-report messages require review before ticketing', function (array $headers, ?string $mime, string $reason) {
    try {
        (new ItEmailContent)->assertHumanMessage($headers, $mime);
        test()->fail('Automatic content was accepted');
    } catch (ItInboundContentException $exception) {
        expect($exception->reason)->toBe($reason);
    }
})->with([
    [['auto-submitted' => 'auto-replied'], null, 'automatic_message'],
    [['auto-submitted' => '(outer (nested)) auto-replied (reply); x-source="worker (one)"'], null, 'automatic_message'],
    [['auto-submitted' => 'auto-generated; owner-email=sender@example.test'], null, 'automatic_message'],
    [['content-type' => 'multipart/report; report-type=delivery-status'], null, 'delivery_report'],
    [['content-type' => 'text/plain'], 'multipart/report', 'delivery_report'],
    [[], 'message/disposition-notification', 'delivery_report'],
    [['content-type' => '(report) multipart (type) / report; report-type=delivery-status'], null, 'delivery_report'],
    [['return-path' => '(empty envelope) <>'], null, 'automatic_message'],
]);

test('an explicitly human empty body stays empty and is not filled with a preview', function () {
    (new ItEmailContent)->assertHumanMessage(['auto-submitted' => '(human) no (manual); x-source="a;b"']);
    expect((new ItEmailContent)->gmail(['mimeType' => 'text/plain', 'body' => ['size' => 0]], fn () => []))->toBe('')
        ->and((new ItEmailContent)->graph(['contentType' => 'text', 'content' => '']))->toBe('');
});

test('conflicting inline and external body references require review without fetching either', function () {
    expect(fn () => (new ItEmailContent)->gmail(['mimeType' => 'text/plain', 'body' => [
        'attachmentId' => 'external', 'data' => base64_encode('Conflicting inline body'),
    ]], fn () => throw new RuntimeException('Must not fetch')))->toThrow(ItInboundContentException::class);
});
