<?php

use App\Services\Chat\ChatDocument;
use Illuminate\Container\Container;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

abstract class ChatDocumentUnitTestCase extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh container with only the validator a ValidationException needs; a previous feature
        // test may leave a flushed application behind.
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en')));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }
}

uses(ChatDocumentUnitTestCase::class);

function chatDocText(string $text, array $marks = []): array
{
    return ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

function chatDocParagraph(array|string ...$content): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn ($node) => is_string($node) ? chatDocText($node) : $node, $content)];
}

function chatDocList(bool $ordered, array ...$items): array
{
    return ['type' => 'list', 'ordered' => $ordered, 'items' => $items];
}

function chatDocQuote(array ...$blocks): array
{
    return ['type' => 'quote', 'blocks' => $blocks];
}

function chatDocMention(int $userId, string $label): array
{
    return ['type' => 'mention', 'user_id' => $userId, 'label' => $label];
}

function chatDocLink(string $href, string $text = 'the form'): array
{
    return ['type' => 'link', 'href' => $href, 'content' => [chatDocText($text)]];
}

function chatDoc(array ...$blocks): array
{
    return ['v' => 1, 'blocks' => $blocks];
}

/** The refusal message, keyed `document`, or "accepted" when the document passes. */
function chatDocRefusal(mixed $document): string
{
    try {
        (new ChatDocument)->normalise($document);
    } catch (ValidationException $error) {
        expect(array_keys($error->errors()))->toBe(['document']);

        return $error->errors()['document'][0];
    }

    return 'accepted';
}

it('accepts every block, inline and mark in the format', function () {
    $document = chatDoc(
        chatDocParagraph(
            chatDocText('Ring '),
            chatDocMention(12, 'Ana Rēweti'),
            chatDocText(' before ', ['bold']),
            chatDocLink('https://example.org/x'),
            chatDocText(' i', ['italic']),
            chatDocText(' u', ['underline']),
            chatDocText(' s', ['strike']),
            chatDocText(' c', ['code']),
        ),
        chatDocList(false, [chatDocParagraph('Kai at 12')]),
        chatDocList(true, [chatDocParagraph('First')]),
        chatDocQuote(chatDocParagraph('Kia ora')),
        ['type' => 'code', 'text' => 'plain text, shown as code'],
    );

    expect((new ChatDocument)->normalise($document))->toBe($document);
});

it('returns the canonical form: fixed key order, sorted marks, merged text and a lower-case scheme', function () {
    $documents = new ChatDocument;
    $input = ['blocks' => [
        ['content' => [
            ['type' => 'text', 'text' => 'Ring '],
            chatDocText('', ['bold']),
            chatDocText('now', ['italic', 'bold', 'bold']),
            chatDocText(' please', ['bold', 'italic']),
            ['content' => [chatDocText('the '), chatDocText('form')], 'href' => 'HTTPS://Example.org/Path', 'type' => 'link'],
            chatDocMention(4, 'Kiri'),
        ], 'type' => 'paragraph'],
        ['items' => [[chatDocParagraph('One')]], 'type' => 'list', 'ordered' => true],
    ], 'v' => 1];

    $normalised = $documents->normalise($input);

    expect($normalised)->toBe(chatDoc(
        chatDocParagraph(
            chatDocText('Ring '),
            chatDocText('now please', ['bold', 'italic']),
            ['type' => 'link', 'href' => 'https://Example.org/Path', 'content' => [chatDocText('the form')]],
            chatDocMention(4, 'Kiri'),
        ),
        chatDocList(true, [chatDocParagraph('One')]),
    ))->and($documents->normalise($normalised))->toBe($normalised);
});

it('accepts addresses that start with https://, http:// or an Oblivion path', function (string $href, string $canonical) {
    expect((new ChatDocument)->normalise(chatDoc(chatDocParagraph(chatDocLink($href))))['blocks'][0]['content'][0]['href'])->toBe($canonical);
})->with([
    'https' => ['https://example.org/x?y=1#z', 'https://example.org/x?y=1#z'],
    'http' => ['http://example.org', 'http://example.org'],
    'upper-case scheme' => ['HTTP://example.org', 'http://example.org'],
    'Māori domain' => ['https://kōrero.example.nz/pānui', 'https://kōrero.example.nz/pānui'],
    'Oblivion path' => ['/operations/clients/12?tab=notes', '/operations/clients/12?tab=notes'],
    'Oblivion home' => ['/', '/'],
]);

