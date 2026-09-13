<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundContentException;
use Closure;
use ValueError;

/** Message bodies only: never substitute a preview or a named attachment for the report. */
final class ItEmailContent
{
    public const MAX_TEXT_BYTES = 100000;

    public const MAX_PART_BYTES = 1048576;

    public const MAX_DECODED_BYTES = 2097152;

    public const MAX_PARTS = 100;

    public const MAX_DEPTH = 12;

    public function assertHumanMessage(array $headers, ?string $mime = null): void
    {
        $parser = new ItEmailHeaders;
        foreach ([$headers['content-type'] ?? '', $mime ?? ''] as $type) {
            $type = $parser->primaryValue($type);
            if (in_array($type, ['multipart/report', 'message/delivery-status', 'message/disposition-notification'], true)) {
                throw new ItInboundContentException('delivery_report');
            }
        }
        if ((isset($headers['auto-submitted']) && $parser->primaryValue($headers['auto-submitted']) !== 'no')
            || (isset($headers['return-path']) && $parser->primaryValue($headers['return-path']) === '<>')) {
            throw new ItInboundContentException('automatic_message');
        }
    }

    /** @param Closure(string): array $readExternalBody */
    public function gmail(array $payload, Closure $readExternalBody): string
    {
        $parts = $bytes = 0;
        $result = $this->part($payload, $readExternalBody, 0, $parts, $bytes);

        return $this->boundedText($result['text']);
    }

    public function graph(mixed $body): string
    {
        if (! is_array($body) || ! is_string($body['contentType'] ?? null) || ! is_string($body['content'] ?? null)) {
            throw new ItInboundContentException;
        }
        $type = strtolower($body['contentType']);
        if (! in_array($type, ['text', 'html'], true)) {
            throw new ItInboundContentException;
        }
        $this->assertPartSize(strlen($body['content']));

        return $this->boundedText($type === 'html' ? $this->html($body['content']) : $body['content']);
    }

    /** Strict complete base64/base64url, bounded before decoding. */
    public function decode(array $body): string
    {
        return ItEmailBase64::decode($body, self::MAX_PART_BYTES);
    }

    /** @return array{text:string,quality:int} */
    private function part(array $part, Closure $read, int $depth, int &$count, int &$bytes): array
    {
        if (++$count > self::MAX_PARTS || $depth > self::MAX_DEPTH) {
            throw new ItInboundContentException('message_structure_too_large');
        }
        if (! is_string($part['mimeType'] ?? null) || (isset($part['filename']) && ! is_string($part['filename']))) {
            throw new ItInboundContentException;
        }
        $mime = strtolower($part['mimeType']);
        // A forwarded .eml or named text file is attachment evidence, not the outer author/body.
        if (($part['filename'] ?? '') !== '' || $mime === 'message/rfc822') {
            return ['text' => '', 'quality' => 0];
        }
        $headers = (new ItEmailHeaders)->read($part['headers'] ?? []);
        if ((new ItEmailHeaders)->primaryValue($headers['content-disposition'] ?? '') === 'attachment') {
            return ['text' => '', 'quality' => 0];
        }
        if (str_starts_with($mime, 'multipart/')) {
            if (! is_array($part['parts'] ?? null) || ! array_is_list($part['parts'])) {
                throw new ItInboundContentException;
            }
            $selected = ['text' => '', 'quality' => 0];
            foreach ($part['parts'] as $child) {
                if (! is_array($child)) {
                    throw new ItInboundContentException;
                }
                $candidate = $this->part($child, $read, $depth + 1, $count, $bytes);
                if ($mime === 'multipart/alternative') {
                    if ($candidate['quality'] > $selected['quality']) {
                        $selected = $candidate;
                    }
                } elseif ($candidate['quality'] > 0) {
                    $selected['text'] = $this->boundedText($selected['text'].($selected['text'] !== '' ? "\n\n" : '').$candidate['text']);
                    $selected['quality'] = max($selected['quality'], $candidate['quality']);
                }
            }

            return $selected;
        }
        if (! in_array($mime, ['text/plain', 'text/html'], true)) {
            return ['text' => '', 'quality' => 0];
        }
        if (! is_array($part['body'] ?? null)) {
            throw new ItInboundContentException;
        }
        $body = $part['body'];
        if (isset($body['attachmentId'])) {
            if (isset($body['data']) && $body['data'] !== '') {
                throw new ItInboundContentException;
            }
            if (! is_string($body['attachmentId']) || $body['attachmentId'] === '' || strlen($body['attachmentId']) > 4096) {
                throw new ItInboundContentException;
            }
            if (isset($body['size']) && (! is_int($body['size']) || $body['size'] < 0 || $body['size'] > self::MAX_PART_BYTES)) {
                throw new ItInboundContentException('message_body_too_large');
            }
            $body = $read($body['attachmentId']);
            if (isset($part['body']['size']) && ($body['size'] ?? null) !== $part['body']['size']) {
                throw new ItInboundContentException;
            }
        }
        if (! array_key_exists('data', $body) && ($body['size'] ?? null) === 0) {
            $body['data'] = '';
        }
        $text = $this->decode($body);
        $bytes += strlen($text);
        if ($bytes > self::MAX_DECODED_BYTES) {
            throw new ItInboundContentException('message_body_too_large');
        }
        $contentType = $headers['content-type'] ?? '';
        if (preg_match_all('/(?:^|;)\s*charset\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $contentType, $matches, PREG_SET_ORDER)) {
            if (count($matches) !== 1) {
                throw new ItInboundContentException;
            }
            $charset = $matches[0][1] !== '' ? $matches[0][1] : $matches[0][2];
            try {
                if (! mb_check_encoding($text, $charset)) {
                    throw new ItInboundContentException;
                }
                $text = mb_convert_encoding($text, 'UTF-8', $charset);
            } catch (ValueError) {
                throw new ItInboundContentException;
            }
        }
        $text = $this->boundedText($mime === 'text/html' ? $this->html($text) : $text);

        return ['text' => $text, 'quality' => $mime === 'text/plain' ? 2 : 1];
    }

    private function html(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new ItInboundContentException;
        }
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text);
        $text = preg_replace('~<(?:br\b[^>]*|/(?:p|div|li|tr|h[1-6]))\s*>~i', "\n", $text);

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function boundedText(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new ItInboundContentException;
        }
        if (strlen($text) > self::MAX_TEXT_BYTES) {
            throw new ItInboundContentException('message_body_too_large');
        }

        return trim($text);
    }

    private function assertPartSize(int $size): void
    {
        if ($size > self::MAX_PART_BYTES) {
            throw new ItInboundContentException('message_body_too_large');
        }
    }
}
