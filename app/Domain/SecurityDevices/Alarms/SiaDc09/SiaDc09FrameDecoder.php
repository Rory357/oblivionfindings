<?php

namespace App\Domain\SecurityDevices\Alarms\SiaDc09;

use DateTimeImmutable;
use DateTimeZone;

/**
 * ANSI/SIA DC-09 (alarm reports over IP) frame codec: CRC-16/ARC, optional AES-CBC, ACK / NAK / DUH.
 *
 * Rewritten in Phase A (docs/security-devices/GOAL.md, item A3). The earlier version checked the CRC
 * over the length field as well, expected a `]` after encrypted text and answered encrypted messages
 * in plain text, so real panels were rejected.
 *
 * Wire frame: `<LF><CRC 4 hex><LEN 4 hex>"<token>"<seq><Rrcvr><Lpref>#<acct>[<data>]<[xdata]…><_timestamp><CR>`
 *
 * - The CRC covers the body only: from the `"` that opens the token to the end of the timestamp. The
 *   leading LF, the CRC, the length field and the trailing CR are not part of it.
 * - Encrypted frames (`"*SIA-DCS"`, `"*ADM-CID"`, `"*NULL"`) carry hex ciphertext straight after the `[`,
 *   with no closing `]` on the wire. The plaintext is `<pad>|<data>]<xdata>_<timestamp>`: AES-CBC with a
 *   zero IV, the key as entered (16, 24 or 32 characters), no padding scheme. The pad may be followed by
 *   `|`; panels without data (for example `NULL`) send the pad and then `]` straight away.
 * - Encrypted frames must carry a timestamp, and any timestamp must sit within 40 s before to 20 s after
 *   the receiver's clock. Otherwise the receiver answers NAK with its own time so the panel can correct.
 * - Encrypted frames are answered with an encrypted ACK (`"*ACK"`); NAK is always plain.
 *
 * Checked against the sample frames in pysiaalarm (MIT, Copyright (c) 2021 E.A. van Valkenburg), the open
 * receiver used by Home Assistant: see tests/Unit/SecurityDevices/SiaDc09FrameDecoderTest.php.
 *
 * Specifications: ANSI/SIA DC-09 (https://www.securityindustry.org/standards/dc-09/), DC-05 (Contact ID),
 * DC-03 (SIA-DCS).
 */
final class SiaDc09FrameDecoder
{
    public const int MAX_FRAME_BYTES = 2048;

    /** A frame's timestamp may be at most this many seconds behind the receiver's clock. */
    public const int TIMESTAMP_PAST_SECONDS = 40;

    /** A frame's timestamp may be at most this many seconds ahead of the receiver's clock. */
    public const int TIMESTAMP_FUTURE_SECONDS = 20;

    /** @var list<string> */
    public const array TOKENS = ['ADM-CID', 'SIA-DCS', 'NULL'];

    private const string TIMESTAMP_FORMAT = 'H:i:s,m-d-Y';