it('refuses other link schemes and addresses that would leave Oblivion', function (string $href) {
    expect(chatDocRefusal(chatDoc(chatDocParagraph(chatDocLink($href)))))->not->toBe('accepted');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'upper-case javascript' => ['JavaScript:alert(1)'],
    'data' => ['data:text/html,<b>hi</b>'],
    'mailto' => ['mailto:ana@example.org'],
    'tel' => ['tel:+6495550200'],
    'ftp' => ['ftp://example.org/file'],
    'protocol-relative' => ['//evil.example/path'],
    'backslash host' => ['/\\evil.example/path'],
    'no host' => ['https://'],
    'one slash' => ['http:/example.org'],
    'bare domain' => ['example.org'],
    'relative path' => ['clients/12'],
    'empty' => [''],
    'space' => ['https://example.org/a b'],
    'tab' => ["https://example.org/\ta"],
    'zero-width space' => ["https://exam\u{200B}ple.org"],
    'right-to-left override' => ["https://example.org/\u{202E}gpj.exe"],
    'too long' => ['https://example.org/'.str_repeat('a', 2030)],
]);

it('names the allowed schemes when it refuses a link', function () {
    expect(chatDocRefusal(chatDoc(chatDocParagraph(chatDocLink('javascript:alert(1)')))))->toBe('Links must start with https://, http:// or /.');
});

it('refuses unknown block types, text types and marks', function (array $document, string $message) {
    expect(chatDocRefusal($document))->toBe($message);
})->with([
    'heading block' => [chatDoc(['type' => 'heading', 'content' => []]), 'The formatted message has a block Chat does not recognise.'],
    'html block' => [chatDoc(['type' => 'html', 'html' => '<b>hi</b>']), 'The formatted message has a block Chat does not recognise.'],
    'block without a type' => [chatDoc(['content' => []]), 'The formatted message has a block Chat does not recognise.'],
    'block with a number type' => [chatDoc(['type' => 1, 'content' => []]), 'The formatted message has a block Chat does not recognise.'],
    'image inline' => [chatDoc(chatDocParagraph(['type' => 'image', 'src' => 'https://example.org/a.png'])), 'The formatted message has text Chat does not recognise.'],
    'html inline' => [chatDoc(chatDocParagraph(['type' => 'html', 'html' => '<script>'])), 'The formatted message has text Chat does not recognise.'],
    'colour mark' => [chatDoc(chatDocParagraph(chatDocText('red', ['colour']))), 'The message uses formatting Chat does not recognise. Use bold, italic, underline, strikethrough or code.'],
    'capitalised mark' => [chatDoc(chatDocParagraph(chatDocText('loud', ['Bold']))), 'The message uses formatting Chat does not recognise. Use bold, italic, underline, strikethrough or code.'],
    'marks as a word' => [chatDoc(chatDocParagraph(['type' => 'text', 'text' => 'x', 'marks' => 'bold'])), 'The formatted message is not in a format Chat accepts.'],
    'marks keyed' => [chatDoc(chatDocParagraph(['type' => 'text', 'text' => 'x', 'marks' => ['a' => 'bold']])), 'The formatted message is not in a format Chat accepts.'],
]);

