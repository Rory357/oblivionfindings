<?php

use App\Domain\It\Exceptions\ItInboundHeaderException;
use App\Domain\It\Services\ItEmailHeaders;

test('single sender parsing preserves quoted display names and legal folding', function (string $header, string $expected) {
    expect((new ItEmailHeaders)->sender($header))->toBe($expected);
})->with([
    ['Worker <WORKER@example.test>', 'worker@example.test'],
    ['"Worker, One" <worker@example.test>', 'worker@example.test'],
    ['"Worker <one>" <worker@example.test>', 'worker@example.test'],
    ["(outer (nested)) Worker\r\n\t<worker@example.test> (desk)", 'worker@example.test'],
    ['worker@example.test', 'worker@example.test'],
    ['"a,b"@example.test', '"a,b"@example.test'],
    ['', ''],
]);

test('ambiguous sender syntax never selects the first address', function (string $header) {
    expect(fn () => (new ItEmailHeaders)->sender($header))->toThrow(ItInboundHeaderException::class);
})->with([
    'Worker <worker@example.test>, Other <other@example.test>',
    'worker@example.test, Other <other@example.test>',
    'worker@example.test <other@example.test>',
    'Worker <worker@example.test> other@example.test',
    'Workers: worker@example.test;',
    'Worker <worker@example.test><other@example.test>',
    'Worker <worker@example.test',
    'Worker <not-an-address>',
    "worker@example.test\nFrom: other@example.test",
    '"Unclosed <worker@example.test>',
    str_repeat('(', 9).'nested'.str_repeat(')', 9).' worker@example.test',
    str_repeat(' ', 16385),
    "\r\n",
]);

test('singleton headers reject duplicates even with different case or identical content', function (string $name) {
    expect(fn () => (new ItEmailHeaders)->read([
        ['name' => $name, 'value' => 'first'], ['name' => strtoupper($name), 'value' => 'first'],
    ]))->toThrow(ItInboundHeaderException::class);
})->with(['from', 'sender', 'reply-to', 'message-id', 'in-reply-to', 'references', 'subject']);

test('header collection preserves only canonical fields while allowing repeated trace fields', function () {
    expect((new ItEmailHeaders)->read([
        ['name' => 'Received', 'value' => 'one'], ['name' => 'Received', 'value' => 'two'],
        ['name' => 'From', 'value' => "Worker\r\n\t<worker@example.test>"],
    ]))->toBe(['from' => 'Worker <worker@example.test>']);
});

test('header collection rejects malformed names controls count and byte overflow', function (array $headers) {
    expect(fn () => (new ItEmailHeaders)->read($headers))->toThrow(ItInboundHeaderException::class);
})->with([
    [[['name' => 'From:', 'value' => 'worker@example.test']]],
    [[['name' => 'From', 'value' => "worker@example.test\0"]]],
    [[['name' => 'From', 'value' => ['worker@example.test']]]],
    [array_fill(0, 201, ['name' => 'Received', 'value' => 'x'])],
    [array_fill(0, 5, ['name' => 'Received', 'value' => str_repeat('x', 15000)])],
    [[['name' => 'From', 'value' => str_repeat('x', 16385)]]],
]);
