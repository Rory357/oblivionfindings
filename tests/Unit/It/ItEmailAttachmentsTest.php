<?php

use App\Domain\It\Exceptions\ItInboundContentException;
use App\Domain\It\Services\ItEmailAttachments;
use App\Domain\It\Services\ItEmailContent;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\MailboxResponseBody;

function itGmailFilePart(array $overrides = []): array
{
    return array_replace(['mimeType' => 'text/plain', 'filename' => 'report.txt', 'headers' => [],
        'body' => ['size' => 3, 'data' => base64_encode('abc')]], $overrides);
}

function itGraphFilePart(array $overrides = []): array
{
    return array_replace(['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'file-1',
        'name' => 'report.txt', 'size' => 3, 'contentType' => 'text/plain', 'isInline' => false], $overrides);
}

test('Gmail file extraction keeps named text files and inline images separate from the report', function () {
    $service = new ItEmailAttachments;
    $files = $service->gmail(['mimeType' => 'multipart/mixed', 'parts' => [
        ['mimeType' => 'text/plain', 'body' => ['size' => 4, 'data' => base64_encode('body')]],
        itGmailFilePart(),
        itGmailFilePart(['filename' => '', 'mimeType' => 'image/png', 'body' => ['size' => 3, 'attachmentId' => 'image-2']]),
    ]]);
    expect(count($files))->toBe(2)->and($files[0]->name)->toBe('report.txt')
        ->and($files[1]->name)->toBe('inline-image-2.png')->and($files[1]->inline)->toBeTrue();
    expect($service->contents($files[0], fn () => throw new RuntimeException('Inline file must not fetch')))->toBe('abc');
    $reads = [];
    expect($service->contents($files[1], function ($id) use (&$reads) {
        $reads[] = $id;

        return ['size' => 3, 'data' => base64_encode('png')];
    }))->toBe('png')->and($reads)->toBe(['image-2']);
});

test('Graph inline and remotely stored files preserve exact metadata on retrieval', function () {
    $service = new ItEmailAttachments;
    $rows = [itGraphFilePart(['contentBytes' => base64_encode('abc')]),
        itGraphFilePart(['id' => 'image-2', 'name' => '', 'contentType' => 'image/png', 'isInline' => true])];
    $files = $service->graph($rows);
    expect($service->contents($files[0], fn () => throw new RuntimeException('Already embedded')))->toBe('abc');
    expect($files[1]->name)->toBe('inline-image-2.png')
        ->and($service->contents($files[1], fn ($id) => [...$rows[1], 'contentBytes' => base64_encode('png')]))->toBe('png');
});

test('both provider descriptors retain the full five by ten MiB allowance before downloading', function (string $provider) {
    $service = new ItEmailAttachments;
    $parts = [];
    for ($i = 1; $i <= 5; $i++) {
        $parts[] = $provider === 'google'
            ? itGmailFilePart(['body' => ['size' => ItEmailAttachments::MAX_FILE_BYTES, 'attachmentId' => 'id-'.$i]])
            : itGraphFilePart(['id' => 'id-'.$i, 'size' => ItEmailAttachments::MAX_FILE_BYTES]);
    }
    $files = $provider === 'google' ? $service->gmail(['mimeType' => 'multipart/mixed', 'parts' => $parts]) : $service->graph($parts);
    expect(array_sum(array_map(fn ($file) => $file->size, $files)))->toBe(52428800);
    $content = str_repeat('x', ItEmailAttachments::MAX_FILE_BYTES);
    $encoded = base64_encode($content);
    $body = $provider === 'google' ? ['size' => strlen($content), 'data' => $encoded] : [...$parts[0], 'contentBytes' => $encoded];
    expect($service->contents($files[0], fn () => $body))->toBe($content);
})->with(['google', 'microsoft']);

test('file response budget allows worst-case JSON escaped standard base64', function () {
    $encoded = base64_encode(str_repeat("\xff", ItEmailAttachments::MAX_FILE_BYTES));
    expect(strlen(json_encode(['contentBytes' => $encoded], JSON_THROW_ON_ERROR)))->toBeLessThan(ItEmailAttachments::FILE_RESPONSE_BYTES)
        ->and(ItEmailAttachments::FILE_RESPONSE_BYTES)->toBeLessThanOrEqual(MailboxResponseBody::MAX_LIMIT)
        ->and(ItEmailAttachments::MESSAGE_RESPONSE_BYTES)->toBeLessThanOrEqual(MailboxResponseBody::MAX_LIMIT);
});