it('refuses extra keys anywhere in the document', function (array $document) {
    expect(chatDocRefusal($document))->toBe('Part of the formatted message has a setting Chat does not recognise.');
})->with([
    'document' => [['v' => 1, 'blocks' => [], 'html' => '<b>hi</b>']],
    'paragraph' => [chatDoc([...chatDocParagraph('Hi'), 'style' => 'color:red'])],
    'text' => [chatDoc(chatDocParagraph([...chatDocText('Hi'), 'html' => '<b>Hi</b>']))],
    'mention' => [chatDoc(chatDocParagraph([...chatDocMention(3, 'Ana'), 'email' => 'ana@example.org']))],
    'link' => [chatDoc(chatDocParagraph([...chatDocLink('https://example.org'), 'target' => '_blank']))],
    'list' => [chatDoc([...chatDocList(true, [chatDocParagraph('One')]), 'start' => 5])],
    'quote' => [chatDoc([...chatDocQuote(chatDocParagraph('Hi')), 'cite' => 'Ana'])],
    'code' => [chatDoc(['type' => 'code', 'text' => 'x', 'language' => 'php'])],
    'numbered key' => [chatDoc(['type' => 'paragraph', 'content' => [], 0 => 'x'])],
]);

it('refuses control characters in text, names, code and links', function (array $document) {
    expect(chatDocRefusal($document))->toBe('The message contains a hidden control character. Remove it and try again.');
})->with([
    'null in text' => [chatDoc(chatDocParagraph("Ring\u{0000} now"))],
    'bell in text' => [chatDoc(chatDocParagraph("Ring\u{0007} now"))],
    'line feed in text' => [chatDoc(chatDocParagraph("Ring\nnow"))],
    'carriage return in text' => [chatDoc(chatDocParagraph("Ring\rnow"))],
    'delete in text' => [chatDoc(chatDocParagraph("Ring\u{007F}now"))],
    'C1 control in text' => [chatDoc(chatDocParagraph("Ring\u{0085}now"))],
    'line feed in a name' => [chatDoc(chatDocParagraph(chatDocMention(3, "Ana\nRēweti")))],
    'carriage return in code' => [chatDoc(['type' => 'code', 'text' => "one\r\ntwo"])],
    'escape in code' => [chatDoc(['type' => 'code', 'text' => "one\u{001B}[31m"])],
]);

it('keeps line breaks and tabs in code blocks only', function () {
    $document = chatDoc(['type' => 'code', 'text' => "if (x) {\n\treturn;\n}"]);

    expect((new ChatDocument)->normalise($document))->toBe($document)
        ->and((new ChatDocument)->plainText($document))->toBe("if (x) {\n\treturn;\n}");
});

it('refuses text that is not valid UTF-8 or not text at all', function (mixed $document) {
    expect(chatDocRefusal($document))->toBe('The formatted message is not in a format Chat accepts.');
})->with([
    'invalid UTF-8' => [chatDoc(chatDocParagraph("Kia ora \xC3\x28"))],
    'number as text' => [chatDoc(chatDocParagraph(['type' => 'text', 'text' => 12, 'marks' => []]))],
    'null document' => [null],
    'string document' => ['<p>Hello</p>'],
    'list document' => [[chatDocParagraph('Hi')]],
    'blocks keyed' => [['v' => 1, 'blocks' => ['a' => chatDocParagraph('Hi')]]],
    'content not a list' => [chatDoc(['type' => 'paragraph', 'content' => 'Hi'])],
    'ordered not true or false' => [chatDoc(['type' => 'list', 'ordered' => 'yes', 'items' => [[chatDocParagraph('One')]]])],
    'items not a list' => [chatDoc(['type' => 'list', 'ordered' => false, 'items' => 'One'])],
]);

it('refuses a missing or unsupported version', function () {
    expect(chatDocRefusal(['blocks' => []]))->toBe('Part of the formatted message is missing.')
        ->and(chatDocRefusal(['v' => 2, 'blocks' => []]))->toBe('This formatted message uses a version Chat does not support. Refresh the page and try again.')
        ->and(chatDocRefusal(['v' => '1', 'blocks' => []]))->toBe('This formatted message uses a version Chat does not support. Refresh the page and try again.')
        ->and(chatDocRefusal(chatDoc(['type' => 'paragraph'])))->toBe('Part of the formatted message is missing.');
});

