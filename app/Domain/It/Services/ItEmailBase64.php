<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundContentException;

/** Shared strict base64/base64url decoding; bounds apply before allocation. */
final class ItEmailBase64
{
    public static function decode(array $body, int $limit, string $invalidReason = 'invalid_message_content', string $sizeReason = 'message_body_too_large'): string
    {
        if (! is_string($body['data'] ?? null) || (isset($body['size']) && (! is_int($body['size']) || $body['size'] < 0))) {
            throw new ItInboundContentException($invalidReason);
        }
        $encoded = $body['data'];
        if (strlen($encoded) > 4 * (int) ceil($limit / 3) || ($body['size'] ?? 0) > $limit) {
            throw new ItInboundContentException($sizeReason);
        }
        if (! preg_match('/^[A-Za-z0-9+\/_-]*={0,2}$/D', $encoded) || strlen(rtrim($encoded, '=')) % 4 === 1) {
            throw new ItInboundContentException($invalidReason);
        }
        $encoded = strtr($encoded, '-_', '+/');
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || rtrim(base64_encode($decoded), '=') !== rtrim($encoded, '=')
            || (str_contains($encoded, '=') && strlen($encoded) % 4 !== 0)
            || (isset($body['size']) && strlen($decoded) !== $body['size'])) {
            throw new ItInboundContentException($invalidReason);
        }
        if (strlen($decoded) > $limit) {
            throw new ItInboundContentException($sizeReason);
        }

        return $decoded;
    }
}