    /**
     * Decode and check a raw SIA DC-09 frame.
     *
     * @return array{
     *     ok: bool,
     *     reason_code: string,
     *     token?: string,
     *     encrypted?: bool,
     *     sequence?: string,
     *     receiver?: string|null,
     *     line_prefix?: string,
     *     account?: string|null,
     *     data?: string,
     *     extended_data?: list<string>,
     *     timestamp?: DateTimeImmutable|null,
     *     length_matches?: bool
     * }
     */
    public function decode(string $rawFrame, #[\SensitiveParameter] ?string $aesKeyBytes = null): array
    {
        if ($rawFrame === '' || strlen($rawFrame) > self::MAX_FRAME_BYTES) {
            return ['ok' => false, 'reason_code' => 'frame_bounds_invalid'];
        }

        $frame = trim($rawFrame, " \t\r\n\0");
        if (strlen($frame) < 16) {
            return ['ok' => false, 'reason_code' => 'frame_too_short'];
        }

        $crcHex = strtoupper(substr($frame, 0, 4));
        $lengthHex = strtoupper(substr($frame, 4, 4));
        if (! ctype_xdigit($crcHex) || ! ctype_xdigit($lengthHex)) {
            return ['ok' => false, 'reason_code' => 'invalid_header'];
        }

        $body = substr($frame, 8);
        if (! hash_equals(self::crc16Arc($body), $crcHex)) {
            return ['ok' => false, 'reason_code' => 'crc_mismatch'];
        }

        // A wrong length field is tolerated once the CRC has proved the body is intact: rejecting a real
        // alarm over a length quirk would be worse than accepting it. The flag is kept for logging.
        $lengthMatches = (int) hexdec($lengthHex) === strlen($body);

        $headerPattern = '/\A"(\*?)(SIA-DCS|ADM-CID|NULL)"(\d{4})(R[0-9A-Fa-f]{1,6})?(L[0-9A-Fa-f]{1,6})?(?:#([0-9A-Fa-f]{3,16}))?\[(.*)\z/s';
        if (preg_match($headerPattern, $body, $matches) !== 1) {
            return $this->unsupportedOrMalformed($body, $lengthMatches);
        }

        $encrypted = $matches[1] === '*';
        $token = $matches[2];
        $sequence = $matches[3];
        $receiver = $matches[4] !== '' ? strtoupper($matches[4]) : null;
        $linePrefix = $matches[5] !== '' ? strtoupper($matches[5]) : 'L0';
        $headerAccount = $matches[6] !== '' ? strtoupper($matches[6]) : null;
        $remainder = $matches[7];

        $envelope = [
            'token' => $token,
            'encrypted' => $encrypted,
            'sequence' => $sequence,
            'receiver' => $receiver,
            'line_prefix' => $linePrefix,
            'account' => $headerAccount,
            'length_matches' => $lengthMatches,
        ];

        if ($encrypted) {
            if ($aesKeyBytes === null || ! in_array(strlen($aesKeyBytes), [16, 24, 32], true)) {
                return ['ok' => false, 'reason_code' => 'encryption_key_required'] + $envelope;
            }

            $plaintext = $this->decrypt(rtrim($remainder), $aesKeyBytes);
            if ($plaintext === null) {
                return ['ok' => false, 'reason_code' => 'decryption_failed'] + $envelope;
            }

            // Drop the pad: any characters other than `|`, `[` and `]`, then an optional `|`.
            $remainder = (string) preg_replace('/\A[^|\[\]]*\|?/', '', $plaintext, 1);
        }

        if (preg_match('/\A([^\]]*)\]((?:\[[^\[\]]*\])*)(?:_(\S+))?\s*\z/s', $remainder, $content) !== 1) {
            return ['ok' => false, 'reason_code' => 'missing_closing_bracket'] + $envelope;
        }

        $data = $content[1];
        $account = $headerAccount;
        if ($account === null && preg_match('/\A#([0-9A-Fa-f]{3,16})\|/', $data, $dataAccount) === 1) {
            $account = strtoupper($dataAccount[1]);
        }
        if ($account === null) {
            return ['ok' => false, 'reason_code' => 'account_missing'] + $envelope;
        }

        $extendedData = [];
        if ($content[2] !== '' && preg_match_all('/\[([^\[\]]*)\]/', $content[2], $blocks) > 0) {
            $extendedData = $blocks[1];
        }

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
            'extended_data' => $extendedData,
            'timestamp' => isset($content[3]) ? $this->parseTimestamp($content[3]) : null,
            'length_matches' => $lengthMatches,
        ];
    }

    /**
     * Whether a decoded frame's timestamp is acceptable at `$now`: required for encrypted frames, and
     * within 40 s before to 20 s after `$now` whenever present.
     *
     * @param  array<string, mixed>  $decoded
     */
    public static function timestampAcceptable(array $decoded, DateTimeImmutable $now): bool
    {
        $timestamp = $decoded['timestamp'] ?? null;
        if (! $timestamp instanceof DateTimeImmutable) {
            return ($decoded['encrypted'] ?? false) !== true;
        }

        $offset = $timestamp->getTimestamp() - $now->getTimestamp();

        return $offset >= -self::TIMESTAMP_PAST_SECONDS && $offset <= self::TIMESTAMP_FUTURE_SECONDS;
    }

    /** Plain `"ACK"` for an unencrypted frame. */
    public static function buildAck(string $sequence, ?string $receiver, string $linePrefix, string $account): string
    {
        return self::wrapBody(self::responseHeader('ACK', $sequence, $receiver, $linePrefix, $account).'[]');
    }

    /** `"DUH"`: the frame was read, but its message type or event can't be handled. Always plain. */
    public static function buildDuh(string $sequence, ?string $receiver, string $linePrefix, string $account): string
    {
        return self::wrapBody(self::responseHeader('DUH', $sequence, $receiver, $linePrefix, $account).'[]');
    }

    /**
     * `"NAK"`: the frame was refused (bad CRC, bad format, unknown account, wrong time). Always plain, and
     * always carries the receiver's UTC time so the panel can correct its clock.
     */
    public static function buildNak(DateTimeImmutable $now): string
    {
        return self::wrapBody('"NAK"0000R0L0A0[]'.self::formatTimestamp($now));
    }

    /**
     * Encrypted `"*ACK"` for an encrypted frame, using the same key. The plaintext `]_<timestamp>` is
     * padded on the left with `0` to whole 16-byte blocks before AES-CBC with a zero IV.
     */
    public static function buildEncryptedAck(
        string $sequence,
        ?string $receiver,
        string $linePrefix,
        string $account,
        #[\SensitiveParameter] string $aesKeyBytes,
        DateTimeImmutable $now,
    ): string {
        $plaintext = ']'.self::formatTimestamp($now);
        $length = strlen($plaintext);
        $padded = str_pad($plaintext, $length + 16 - ($length % 16), '0', STR_PAD_LEFT);

        $cipher = openssl_encrypt(
            $padded,
            self::cipherMethod($aesKeyBytes),
            $aesKeyBytes,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );
        if (! is_string($cipher)) {
            throw new \RuntimeException('The encrypted ACK could not be built.');
        }

        return self::wrapBody(
            self::responseHeader('*ACK', $sequence, $receiver, $linePrefix, $account).'['.strtoupper(bin2hex($cipher)),
        );
    }