it('refuses empty lists, items, quotes and links, and code inside lists or quotes', function (array $document, string $message) {
    expect(chatDocRefusal($document))->toBe($message);
})->with([
    'list without items' => [chatDoc(chatDocList(false)), 'A list needs at least one item.'],
    'empty list item' => [chatDoc(chatDocList(false, [])), 'Each list item needs a paragraph.'],
    'empty quote' => [chatDoc(chatDocQuote()), 'A quote needs at least one paragraph.'],
    'link without text' => [chatDoc(chatDocParagraph(['type' => 'link', 'href' => '/', 'content' => []])), 'A link needs some text.'],
    'link with only empty text' => [chatDoc(chatDocParagraph(chatDocLink('/', ''))), 'A link needs some text.'],
    'link inside a link' => [chatDoc(chatDocParagraph(['type' => 'link', 'href' => '/', 'content' => [chatDocLink('/a')]])), 'A link cannot go inside another link.'],
    'mention inside a link' => [chatDoc(chatDocParagraph(['type' => 'link', 'href' => '/', 'content' => [chatDocMention(3, 'Ana')]])), 'A link can only hold text.'],
    'code in a list' => [chatDoc(chatDocList(false, [['type' => 'code', 'text' => 'x']])), 'A code block cannot go inside a list or a quote.'],
    'code in a quote' => [chatDoc(chatDocQuote(['type' => 'code', 'text' => 'x'])), 'A code block cannot go inside a list or a quote.'],
]);

it('refuses mentions without a person or a name', function (array $mention, string $message) {
    expect(chatDocRefusal(chatDoc(chatDocParagraph($mention))))->toBe($message);
})->with([
    'no person' => [['type' => 'mention', 'user_id' => 0, 'label' => 'Ana'], 'A mention must name a person in this conversation.'],
    'negative person' => [['type' => 'mention', 'user_id' => -4, 'label' => 'Ana'], 'A mention must name a person in this conversation.'],
    'person as text' => [['type' => 'mention', 'user_id' => '12', 'label' => 'Ana'], 'A mention must name a person in this conversation.'],
    'person as a fraction' => [['type' => 'mention', 'user_id' => 1.5, 'label' => 'Ana'], 'A mention must name a person in this conversation.'],
    'empty name' => [['type' => 'mention', 'user_id' => 3, 'label' => ''], "A mention needs the person's name."],
    'blank name' => [['type' => 'mention', 'user_id' => 3, 'label' => '   '], "A mention needs the person's name."],
    'long name' => [['type' => 'mention', 'user_id' => 3, 'label' => str_repeat('ā', 256)], 'A mentioned name is too long.'],
]);

it('allows 200 blocks counted at every level and refuses more', function () {
    $paragraphs = array_fill(0, 200, chatDocParagraph('Hi'));
    // A list of 99 one-paragraph items is 100 blocks.
    $list = chatDocList(false, ...array_fill(0, 99, [chatDocParagraph('Item')]));

    expect(chatDocRefusal(chatDoc(...$paragraphs)))->toBe('accepted')
        ->and(chatDocRefusal(chatDoc($list, $list)))->toBe('accepted')
        ->and(chatDocRefusal(chatDoc(...$paragraphs, ...[chatDocParagraph('One more')])))->toBe('The message has too many paragraphs, lists and quotes. Keep it to 200.')
        ->and(chatDocRefusal(chatDoc($list, $list, chatDocParagraph('One more'))))->toBe('The message has too many paragraphs, lists and quotes. Keep it to 200.');
});

it('allows lists and quotes nested 3 deep and refuses a fourth level', function () {
    $lists = chatDocList(false, [chatDocParagraph('One'), chatDocList(false, [chatDocParagraph('Two'), chatDocList(true, [chatDocParagraph('Three')])])]);
    $quotes = chatDocQuote(chatDocQuote(chatDocQuote(chatDocParagraph('Three'))));
    $mixed = chatDocList(false, [chatDocQuote(chatDocList(true, [chatDocParagraph('Three')]))]);

    expect(chatDocRefusal(chatDoc($lists, $quotes, $mixed)))->toBe('accepted')
        ->and(chatDocRefusal(chatDoc(chatDocList(false, [chatDocList(false, [chatDocList(false, [chatDocList(false, [chatDocParagraph('Four')])])])]))))
        ->toBe('Lists and quotes can be placed inside each other at most 3 deep.')
        ->and(chatDocRefusal(chatDoc(chatDocQuote(chatDocQuote(chatDocQuote(chatDocQuote(chatDocParagraph('Four'))))))))
        ->toBe('Lists and quotes can be placed inside each other at most 3 deep.')
        ->and(chatDocRefusal(chatDoc(chatDocQuote(chatDocList(false, [chatDocQuote(chatDocList(true, [chatDocParagraph('Four')]))])))))
        ->toBe('Lists and quotes can be placed inside each other at most 3 deep.');
});

