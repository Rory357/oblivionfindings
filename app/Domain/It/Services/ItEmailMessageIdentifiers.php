<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundHeaderException;

/** Bounded RFC5322 identification fields; identifiers are evidence, never access grants. */
final class ItEmailMessageIdentifiers
{
    public const MAX_HEADER_BYTES = 16384;

    public const MAX_IDENTIFIER_BYTES = 998;

    public const MAX_REFERENCES = 100;

    private const ATOM = '[A-Za-z0-9!#$%&\x27*+\-/=?^_`{|}\~]+';

    public function messageId(?string $header): ?string
    {
        $ids = $this->references($header);
        if (count($ids) > 1) {
            throw new ItInboundHeaderException;
        }

        return $ids[0] ?? null;
    }

    /** @return list<string> Normalized bracketed IDs in original order, including repeats. */
    public function references(?string $header): array
    {
        if ($header === null) {
            return [];
        }
        if (strlen($header) > self::MAX_HEADER_BYTES) {
            throw new ItInboundHeaderException('message_headers_too_large');
        }
        // Unfold only legal CRLF followed by whitespace; reject bare line breaks/injection.
        $header = preg_replace('/\r\n[ \t]+/', ' ', $header);
        if (preg_match('/[^\x09\x20-\x7e]/', $header)) {
            throw new ItInboundHeaderException;
        }
        if (trim($header) === '') {
            return [];
        }
        $offset = 0;
        $length = strlen($header);
        $ids = [];
        while ($offset < $length) {
            $this->skipCommentsAndWhitespace($header, $offset);
            if ($offset === $length) {
                break;
            }
            if ($header[$offset++] !== '<') {
                throw new ItInboundHeaderException;
            }
            $start = $offset;
            $quoted = false;
            $literal = false;
            $escaped = false;
            while ($offset < $length) {
                $character = $header[$offset];
                if ($offset - $start > self::MAX_IDENTIFIER_BYTES) {
                    throw new ItInboundHeaderException('message_headers_too_large');
                }
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\' && ($quoted || $literal)) {
                    $escaped = true;
                } elseif ($character === '"' && ! $literal) {
                    $quoted = ! $quoted;
                } elseif ($character === '[' && ! $quoted) {
                    $literal = true;
                } elseif ($character === ']' && ! $quoted) {
                    $literal = false;
                } elseif ($character === '>' && ! $quoted && ! $literal) {
                    break;
                }
                $offset++;
            }
            if ($offset === $length || $escaped || $quoted || $literal) {
                throw new ItInboundHeaderException;
            }
            $ids[] = $this->normalizeId(substr($header, $start, $offset - $start));
            $offset++;
            if (count($ids) > self::MAX_REFERENCES) {
                throw new ItInboundHeaderException('message_headers_too_large');
            }
        }

        return $ids;
    }

    public function hash(string $normalizedId): string
    {
        // Preserve opaque identifier case; a SQL case-insensitive comparison is not identity.
        return hash('sha256', $normalizedId);
    }

    private function normalizeId(string $id): string
    {
        if (strlen($id) + 2 > self::MAX_IDENTIFIER_BYTES) {
            throw new ItInboundHeaderException('message_headers_too_large');
        }
        $atom = self::ATOM;
        $dotAtom = $atom.'(?:\.'.$atom.')*';
        $quoted = '"(?:[\x20\x21\x23-\x5b\x5d-\x7e]|\\\\[\x20-\x7e])*"';
        $literal = '\[(?:[\x21-\x5a\x5e-\x7e]|\\\\[\x20-\x7e])*\]';
        if (! preg_match('~^('.$dotAtom.'|'.$quoted.')@('.$dotAtom.'|'.$literal.')$~D', $id, $match)) {
            throw new ItInboundHeaderException;
        }
        $left = $match[1];
        if (str_starts_with($left, '"')) {
            $unquoted = preg_replace('/\\\\([\x20-\x7e])/', '$1', substr($left, 1, -1));
            // RFC5256: quoted and unquoted representations of the same ID compare equally.
            $left = preg_match('~^'.$dotAtom.'$~D', $unquoted)
                ? $unquoted : '"'.addcslashes($unquoted, '\\"').'"';
        }

        return '<'.$left.'@'.$match[2].'>';
    }

    private function skipCommentsAndWhitespace(string $header, int &$offset): void
    {
        $length = strlen($header);
        while ($offset < $length) {
            if ($header[$offset] === ' ' || $header[$offset] === "\t") {
                $offset++;

                continue;
            }
            if ($header[$offset] !== '(') {
                return;
            }
            $depth = 1;
            $offset++;
            while ($offset < $length && $depth > 0) {
                $character = $header[$offset++];
                if ($character === '\\') {
                    if ($offset === $length) {
                        throw new ItInboundHeaderException;
                    }
                    $offset++;
                } elseif ($character === '(') {
                    if (++$depth > 8) {
                        throw new ItInboundHeaderException('message_headers_too_large');
                    }
                } elseif ($character === ')') {
                    $depth--;
                }
            }
            if ($depth !== 0) {
                throw new ItInboundHeaderException;
            }
        }
    }
}