    /**
     * Wrap a body in `<LF><CRC><LEN><BODY><CR>`, with the CRC over the body only.
     */
    public static function wrapBody(string $body): string
    {
        return "\n".self::crc16Arc($body).sprintf('%04X', strlen($body)).$body."\r";
    }

    /**
     * CRC-16/ARC (polynomial 0x8005 reflected as 0xA001, initial value 0, no final XOR), as 4 uppercase
     * hex characters. Check value: "123456789" gives BB3D.
     */
    public static function crc16Arc(string $bytes): string
    {
        $crc = 0x0000;
        $length = strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($bytes[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x0001) !== 0
                    ? (($crc >> 1) ^ 0xA001) & 0xFFFF
                    : ($crc >> 1) & 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }

    public static function formatTimestamp(DateTimeImmutable $moment): string
    {
        return '_'.$moment->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
    }

    private static function responseHeader(string $token, string $sequence, ?string $receiver, string $linePrefix, string $account): string
    {
        $seq = preg_match('/\A\d{4}\z/', $sequence) === 1 ? $sequence : '0000';
        $rcvr = $receiver !== null && preg_match('/\AR[0-9A-Fa-f]{1,6}\z/', $receiver) === 1 ? strtoupper($receiver) : '';
        $pref = preg_match('/\AL[0-9A-Fa-f]{1,6}\z/', $linePrefix) === 1 ? strtoupper($linePrefix) : 'L0';
        $acct = preg_match('/\A[0-9A-Fa-f]{3,16}\z/', $account) === 1 ? strtoupper($account) : '000';

        return sprintf('"%s"%s%s%s#%s', $token, $seq, $rcvr, $pref, $acct);
    }

    private static function cipherMethod(string $aesKeyBytes): string
    {
        return match (strlen($aesKeyBytes)) {
            16 => 'aes-128-cbc',
            24 => 'aes-192-cbc',
            32 => 'aes-256-cbc',
            default => throw new \InvalidArgumentException('SIA DC-09 keys are 16, 24 or 32 bytes.'),
        };
    }

    /** Decrypt hex ciphertext; null unless it is whole AES blocks that decrypt to printable text. */
    private function decrypt(string $cipherHex, #[\SensitiveParameter] string $aesKeyBytes): ?string
    {
        if ($cipherHex === '' || strlen($cipherHex) % 32 !== 0 || ! ctype_xdigit($cipherHex)) {
            return null;
        }

        $plaintext = openssl_decrypt(
            (string) hex2bin($cipherHex),
            self::cipherMethod($aesKeyBytes),
            $aesKeyBytes,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\x00", 16),
        );

        // A wrong key yields binary noise; the real plaintext is printable ASCII.
        if (! is_string($plaintext) || $plaintext === '' || preg_match('/\A[\x20-\x7E]+\z/', $plaintext) !== 1) {
            return null;
        }

        return $plaintext;
    }

    /**
     * A body that isn't one of the supported tokens. If it is still a well-formed DC-09 header, report
     * `unsupported_token` with what's needed for a DUH reply; otherwise it's malformed.
     *
     * @return array<string, mixed>
     */
    private function unsupportedOrMalformed(string $body, bool $lengthMatches): array
    {
        $pattern = '/\A"\*?([A-Z0-9-]{2,16})"(\d{4})(R[0-9A-Fa-f]{1,6})?(L[0-9A-Fa-f]{1,6})?(?:#([0-9A-Fa-f]{3,16}))?\[/';
        if (preg_match($pattern, $body, $matches) === 1 && ! in_array($matches[1], self::TOKENS, true)) {
            return [
                'ok' => false,
                'reason_code' => 'unsupported_token',
                'token' => $matches[1],
                'sequence' => $matches[2],
                'receiver' => ($matches[3] ?? '') !== '' ? strtoupper($matches[3]) : null,
                'line_prefix' => ($matches[4] ?? '') !== '' ? strtoupper($matches[4]) : 'L0',
                'account' => ($matches[5] ?? '') !== '' ? strtoupper($matches[5]) : null,
                'length_matches' => $lengthMatches,
            ];
        }

        return ['ok' => false, 'reason_code' => 'malformed_envelope'];
    }

    private function parseTimestamp(string $raw): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.self::TIMESTAMP_FORMAT, trim($raw), new DateTimeZone('UTC'));
        if (! $parsed instanceof DateTimeImmutable) {
            return null;
        }

        // Reject roll-overs such as 25:61:00 that createFromFormat would quietly accept.
        $errors = DateTimeImmutable::getLastErrors();

        return is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0) ? null : $parsed;
    }
}
