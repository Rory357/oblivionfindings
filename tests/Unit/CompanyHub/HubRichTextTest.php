<?php

use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubReaderMetadata;
use App\Domain\CompanyHub\Services\HubReadiness;
use App\Domain\CompanyHub\Services\HubRichText;

function richTextDocument(array $blocks): array
{
    return ['version' => 1, 'blocks' => $blocks];
}

function richTextParagraph(string $text): array
{
    return ['type' => 'paragraph', 'runs' => [['text' => $text]]];
}

function richTextPage(array $block): array
{
    return ['title' => 'Practical guide', 'sections' => [['id' => 'guide', 'blocks' => [[
        'id' => 'instructions', 'type' => 'text', 'title' => 'Getting started', ...$block,
    ]]]]];
}

test('semantic guide formatting roundtrips headings marks lists tables and links without HTML', function () {
    $document = richTextDocument([
        ['type' => 'heading', 'level' => 2, 'runs' => [['text' => 'Before your shift', 'bold' => true]]],
        ['type' => 'paragraph', 'runs' => [
            ['text' => 'Read ', 'italic' => false],
            ['text' => 'the guide', 'href' => '/company-hub/read/welcome'],
            ['text' => ' and ask ', 'bold' => false],
            ['text' => 'your team', 'italic' => true, 'href' => 'https://example.org/help'],
        ]],
        ['type' => 'bullet_list', 'items' => [[['text' => 'Meet your buddy']], [['text' => 'Check the roster']]]],
        ['type' => 'ordered_list', 'items' => [[['text' => 'Arrive']], [['text' => 'Check in']]]],
        ['type' => 'table', 'header' => true, 'rows' => [
            [[['text' => 'When']], [['text' => 'What']]],
            [[['text' => 'Morning']], [['text' => 'Welcome', 'bold' => true]]],
        ]],
    ]);
    $saved = HubRichText::normalise(json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect($saved)->toBe($document)
        ->and(HubRichText::normalise($saved))->toBe($saved)
        ->and(HubRichText::plainText($saved))->toBe("Before your shift\n\nRead the guide and ask your team\n\nMeet your buddy\nCheck the roster\n\nArrive\nCheck in\n\nWhen\tWhat\nMorning\tWelcome");
    $content = richTextPage(['rich_text' => $saved, 'body' => HubRichText::plainText($saved)]);
    expect(HubContent::readerContent($content))->toBe($content)
        ->and(HubReaderMetadata::body($content))->toContain('Check the roster', 'Morning')
        ->and(HubReaderMetadata::matches(['title' => 'Practical guide'], 'Check the roster', HubReaderMetadata::body($content)))->toBeTrue();
    $content['sections'][0]['blocks'][0]['hidden'] = true;
    expect(HubContent::readerContent($content)['sections'])->toBe([])
        ->and(HubReaderMetadata::body($content))->toBe('');
});

test('legacy plain text and explicitly cleared formatting keep the same reader and readiness contract', function () {
    $content = richTextPage(['body' => "First step.\n\nSecond step."]);
    expect(HubContent::readerContent($content))->toBe($content)
        ->and(HubReadiness::issues($content))->toBe([]);
    $content['sections'][0]['blocks'][0]['rich_text'] = null;
    expect(HubContent::readerContent($content))->toBe($content)
        ->and(HubReadiness::issues($content))->toBe([]);
});

test('formatting cannot smuggle HTML attributes embeds arbitrary node types or unsafe links', function (array $document) {
    expect(fn () => HubRichText::normalise($document))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown document property' => [richTextDocument([richTextParagraph('Useful')]) + ['html' => '<script>alert(1)</script>']],
    'raw HTML node' => [richTextDocument([['type' => 'html', 'html' => '<img onerror="alert(1)">']])],
    'image embed' => [richTextDocument([['type' => 'image', 'src' => 'https://example.org/x.png']])],
    'style attribute' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Useful', 'style' => 'color:red']]]])],
    'event attribute' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Useful', 'onclick' => 'alert(1)']]]])],
    'javascript link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => 'javascript:alert(1)']]]])],
    'http link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => 'http://example.org']]]])],
    'protocol relative link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => '//example.org']]]])],
    'credentials in link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => 'https://user:secret@example.org']]]])],
    'control in link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => "/\n/example.org"]]]])],
    'backslash in link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => '/\\example.org']]]])],
]);

test('malformed structured documents fail before a reader can receive unsupported shapes', function (mixed $document) {
    expect(fn () => HubRichText::normalise($document))->toThrow(InvalidArgumentException::class);
})->with([
    'HTML string' => ['<p>Useful</p>'],
    'missing version' => [['blocks' => []]],
    'unsupported version' => [['version' => 2, 'blocks' => []]],
    'string version' => [['version' => '1', 'blocks' => []]],
    'keyed blocks' => [richTextDocument(['first' => richTextParagraph('Useful')])],
    'string node' => [richTextDocument(['Useful'])],
    'missing runs' => [richTextDocument([['type' => 'paragraph']])],
    'keyed runs' => [richTextDocument([['type' => 'paragraph', 'runs' => ['first' => ['text' => 'Useful']]]])],
    'missing text' => [richTextDocument([['type' => 'paragraph', 'runs' => [['bold' => true]]]])],
    'numeric text' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 12]]]])],
    'string mark' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Useful', 'bold' => 'yes']]]])],
    'null link' => [richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Useful', 'href' => null]]]])],
    'heading one' => [richTextDocument([['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Useful']]]])],
    'keyed list' => [richTextDocument([['type' => 'bullet_list', 'items' => ['first' => [['text' => 'Useful']]]]])],
    'table without header choice' => [richTextDocument([['type' => 'table', 'rows' => []]])],
    'ragged table' => [richTextDocument([['type' => 'table', 'header' => true, 'rows' => [[[['text' => 'One']]], [[['text' => 'Two']], [['text' => 'Three']]]]]])],
    'empty table row' => [richTextDocument([['type' => 'table', 'header' => false, 'rows' => [[]]]])],
    'invalid UTF8' => [richTextDocument([richTextParagraph("\xC3\x28")])],
    'null byte' => [richTextDocument([richTextParagraph("Read\0the guide")])],
]);

