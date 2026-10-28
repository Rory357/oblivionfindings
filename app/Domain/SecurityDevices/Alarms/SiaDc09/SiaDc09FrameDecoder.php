<?php

namespace App\Domain\SecurityDevices\Alarms\SiaDc09;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Clean-room ANSI/SIA DC-09-2013 (IP Event Reporting) frame codec with CRC-16/ARC and optional AES-CBC.
 *
 * Official specifications:
 *   - Security Industry Association ANSI/SIA DC-09 (Internet Protocol Event Reporting):
 *     https://www.securityindustry.org/standards/dc-09/
 *   - ANSI/SIA DC-05 (Ademco Contact ID Protocol for Digital Alarm Communications)
 *   - ANSI/SIA DC-03 (SIA Digital Communication Standard - SIA-DCS)
 */
final class SiaDc09FrameDecoder
{
    public const int MAX_FRAME_BYTES = 2048;

    /**
     * Decode and verify a raw SIA DC-09 wire frame (`<LF><CRC><0LLL><BODY><CR>`).
     *
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     token?: string,
     *     encrypted?: bool,
     *     sequence?: string,
     *     receiver?: string|null,
     *     line_prefix?: string,
     *     account?: string,
     *     data?: string,
     *     timestamp?: DateTimeImmutable|null
     * }
     */
    public function decode(string $rawFrame, #[\SensitiveParameter] ?string $aesKeyBytes = null): array
    {
        if ($rawFrame === '' || strlen($rawFrame) > self::MAX_FRAME_BYTES) {
            return ['ok' => false, 'reason_code' => 'frame_bounds_invalid'];
        }

        $trimmed = trim($rawFrame, "\r\n");
        // Strict wire framing begins with LF (0x0A) and ends with CR (0x0D), though UDP senders may omit outer whitespace
        if (strlen($trimmed) < 16) {
            return ['ok' => false, 'reason_code' => 'frame_too_short'];
        }

        $crcHex = strtoupper(substr($trimmed, 0, 4));
        $lenHex = strtoupper(substr($trimmed, 4, 4));
        if (! ctype_xdigit($crcHex) || $lenHex[0] !== '0' || ! ctype_xdigit($lenHex)) {
            return ['ok' => false, 'reason_code' => 'invalid_header'];
        }

        $expectedBodyLen = (int) hexdec($lenHex);
        $body = substr($trimmed, 8);
        if ($expectedBodyLen < 8 || strlen($body) !== $expectedBodyLen) {
            return ['ok' => false, 'reason_code' => 'length_mismatch'];
        }

        // ANSI/SIA DC-09 §5.2: CRC-16/ARC is calculated from the `0LLL` length field through the end of `<BODY>`
        $computedCrc = self::crc16Arc($lenHex.$body);
        if (! hash_equals($computedCrc, $crcHex)) {
            return ['ok' => false, 'reason_code' => 'crc_mismatch'];
        }

        $headerPattern = '/\A"(\*?[A-Z0-9-]+)"(\d{4})(R[0-9A-Fa-f]{1,6})?(L[0-9A-Fa-f]{1,6})?#([0-9A-Fa-f]{3,16})\[(.*)\z/s';
        if (preg_match($headerPattern, $body, $matches) !== 1) {
            return ['ok' => false, 'reason_code' => 'malformed_envelope'];
        }

        $rawToken = $matches[1];
        $encrypted = str_starts_with($rawToken, '*');
        $token = ltrim($rawToken, '*');
        if (! in_array($token, ['ADM-CID', 'SIA-DCS', 'NULL'], true)) {
            return ['ok' => false, 'reason_code' => 'unsupported_token'];
        }

        $sequence = $matches[2];
        $receiver = ($matches[3] ?? '') !== '' ? $matches[3] : null;
        $linePrefix = ($matches[4] ?? '') !== '' ? $matches[4] : 'L0';
        $account = strtoupper($matches[5]);
        $remainder = $matches[6];

        if ($encrypted) {
            if ($aesKeyBytes === null || ! in_array(strlen($aesKeyBytes), [16, 24, 32], true)) {
                return [
                    'ok' => false,
                    'reason_code' => 'encryption_key_required',
                    'account' => $account,
                    'sequence' => $sequence,
                    'receiver' => $receiver,
                    'line_prefix' => $linePrefix,
                ];
            }

            $closeBracketPos = strrpos($remainder, ']');
            if ($closeBracketPos === false) {
                return ['ok' => false, 'reason_code' => 'malformed_ciphertext'];
            }
            $cipherHex = substr($remainder, 0, $closeBracketPos);
            if ($cipherHex === '' || strlen($cipherHex) % 32 !== 0 || ! ctype_xdigit($cipherHex)) {
                return ['ok' => false, 'reason_code' => 'malformed_ciphertext'];
            }

            $cipherMethod = match (strlen($aesKeyBytes)) {
                16 => 'aes-128-cbc',
                24 => 'aes-192-cbc',
                32 => 'aes-256-cbc',
            };
            $decrypted = openssl_decrypt(
                (string) hex2bin($cipherHex),
                $cipherMethod,
                $aesKeyBytes,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                str_repeat("\x00", 16),
            );
            if (! is_string($decrypted) || $decrypted === '') {
                return ['ok' => false, 'reason_code' => 'decryption_failed'];
            }

            // ANSI/SIA DC-09 §5.4: decrypted plaintext starts with random pad followed by `|`
            $pipePos = strpos($decrypted, '|');
            if ($pipePos === false) {
                return ['ok' => false, 'reason_code' => 'decryption_failed'];
            }
            $remainder = rtrim(substr($decrypted, $pipePos + 1), "\x00");
        }

        $closeBracketPos = strpos($remainder, ']');
        if ($closeBracketPos === false) {
            return ['ok' => false, 'reason_code' => 'missing_closing_bracket'];
        }

        $data = substr($remainder, 0, $closeBracketPos);
        $trailing = substr($remainder, $closeBracketPos + 1);
        $timestamp = $this->parseTimestamp($trailing);

        return [
            'ok' => true,
            'reason_code' => 'decoded',
            'token' => $token,
            'encrypted' => $encrypted,
            'sequence' => $sequence,
            'receiver' => $receiver,
            'line_prefix' => $linePrefix,
            'account' => $account,
            'data' => $data,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Build a wire-compliant SIA DC-09 response (`ACK`, `NAK`, or `DUH`) with CRC-16/ARC.
     */
    public static function buildResponseFrame(
        string $responseToken,
        string $sequence,
        ?string $receiver,
        string $linePrefix,
        string $account,
        ?DateTimeImmutable $utcNow = null,
    ): string {
        $utcNow ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tsSuffix = $responseToken === 'NAK'
            ? '_'.$utcNow->format('H:i:s,m-d-Y')
            : '_'.$utcNow->format('H:i:s,m-d-Y');

        $seq = preg_match('/\A\d{4}\z/', $sequence) === 1 ? $sequence : '0001';
        $rcvr = $receiver !== null && preg_match('/\AR[0-9A-Fa-f]{1,6}\z/', $receiver) === 1 ? $receiver : '';
        $pref = preg_match('/\AL[0-9A-Fa-f]{1,6}\z/', $linePrefix) === 1 ? $linePrefix : 'L0';
        $acct = preg_match('/\A[0-9A-Fa-f]{3,16}\z/', $account) === 1 ? strtoupper($account) : '0000';

        $body = sprintf('"%s"%s%s%s#%s[]%s', $responseToken, $seq, $rcvr, $pref, $acct, $tsSuffix);

        return self::wrapBody($body);
    }

    /**
     * Wrap an unencrypted or pre-encrypted SIA DC-09 body in `<LF><CRC><0LLL><BODY><CR>`.
     */
    public static function wrapBody(string $body): string
    {
        $lenHex = sprintf('0%03X', strlen($body));
        $crcHex = self::crc16Arc($lenHex.$body);

        return "\n".$crcHex.$lenHex.$body."\r";
    }

    /**
     * Compute ANSI/SIA DC-09 CRC-16/ARC (polynomial 0x8005, reflected 0xA001, init 0x0000) as 4 uppercase hex chars.
     */
    public static function crc16Arc(string $bytes): string
    {
        $crc = 0x0000;
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($bytes[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x0001) !== 0) {
                    $crc = (($crc >> 1) ^ 0xA001) & 0xFFFF;
                } else {
                    $crc = ($crc >> 1) & 0xFFFF;
                }
            }
        }

        return sprintf('%04X', $crc);
    }

    private function parseTimestamp(string $trailing): ?DateTimeImmutable
    {
        $trimmed = trim($trailing);
        if ($trimmed === '' || ! str_starts_with($trimmed, '_')) {
            return null;
        }

        // Format: _HH:MM:SS,MM-DD-YYYY
        $raw = substr($trimmed, 1);
        $dt = DateTimeImmutable::createFromFormat('!H:i:s,m-d-Y', $raw, new DateTimeZone('UTC'));

        return $dt instanceof DateTimeImmutable ? $dt : null;
    }
}