test('unsafe or unsupported filenames are rejected without choosing a replacement path', function (string $name) {
    expect(fn () => (new ItEmailAttachments)->gmail([...itGmailFilePart(), 'filename' => $name]))->toThrow(ItInboundContentException::class);
    expect(fn () => (new ItEmailAttachments)->graph([itGraphFilePart(['name' => $name])]))->toThrow(ItInboundContentException::class);
})->with(['../report.txt', 'C:\\report.txt', 'folder/report.txt', "null\0.txt", 'CON.txt', 'report.txt:payload', 'report.html', 'image.svg', 'forwarded.eml', 'report.txt ', "report\u{202e}txt.exe", str_repeat('a', 256).'.txt', "\xff.txt"]);

test('metadata limits reject oversized files and collections before content decoding', function (string $provider) {
    $service = new ItEmailAttachments;
    $large = $provider === 'google'
        ? fn () => $service->gmail(itGmailFilePart(['body' => ['size' => ItEmailAttachments::MAX_FILE_BYTES + 1, 'attachmentId' => 'id']]))
        : fn () => $service->graph([itGraphFilePart(['size' => ItEmailAttachments::MAX_FILE_BYTES + 1])]);
    expect($large)->toThrow(ItInboundContentException::class);
    $many = $provider === 'google'
        ? fn () => $service->gmail(['mimeType' => 'multipart/mixed', 'parts' => array_fill(0, 6, itGmailFilePart())])
        : fn () => $service->graph(array_fill(0, 6, itGraphFilePart()));
    expect($many)->toThrow(ItInboundContentException::class);
})->with(['google', 'microsoft']);

test('non-file Graph objects cannot become downloaded evidence', function (string $type) {
    expect(fn () => (new ItEmailAttachments)->graph([itGraphFilePart(['@odata.type' => $type])]))->toThrow(ItInboundContentException::class);
})->with(['#microsoft.graph.referenceAttachment', '#microsoft.graph.itemAttachment', '']);

test('Gmail malformed nesting and conflicting body sources cannot hide file evidence', function () {
    $service = new ItEmailAttachments;
    $deep = itGmailFilePart();
    for ($i = 0; $i <= ItEmailContent::MAX_DEPTH; $i++) {
        $deep = ['mimeType' => 'multipart/mixed', 'parts' => [$deep]];
    }
    foreach ([$deep, ['mimeType' => 'multipart/mixed', 'parts' => array_fill(0, 101, ['mimeType' => 'text/plain'])],
        itGmailFilePart(['body' => ['size' => 3, 'data' => 'YWJj', 'attachmentId' => 'id']]),
        ['mimeType' => 'text/plain', 'parts' => [itGmailFilePart()]],
        itGmailFilePart(['mimeType' => 'message/rfc822']),
    ] as $payload) {
        expect(fn () => $service->gmail($payload))->toThrow(ItInboundContentException::class);
    }
});

test('duplicate external file identities are rejected', function (string $provider) {
    $service = new ItEmailAttachments;
    expect($provider === 'google'
        ? fn () => $service->gmail(['mimeType' => 'multipart/mixed', 'parts' => array_fill(0, 2, itGmailFilePart(['body' => ['size' => 3, 'attachmentId' => 'same']]))])
        : fn () => $service->graph(array_fill(0, 2, itGraphFilePart())))
        ->toThrow(ItInboundContentException::class);
})->with(['google', 'microsoft']);

test('Graph content lookup cannot replace the reviewed file metadata', function (array $change) {
    $service = new ItEmailAttachments;
    $file = $service->graph([itGraphFilePart()])[0];
    expect(fn () => $service->contents($file, fn () => array_replace(itGraphFilePart(['contentBytes' => 'YWJj']), $change)))
        ->toThrow(ItInboundContentException::class);
})->with(array_map(fn (array $change) => [$change], [['id' => 'other'], ['name' => 'other.txt'], ['size' => 4], ['contentType' => 'image/png'], ['isInline' => true], ['@odata.type' => '#microsoft.graph.itemAttachment'], ['contentBytes' => 'YWJj!']]));

test('external file transport failures remain retryable without invented content', function () {
    $service = new ItEmailAttachments;
    $file = $service->gmail(itGmailFilePart(['body' => ['size' => 3, 'attachmentId' => 'id']]))[0];
    $failure = new MailboxProviderFailure('unavailable');
    try {
        $service->contents($file, fn () => throw $failure);
        test()->fail('Expected the original retryable failure.');
    } catch (MailboxProviderFailure $caught) {
        expect($caught)->toBe($failure);
    }
});