it('allows 10,000 characters of plain text, counting list markers, and refuses more', function () {
    $tooLong = 'The message is too long. Keep it to 10,000 characters.';
    $item = fn (int $length) => chatDoc(chatDocList(false, [chatDocParagraph(str_repeat('a', $length))]));
    $code = fn (string $text) => chatDoc(['type' => 'code', 'text' => $text]);

    expect(chatDocRefusal(chatDoc(chatDocParagraph(str_repeat('ā', 10000)))))->toBe('accepted')
        ->and(chatDocRefusal(chatDoc(chatDocParagraph(str_repeat('ā', 10001)))))->toBe($tooLong)
        // Two paragraphs of 5,000 are 10,001 characters with the line between them.
        ->and(chatDocRefusal(chatDoc(chatDocParagraph(str_repeat('a', 5000)), chatDocParagraph(str_repeat('a', 5000)))))->toBe($tooLong)
        // "• " and the item text: 2 + 9,998 fits, 2 + 9,999 does not.
        ->and(chatDocRefusal($item(9998)))->toBe('accepted')
        ->and(chatDocRefusal($item(9999)))->toBe($tooLong)
        ->and(chatDocRefusal($code(str_repeat("a\n", 5000))))->toBe('accepted')
        ->and(chatDocRefusal($code(str_repeat("a\n", 5000).'a')))->toBe($tooLong);
});

it('allows 30 mentions and refuses more', function () {
    $mentions = fn (int $count) => chatDoc(chatDocParagraph(...array_merge(...array_fill(0, $count, [chatDocMention(3, 'Ana'), chatDocText(' ')]))));

    expect(chatDocRefusal($mentions(30)))->toBe('accepted')
        ->and(chatDocRefusal($mentions(31)))->toBe('You can mention up to 30 people in one message.');
});

it('writes the plain text with each paragraph and list item on its own line', function () {
    $document = (new ChatDocument)->normalise(chatDoc(
        chatDocParagraph('Ring ', chatDocMention(12, 'Ana Rēweti'), chatDocText(' before ', ['bold']), chatDocLink('https://example.org/x')),
        chatDocParagraph(),
        chatDocList(true,
            [chatDocParagraph('Check meds'), chatDocParagraph('Second line')],
            [chatDocParagraph('Call whānau'), chatDocList(false, [chatDocParagraph('Māmā')], [chatDocParagraph('Koro')])],
        ),
        chatDocQuote(chatDocParagraph('Kia ora'), chatDocList(false, [chatDocParagraph('Quoted item')]), chatDocQuote(chatDocParagraph('Deeper'))),
        ['type' => 'code', 'text' => "line one\n\tline two"],
    ));

    expect((new ChatDocument)->plainText($document))->toBe(implode("\n", [
        'Ring @Ana Rēweti before the form',
        '',
        '1. Check meds',
        '   Second line',
        '2. Call whānau',
        '   • Māmā',
        '   • Koro',
        '> Kia ora',
        '> • Quoted item',
        '> > Deeper',
        'line one',
        "\tline two",
    ]));
});