test('structured guide text has cumulative bounded characters nodes runs lists tables and link size', function () {
    $maximum = richTextDocument([richTextParagraph(str_repeat('ā', HubRichText::MAX_CHARACTERS))]);
    expect(HubRichText::normalise($maximum))->toBe($maximum);
    $invalid = [
        richTextDocument([richTextParagraph(str_repeat('ā', HubRichText::MAX_CHARACTERS + 1))]),
        richTextDocument([richTextParagraph(str_repeat('a', 10000)), richTextParagraph(str_repeat('b', 10000))]),
        richTextDocument(array_fill(0, HubRichText::MAX_NODES + 1, richTextParagraph(''))),
        richTextDocument([['type' => 'paragraph', 'runs' => array_fill(0, 600, ['text' => ''])], ['type' => 'paragraph', 'runs' => array_fill(0, 401, ['text' => ''])]]),
        richTextDocument([['type' => 'bullet_list', 'items' => array_fill(0, HubRichText::MAX_LIST_ITEMS + 1, [])]]),
        richTextDocument([['type' => 'table', 'header' => false, 'rows' => array_fill(0, HubRichText::MAX_TABLE_ROWS + 1, [[]])]]),
        richTextDocument([['type' => 'table', 'header' => true, 'rows' => [array_fill(0, HubRichText::MAX_TABLE_COLUMNS + 1, [])]]]),
        richTextDocument([['type' => 'paragraph', 'runs' => [['text' => 'Open', 'href' => 'https://example.org/'.str_repeat('x', 2000)]]]]),
        richTextDocument([['type' => 'paragraph', 'runs' => array_fill(0, 110, ['text' => '', 'href' => 'https://example.org/'.str_repeat('x', 1900)])]]),
    ];
    foreach ($invalid as $document) {
        expect(fn () => HubRichText::normalise($document))->toThrow(InvalidArgumentException::class);
    }
});

test('rich guide readiness uses visible formatted text and never a misleading stale plain body', function () {
    foreach ([[], [richTextParagraph('  ')], [richTextParagraph("\u{00A0}\u{200B}\u{FEFF}")], [['type' => 'bullet_list', 'items' => [[]]]], [['type' => 'table', 'header' => true, 'rows' => [[[], []]]]]] as $blocks) {
        $content = richTextPage(['body' => 'Old nonempty text', 'rich_text' => richTextDocument($blocks)]);
        expect(array_column(HubReadiness::issues($content), 'code'))->toBe(['block_body']);
        $content['sections'][0]['blocks'][0]['hidden'] = true;
        expect(HubReadiness::issues($content))->toBe([]);
    }
    $content = richTextPage(['body' => '', 'rich_text' => richTextDocument([richTextParagraph('Useful instructions')])]);
    expect(HubReadiness::issues($content))->toBe([]);
    $content['sections'][0]['blocks'][0]['rich_text'] = ['version' => 9, 'blocks' => []];
    expect(array_column(HubReadiness::issues($content), 'code'))->toBe(['block_rich_text']);
});
