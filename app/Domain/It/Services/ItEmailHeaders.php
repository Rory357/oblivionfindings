<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundHeaderException;
use InvalidArgumentException;
use Symfony\Component\Mime\Address;

/** Bounded provider headers. Ambiguous author information never chooses an account. */
final class ItEmailHeaders
{
    public const MAX_FIELDS = 200;

    public const MAX_BYTES = 65536;

    private const SINGLE = ['from', 'sender', 'reply-to', 'message-id', 'in-reply-to', 'references', 'subject', 'auto-submitted', 'content-type', 'content-disposition', 'return-path'];

    /** Leading semantic value, with bounded RFC comments removed and parameters ignored. */
    public function primaryValue(string $value): string
    {
        if (strlen($value) > ItEmailMessageIdentifiers::MAX_HEADER_BYTES) {
            throw new ItInboundHeaderException('message_headers_too_large');
        }
        $value = $this->unfold($value);
        $plain = '';
        $depth = 0;
        $quoted = $escaped = false;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($escaped) {
                if ($depth === 0) {
                    $plain .= $char;
                }
                $escaped = false;

                continue;
            }
            if ($char === '\\' && ($depth > 0 || $quoted)) {
                $escaped = true;
                if ($depth === 0) {
                    $plain .= $char;
                }

                continue;
            }
            if ($depth > 0) {
                $depth += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
                if ($depth > 8) {
                    throw new ItInboundHeaderException('message_headers_too_large');
                }

                continue;
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === '(') {
                $depth = 1;
                $plain .= ' ';

                continue;
            } elseif (! $quoted && $char === ')') {
                throw new ItInboundHeaderException;
            }
            $plain .= $char;
        }
        if ($quoted || $escaped || $depth !== 0) {
            throw new ItInboundHeaderException;
        }

        return strtolower(trim(preg_replace('~[ \t]*/[ \t]*~', '/', explode(';', $plain)[0])));
    }

    /** @return array<string, string> Only fields used by the canonical inbound adapter. */
    public function read(mixed $fields): array
    {
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw new ItInboundHeaderException;
        }
        if (count($fields) > self::MAX_FIELDS) {
            throw new ItInboundHeaderException('message_headers_too_large');
        }
        $bytes = 0;
        $result = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null) || ! is_string($field['value'] ?? null)
                || preg_match('/^[\x21-\x39\x3b-\x7e]+$/D', $field['name']) !== 1) {
                throw new ItInboundHeaderException;
            }
            $bytes += strlen($field['name']) + strlen($field['value']);
            if ($bytes > self::MAX_BYTES || strlen($field['value']) > ItEmailMessageIdentifiers::MAX_HEADER_BYTES) {
                throw new ItInboundHeaderException('message_headers_too_large');
            }
            $value = $this->unfold($field['value']);
            $name = strtolower($field['name']);
            if (in_array($name, self::SINGLE, true)) {
                if (array_key_exists($name, $result)) {
                    throw new ItInboundHeaderException('duplicate_message_headers');
                }
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /** Accept one mailbox, including quoted display names and bounded nested comments. */
    public function sender(?string $header): string
    {
        if ($header === null) {
            return '';
        }
        if (strlen($header) > ItEmailMessageIdentifiers::MAX_HEADER_BYTES) {
            throw new ItInboundHeaderException('message_headers_too_large');
        }
        $header = $this->unfold($header);
        if (trim($header) === '') {
            return '';
        }
        $clean = '';
        $quoted = false;
        $escaped = false;
        $comments = 0;
        $bareAddressSeen = false;
        $start = $end = null;
        for ($i = 0, $length = strlen($header); $i < $length; $i++) {
            $char = $header[$i];
            if ($escaped) {
                if ($comments === 0) {
                    $clean .= $char;
                }
                $escaped = false;

                continue;
            }
            if ($char === '\\' && ($quoted || $comments > 0)) {
                $escaped = true;
                if ($comments === 0) {
                    $clean .= $char;
                }

                continue;
            }
            if ($comments > 0) {
                $comments += $char === '(' ? 1 : ($char === ')' ? -1 : 0);
                if ($comments > 8) {
                    throw new ItInboundHeaderException('sender_ambiguous');
                }

                continue;
            }
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted) {
                if ($char === '(') {
                    $comments = 1;
                    $clean .= ' ';

                    continue;
                }
                if ($char === ')' || $char === ',' || $char === ';' || ($char === ':' && $start === null)) {
                    throw new ItInboundHeaderException('sender_ambiguous');
                }
                if ($char === '<') {
                    if ($start !== null || $bareAddressSeen) {
                        throw new ItInboundHeaderException('sender_ambiguous');
                    }
                    $start = strlen($clean);
                }
                if ($char === '@' && $start === null) {
                    $bareAddressSeen = true;
                }
                if ($char === '>') {
                    if ($start === null || $end !== null) {
                        throw new ItInboundHeaderException('sender_ambiguous');
                    }
                    $end = strlen($clean);
                }
            }
            $clean .= $char;
        }
        if ($quoted || $escaped || $comments > 0 || ($start !== null && ($end === null || trim(substr($clean, $end + 1)) !== ''))) {
            throw new ItInboundHeaderException('sender_ambiguous');
        }
        $address = trim($start === null ? $clean : substr($clean, $start + 1, $end - $start - 1));
        try {
            $address = (new Address($address))->getAddress();
        } catch (InvalidArgumentException) {
            throw new ItInboundHeaderException('sender_ambiguous');
        }
        if (strlen($address) > 255) {
            throw new ItInboundHeaderException('sender_ambiguous');
        }

        return mb_strtolower($address);
    }

    private function unfold(string $value): string
    {
        $value = preg_replace('/\r\n[ \t]+/', ' ', $value);
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value)) {
            throw new ItInboundHeaderException;
        }

        return $value;
    }
}
