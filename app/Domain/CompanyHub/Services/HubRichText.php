<?php

namespace App\Domain\CompanyHub\Services;

use InvalidArgumentException;

/** Semantic text only: readers render these nodes as elements, never as HTML. */
class HubRichText
{
    public const MAX_CHARACTERS = 20000;

    public const MAX_NODES = 100;

    public const MAX_RUNS = 1000;

    public const MAX_LIST_ITEMS = 100;

    public const MAX_TABLE_ROWS = 20;

    public const MAX_TABLE_COLUMNS = 10;

    public const MAX_BYTES = 200000;

    public static function normalise(mixed $value): array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['version', 'blocks']) !== []
            || ($value['version'] ?? null) !== 1 || ! self::isList($value['blocks'] ?? null, self::MAX_NODES)) {
            throw new InvalidArgumentException('Use a supported formatted text document.');
        }
        if (strlen(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Keep the formatted text document within its supported size.');
        }
        $characters = 0;
        $runs = 0;
        $blocks = [];
        foreach ($value['blocks'] as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                throw new InvalidArgumentException('Choose a supported text, heading, list or table.');
            }
            $type = $block['type'];
            if (in_array($type, ['paragraph', 'heading'], true)) {
                self::keys($block, $type === 'heading' ? ['type', 'level', 'runs'] : ['type', 'runs']);
                if ($type === 'heading' && ! in_array($block['level'] ?? null, [2, 3, 4], true)) {
                    throw new InvalidArgumentException('Choose heading level 2, 3 or 4.');
                }
                $node = ['type' => $type];
                if ($type === 'heading') {
                    $node['level'] = $block['level'];
                }
                $node['runs'] = self::runs($block['runs'] ?? null, $characters, $runs);
            } elseif (in_array($type, ['bullet_list', 'ordered_list'], true)) {
                self::keys($block, ['type', 'items']);
                if (! self::isList($block['items'] ?? null, self::MAX_LIST_ITEMS)) {
                    throw new InvalidArgumentException('Keep each list within 100 items.');
                }
                $node = ['type' => $type, 'items' => []];
                foreach ($block['items'] as $item) {
                    $node['items'][] = self::runs($item, $characters, $runs);
                }
            } elseif ($type === 'table') {
                self::keys($block, ['type', 'header', 'rows']);
                if (! is_bool($block['header'] ?? null) || ! self::isList($block['rows'] ?? null, self::MAX_TABLE_ROWS)) {
                    throw new InvalidArgumentException('Use a table with up to 20 rows and a clear header choice.');
                }
                $node = ['type' => 'table', 'header' => $block['header'], 'rows' => []];
                $columns = null;
                foreach ($block['rows'] as $row) {
                    if (! self::isList($row, self::MAX_TABLE_COLUMNS) || $row === [] || ($columns !== null && count($row) !== $columns)) {
                        throw new InvalidArgumentException('Use the same number of cells in each table row, up to 10 columns.');
                    }
                    $columns = count($row);
                    $cells = [];
                    foreach ($row as $cell) {
                        $cells[] = self::runs($cell, $characters, $runs);
                    }
                    $node['rows'][] = $cells;
                }
            } else {
                throw new InvalidArgumentException('Choose a supported text, heading, list or table.');
            }
            $blocks[] = $node;
        }

        $document = ['version' => 1, 'blocks' => $blocks];
        if (mb_strlen(self::plainText($document)) > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException('Keep formatted text within 20,000 characters.');
        }

        return $document;
    }

    /** The body fallback also supplies search and reading-time calculations. */
    public static function plainText(array $document): string
    {
        $text = fn (array $runs) => implode('', array_column($runs, 'text'));

        return implode("\n\n", array_map(fn (array $block) => match ($block['type']) {
            'paragraph', 'heading' => $text($block['runs']),
            'bullet_list', 'ordered_list' => implode("\n", array_map($text, $block['items'])),
            'table' => implode("\n", array_map(fn (array $row) => implode("\t", array_map($text, $row)), $block['rows'])),
        }, $document['blocks']));
    }

    private static function runs(mixed $value, int &$characters, int &$count): array
    {
        if (! self::isList($value, self::MAX_RUNS)) {
            throw new InvalidArgumentException('Use an ordered list of text runs.');
        }
        $result = [];
        foreach ($value as $run) {
            if (! is_array($run) || ! is_string($run['text'] ?? null) || ! mb_check_encoding($run['text'], 'UTF-8')
                || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $run['text'])) {
                throw new InvalidArgumentException('Use plain text for each formatted text run.');
            }
            self::keys($run, ['text', 'bold', 'italic', 'href']);
            $characters += mb_strlen($run['text']);
            if (++$count > self::MAX_RUNS || $characters > self::MAX_CHARACTERS) {
                throw new InvalidArgumentException('Keep formatted text within 20,000 characters and 1,000 text runs.');
            }
            $item = ['text' => $run['text']];
            foreach (['bold', 'italic'] as $mark) {
                if (array_key_exists($mark, $run)) {
                    if (! is_bool($run[$mark])) {
                        throw new InvalidArgumentException('Use a true or false choice for text formatting.');
                    }
                    $item[$mark] = $run[$mark];
                }
            }
            if (array_key_exists('href', $run)) {
                if (! is_string($run['href']) || mb_strlen($run['href']) > 2000 || ! HubContent::safeUrl($run['href'])) {
                    throw new InvalidArgumentException('Use a secure https link or an internal application path.');
                }
                $item['href'] = $run['href'];
            }
            $result[] = $item;
        }

        return $result;
    }

    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidArgumentException('Use only the supported formatting settings.');
        }
    }

    private static function isList(mixed $value, int $maximum): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= $maximum;
    }
}