it('writes the plain text of the example document in the design', function () {
    $document = (new ChatDocument)->normalise(json_decode(<<<'JSON'
        {"v": 1, "blocks": [
          {"type": "paragraph", "content": [
            {"type": "text", "text": "Ring ", "marks": []},
            {"type": "mention", "user_id": 12, "label": "Ana Rēweti"},
            {"type": "text", "text": " before ", "marks": ["bold"]},
            {"type": "link", "href": "https://example.org/x", "content": [{"type": "text", "text": "the form", "marks": []}]}
          ]},
          {"type": "list", "ordered": false, "items": [[{"type": "paragraph", "content": []}]]},
          {"type": "quote", "blocks": [{"type": "paragraph", "content": []}]},
          {"type": "code", "text": "plain text, shown as code"}
        ]}
        JSON, true, flags: JSON_THROW_ON_ERROR));

    expect((new ChatDocument)->plainText($document))->toBe("Ring @Ana Rēweti before the form\n• \n> \nplain text, shown as code")
        ->and((new ChatDocument)->mentions($document))->toBe([['user_id' => 12, 'start' => 5, 'length' => 11, 'label' => '@Ana Rēweti']]);
});

it('measures mention offsets in Unicode code points into the plain text, as plain sends do', function () {
    $documents = new ChatDocument;
    $document = $documents->normalise(chatDoc(
        chatDocParagraph('Kia ora 🙂 ', chatDocMention(7, 'Ana Rēweti'), ' me ', chatDocMention(8, 'Tāne Ōtaki')),
        chatDocList(false, [chatDocParagraph('Whānau: ', chatDocMention(9, 'Mere Pōtiki'))]),
        chatDocQuote(chatDocParagraph(chatDocMention(7, 'Ana Rēweti'))),
    ));
    $text = $documents->plainText($document);
    $mentions = $documents->mentions($document);

    expect($text)->toBe("Kia ora 🙂 @Ana Rēweti me @Tāne Ōtaki\n• Whānau: @Mere Pōtiki\n> @Ana Rēweti")
        ->and(array_column($mentions, 'start'))->toBe([10, 25, 47, 62])
        ->and(array_column($mentions, 'user_id'))->toBe([7, 8, 9, 7])
        ->and(array_column($mentions, 'length'))->toBe([11, 11, 12, 11]);
    // Each offset finds its label in the plain text, counted in code points rather than bytes.
    foreach ($mentions as $mention) {
        expect(mb_substr($text, $mention['start'], $mention['length'], 'UTF-8'))->toBe($mention['label']);
    }
    expect(strlen('@Ana Rēweti'))->toBe(12)->and(mb_strlen('@Ana Rēweti', 'UTF-8'))->toBe(11);
});

it('gives an empty document no text and no mentions', function () {
    $document = (new ChatDocument)->normalise(chatDoc());

    expect($document)->toBe(['v' => 1, 'blocks' => []])
        ->and((new ChatDocument)->plainText($document))->toBe('')
        ->and((new ChatDocument)->mentions($document))->toBe([]);
});

it('reads a submitted document from JSON, from a JSON string and from a multipart form', function () {
    $documents = new ChatDocument;
    $document = chatDoc(chatDocParagraph('Kia ora'));
    $json = fn (array $body) => Request::create('/chat', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

    expect($documents->requested($json(['document' => $document])))->toBe($document)
        ->and($documents->requested($json(['document' => json_encode($document)])))->toBe($document)
        ->and($documents->requested(Request::create('/chat', 'POST', ['document' => json_encode($document)])))->toBe($document)
        ->and($documents->requested($json(['content' => 'Plain'])))->toBeNull()
        ->and($documents->requested($json(['document' => null])))->toBeNull()
        ->and($documents->requested(Request::create('/chat', 'POST', ['content' => 'Plain'])))->toBeNull();
    expect(fn () => $documents->requested(Request::create('/chat', 'POST', ['document' => '{"v": 1, "blocks": ['])))
        ->toThrow(ValidationException::class, 'The formatted message could not be read. Copy your text and try again.');
});

it('reads JSON documents from the request bytes so empty strings are not turned into null', function () {
    $request = Request::create('/chat', 'POST', server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode(['document' => chatDoc(['type' => 'code', 'text' => ''], chatDocParagraph(chatDocText('')))], JSON_THROW_ON_ERROR));
    (new ConvertEmptyStringsToNull)->handle($request, fn (Request $request) => $request);

    expect($request->input('document.blocks.0.text'))->toBeNull()
        ->and((new ChatDocument)->requested($request))->toBe(chatDoc(['type' => 'code', 'text' => ''], chatDocParagraph()));
});
