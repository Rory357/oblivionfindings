<?php

namespace App\Services\Chat;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Formatted message text in Oblivion's own document format, version 1. Written from the format in
 * docs/chat-experience/c2.6-message-model.md. A document is data, never HTML: normalise() refuses
 * anything outside the format, and the plain-text content and mention offsets come from it.
 */
final class ChatDocument
{
    public const VERSION = 1;

    /** Every block counts, including the paragraphs inside lists and quotes. */
    public const MAX_BLOCKS = 200;

    /** Lists and quotes inside lists and quotes. */
    public const MAX_DEPTH = 3;

    /** Characters of plain text, the same limit as content. */
    public const MAX_TEXT = 10000;

    /** The same limit as the mentions on a plain send. */
    public const MAX_MENTIONS = 30;

    /** "@" and the label fit chat_message_mentions.label. */
    public const MAX_LABEL = 255;

    public const MAX_HREF = 2048;

    /** In the order normalise() sorts them into. */
    public const MARKS = ['bold', 'italic', 'underline', 'strike', 'code'];

    private int $blockCount = 0;

    private int $characterCount = 0;

    /**
     * The submitted document, normalised, or null when the request has none. JSON requests are read
     * from their own bytes so the empty-string middleware cannot turn "" into null. A send with
     * files is multipart, so it carries the document as a JSON string.
     */
    public function requested(Request $request): ?array
    {
        $body = $request->isJson() ? json_decode($request->getContent(), true) : null;
        $value = is_array($body) ? ($body['document'] ?? null) : $request->input('document');
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->refuse('The formatted message could not be read. Copy your text and try again.');
            }
        }

        return $this->normalise($value);
    }

    /** Checks a document against the format and returns its canonical form, or refuses it. */
    public function normalise(mixed $document): array
    {
        $this->blockCount = 0;
        $this->characterCount = 0;
        $document = $this->node($document, ['v', 'blocks']);
        if ($document['v'] !== self::VERSION) {
            $this->refuse('This formatted message uses a version Chat does not support. Refresh the page and try again.');
        }
        $normalised = ['v' => self::VERSION, 'blocks' => $this->blocks($document['blocks'], 0)];
        [$text, $mentions] = $this->render($normalised);
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT) {
            $this->tooLong();
        }
        if (count($mentions) > self::MAX_MENTIONS) {
            $this->refuse('You can mention up to 30 people in one message.');
        }

        return $normalised;
    }

    /**
     * The plain-text copy of a normalised document. Paragraphs and list items each go on their own
     * line; list items start with "• " or "1. " and quote lines with "> "; a mention is "@" and its label.
     */
    public function plainText(array $document): string
    {
        return $this->render($document)[0];
    }

    /**
     * The mentions in a normalised document. Offsets are Unicode code points into plainText(), as
     * ChatMentions measures them, and the label is the mention as it reads there ("@Ana Rēweti").
     *
     * @return list<array{user_id: int, start: int, length: int, label: string}>
     */
    public function mentions(array $document): array
    {
        return $this->render($document)[1];
    }

    private function blocks(mixed $blocks, int $depth): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->unreadable();
        }
        $normalised = [];
        foreach ($blocks as $block) {
            if (++$this->blockCount > self::MAX_BLOCKS) {
                $this->refuse('The message has too many paragraphs, lists and quotes. Keep it to 200.');
            }
            $normalised[] = match (is_array($block) ? ($block['type'] ?? null) : null) {
                'paragraph' => ['type' => 'paragraph', 'content' => $this->inline($this->node($block, ['type', 'content'])['content'], false)],
                'list' => $this->list($block, $depth),
                'quote' => $this->quote($block, $depth),
                'code' => $depth === 0 ? $this->code($block) : $this->refuse('A code block cannot go inside a list or a quote.'),
                default => $this->refuse('The formatted message has a block Chat does not recognise.'),
            };
        }

        return $normalised;
    }

    private function list(array $block, int $depth): array
    {
        $block = $this->node($block, ['type', 'ordered', 'items']);
        $this->nest($depth);
        if (! is_bool($block['ordered']) || ! is_array($block['items']) || ! array_is_list($block['items'])) {
            $this->unreadable();
        }
        if ($block['items'] === []) {
            $this->refuse('A list needs at least one item.');
        }
        $items = [];
        foreach ($block['items'] as $item) {
            // Each item holds paragraphs, and lists or quotes up to the nesting limit.
            $items[] = $this->blocks($item, $depth + 1) ?: $this->refuse('Each list item needs a paragraph.');
        }

        return ['type' => 'list', 'ordered' => $block['ordered'], 'items' => $items];
    }

    private function quote(array $block, int $depth): array
    {
        $block = $this->node($block, ['type', 'blocks']);
        $this->nest($depth);

        return ['type' => 'quote', 'blocks' => $this->blocks($block['blocks'], $depth + 1) ?: $this->refuse('A quote needs at least one paragraph.')];
    }

    private function code(array $block): array
    {
        $block = $this->node($block, ['type', 'text']);

        // Code keeps its line breaks and tabs.
        return ['type' => 'code', 'text' => $this->counted($this->text($block['text'], multiline: true))];
    }

    private function nest(int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            $this->refuse('Lists and quotes can be placed inside each other at most 3 deep.');
        }
    }

    private function inline(mixed $content, bool $inLink): array
    {
        if (! is_array($content) || ! array_is_list($content)) {
            $this->unreadable();
        }
        $normalised = [];
        foreach ($content as $node) {
            $node = match (is_array($node) ? ($node['type'] ?? null) : null) {
                'text' => $this->textNode($node),
                'mention' => $inLink ? $this->refuse('A link can only hold text.') : $this->mention($node),
                'link' => $inLink ? $this->refuse('A link cannot go inside another link.') : $this->link($node),
                default => $this->refuse('The formatted message has text Chat does not recognise.'),
            };
            // Empty text adds nothing, and neighbouring text with the same marks is one piece.
            if ($node['type'] === 'text') {
                $last = array_key_last($normalised);
                if ($node['text'] === '') {
                    continue;
                }
                if ($last !== null && $normalised[$last]['type'] === 'text' && $normalised[$last]['marks'] === $node['marks']) {
                    $normalised[$last]['text'] .= $node['text'];

                    continue;
                }
            }
            $normalised[] = $node;
        }

        return $normalised;
    }

    private function textNode(array $node): array
    {
        $node = $this->node($node, ['type', 'text'], ['marks']);
        $marks = $node['marks'] ?? [];
        if (! is_array($marks) || ! array_is_list($marks)) {
            $this->unreadable();
        }
        foreach ($marks as $mark) {
            if (! in_array($mark, self::MARKS, true)) {
                $this->refuse('The message uses formatting Chat does not recognise. Use bold, italic, underline, strikethrough or code.');
            }
        }

        return ['type' => 'text', 'text' => $this->counted($this->text($node['text'])), 'marks' => array_values(array_intersect(self::MARKS, $marks))];
    }

    private function mention(array $node): array
    {
        $node = $this->node($node, ['type', 'user_id', 'label']);
        if (! is_int($node['user_id']) || $node['user_id'] < 1) {
            $this->refuse('A mention must name a person in this conversation.');
        }
        $label = $this->text($node['label']);
        if (trim($label) === '') {
            $this->refuse("A mention needs the person's name.");
        }
        if (mb_strlen($label, 'UTF-8') > self::MAX_LABEL) {
            $this->refuse('A mentioned name is too long.');
        }

        return ['type' => 'mention', 'user_id' => $node['user_id'], 'label' => $this->counted($label)];
    }

    private function link(array $node): array
    {
        $node = $this->node($node, ['type', 'href', 'content']);
        $href = $this->href($node['href']);

        return ['type' => 'link', 'href' => $href, 'content' => $this->inline($node['content'], true) ?: $this->refuse('A link needs some text.')];
    }

    /** An http(s) address with a host, or an Oblivion path. "//host" and "/\host" would leave Oblivion. */
    private function href(mixed $href): string
    {
        if (! is_string($href) || ! mb_check_encoding($href, 'UTF-8') || mb_strlen($href, 'UTF-8') > self::MAX_HREF) {
            $this->refuse('A link address is missing or too long.');
        }
        // Invisible format characters (zero-width spaces, direction overrides) could disguise an address.
        if (preg_match('/[\s\p{Z}\p{Cc}\p{Cf}\\\\]/u', $href) === 1) {
            $this->refuse('A link address cannot contain spaces, backslashes or hidden characters.');
        }
        if (preg_match('~^(?:https?://[^/?#]+|/(?!/))~i', $href) !== 1) {
            $this->refuse('Links must start with https://, http:// or /.');
        }

        // The scheme compares without case; the canonical form is lower case.
        return preg_replace_callback('~^https?(?=://)~i', fn (array $scheme) => strtolower($scheme[0]), $href);
    }

    /** An object with exactly these keys; optional ones may be left out. */
    private function node(mixed $value, array $required, array $optional = []): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->unreadable();
        }
        foreach (array_keys($value) as $key) {
            if (! in_array($key, [...$required, ...$optional], true)) {
                $this->refuse('Part of the formatted message has a setting Chat does not recognise.');
            }
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                $this->refuse('Part of the formatted message is missing.');
            }
        }

        return $value;
    }

    private function text(mixed $value, bool $multiline = false): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->unreadable();
        }
        if (preg_match($multiline ? '/[^\P{Cc}\n\t]/u' : '/\p{Cc}/u', $value) === 1) {
            $this->refuse('The message contains a hidden control character. Remove it and try again.');
        }

        return $value;
    }

    /** Stops early on a very long document; normalise() checks the final plain text as well. */
    private function counted(string $text): string
    {
        $this->characterCount += mb_strlen($text, 'UTF-8');
        if ($this->characterCount > self::MAX_TEXT) {
            $this->tooLong();
        }

        return $text;
    }

    /** @return array{0: string, 1: list<array{user_id: int, start: int, length: int, label: string}>} */
    private function render(array $document): array
    {
        $text = '';
        $offset = 0;
        $mentions = [];
        foreach ($this->lines($document['blocks'] ?? []) as $index => $line) {
            if ($index > 0) {
                $text .= "\n";
                $offset++;
            }
            foreach ($line as [$piece, $userId]) {
                $length = mb_strlen($piece, 'UTF-8');
                if ($userId !== null) {
                    $mentions[] = ['user_id' => $userId, 'start' => $offset, 'length' => $length, 'label' => $piece];
                }
                $text .= $piece;
                $offset += $length;
            }
        }

        return [$text, $mentions];
    }

    /** @return list<list<array{0: string, 1: ?int}>> each line as pieces of text and the mentioned person, if any */
    private function lines(array $blocks): array
    {
        $lines = [];
        foreach ($blocks as $block) {
            array_push($lines, ...match ($block['type']) {
                'paragraph' => [$this->pieces($block['content'])],
                'code' => array_map(fn (string $line) => [[$line, null]], explode("\n", $block['text'])),
                'quote' => $this->prefixed($this->lines($block['blocks']), '> ', '> '),
                'list' => $this->listLines($block),
            });
        }

        return $lines;
    }

    private function listLines(array $list): array
    {
        $lines = [];
        foreach ($list['items'] as $index => $item) {
            $marker = $list['ordered'] ? ($index + 1).'. ' : '• ';
            // A second paragraph or a nested list lines up under the item's text.
            array_push($lines, ...$this->prefixed($this->lines($item), $marker, str_repeat(' ', mb_strlen($marker, 'UTF-8'))));
        }

        return $lines;
    }

    private function prefixed(array $lines, string $first, string $rest): array
    {
        foreach (array_keys($lines) as $index) {
            array_unshift($lines[$index], [$index === 0 ? $first : $rest, null]);
        }

        return $lines;
    }

    private function pieces(array $content): array
    {
        $pieces = [];
        foreach ($content as $node) {
            array_push($pieces, ...match ($node['type']) {
                'text' => [[$node['text'], null]],
                'mention' => [['@'.$node['label'], $node['user_id']]],
                'link' => $this->pieces($node['content']),
            });
        }

        return $pieces;
    }

    private function tooLong(): never
    {
        $this->refuse('The message is too long. Keep it to 10,000 characters.');
    }

    private function unreadable(): never
    {
        $this->refuse('The formatted message is not in a format Chat accepts.');
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
