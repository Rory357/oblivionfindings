<?php

use App\Domain\It\Exceptions\ItInboundHeaderException;
use App\Domain\It\Services\ItEmailMessageIdentifiers;

test('email identifiers normalize equivalent quoting and outer comments without changing opaque case', function () {
    $parser = new ItEmailMessageIdentifiers;
    expect($parser->messageId(" (comment (nested)) <\"a\\bc\"@Mail.test>\r\n\t(last)"))->toBe('<abc@Mail.test>')
        ->and($parser->messageId('<abc@Mail.test>'))->toBe('<abc@Mail.test>')
        ->and($parser->hash('<ABC@Mail.test>'))->not->toBe($parser->hash('<abc@Mail.test>'))
        ->and($parser->messageId('<"a b"@[127.0.0.1]>'))->toBe('<"a b"@[127.0.0.1]>');
});

test('email references preserve ordered ancestry and exact identifiers longer than the legacy column', function () {
    $parser = new ItEmailMessageIdentifiers;
    $long = '<'.str_repeat('a', 300).'@example.test>';
    expect($parser->messageId($long))->toBe($long)
        ->and($parser->references('<parent@mail> (x)'.$long."\r\n\t<parent@mail>"))
        ->toBe(['<parent@mail>', $long, '<parent@mail>'])
        ->and($parser->messageId(null))->toBeNull()
        ->and($parser->references(''))->toBe([]);
});

test('malformed or ambiguous identification fields are rejected instead of truncated', function (string $header) {
    expect(fn () => (new ItEmailMessageIdentifiers)->messageId($header))->toThrow(ItInboundHeaderException::class);
})->with([
    '<first@mail> <second@mail>', '<same@mail><same@mail>', 'id-without-brackets', '<a@mail',
    '<a..b@mail>', '<@mail>', '<a@>', '<a@mail> trailing', "<a@mail>\nBcc: hidden@test",
    "<a@mail>\r\nBcc: hidden@test", "<a\0@mail>", '<a(comment)@mail>', '<a@ma il>',
    '(unclosed <a@mail>', '<"unclosed@mail>', '<a@[unclosed>', '<é@mail>',
    "\r\n", "\n", "\0", "\r\n\0",
]);

test('identification parsing has explicit total size, identifier count and nesting bounds', function () {
    $parser = new ItEmailMessageIdentifiers;
    foreach ([str_repeat(' ', 16385).'<a@mail>', '<'.str_repeat('a', 999).'@mail>',
        str_repeat('<a@mail>', 101), str_repeat('(', 9).'x'.str_repeat(')', 9).'<a@mail>'] as $header) {
        expect(fn () => $parser->references($header))->toThrow(ItInboundHeaderException::class);
    }
    expect($parser->references(str_repeat('<a@mail>', 100)))->toHaveCount(100);
});
