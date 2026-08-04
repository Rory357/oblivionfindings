<?php

namespace App\Support\Security;

use DateTimeInterface;
use Stringable;
use Throwable;

final class SensitiveDataRedactor
{
    public const string REDACTED = '[REDACTED]';

    private const array SENSITIVE_KEYS = [
        'authorization', 'proxy_authorization', 'cookie', 'set_cookie',
        'password', 'passwd', 'passphrase', 'secret', 'client_secret',
        'token', 'access_token', 'refresh_token', 'id_token',
        'api_key', 'apikey', 'private_key', 'client_private_key',
        'signing_secret_key', 'request_signing_secret_key',
        'material', 'credential_material', 'lease_id', 'secret_manager_reference',
    ];

    /** @param array<mixed> $values @return array<mixed> */
    public function context(array $values, int $depth = 0): array
    {
        if ($depth >= 10) {
            return ['truncated' => true];
        }

        $redacted = [];
        $count = 0;
        foreach ($values as $key => $value) {
            if (++$count > 256) {
                $redacted['truncated'] = true;
                break;
            }
            $normalisedKey = is_string($key) ? $this->normaliseKey($key) : null;
            $redacted[$key] = $normalisedKey !== null && $this->sensitiveKey($normalisedKey)
                ? self::REDACTED
                : $this->value($value, $depth + 1);
        }

        return $redacted;
    }

    public function message(string $message): string
    {
        $message = substr($message, 0, 65_536);
        $patterns = [
            '/\b(Bearer|Basic)\s+[A-Za-z0-9+\/_=.-]+/i' => '$1 '.self::REDACTED,
            '/-----BEGIN(?: [A-Z0-9]+)? PRIVATE KEY-----.*?-----END(?: [A-Z0-9]+)? PRIVATE KEY-----/is' => self::REDACTED,
            '#(https?://)[^\s/@:]+:[^\s/@]+@#i' => '$1'.self::REDACTED.'@',
            '/\b(password|passwd|passphrase|secret|client_secret|token|access_token|refresh_token|api[_-]?key|private[_-]?key|lease_id)\b(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;&]+)/i' => '$1$2'.self::REDACTED,
            '/([?&](?:password|passwd|passphrase|secret|token|access_token|refresh_token|api[_-]?key|lease_id)=)[^&#\s]*/i' => '$1'.self::REDACTED,
            '/("(?:password|passwd|passphrase|secret|client_secret|token|access_token|refresh_token|api_key|private_key|lease_id|material)"\s*:\s*)("(?:\\.|[^"])*"|[^,}\s]+)/i' => '$1"'.self::REDACTED.'"',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $message = preg_replace($pattern, $replacement, $message) ?? self::REDACTED;
        }

        return $message;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            return $this->context($value, $depth);
        }
        if ($value instanceof Throwable) {
            return [
                'exception_class' => $value::class,
                'exception_message' => $this->message($value->getMessage()),
                'exception_code' => is_int($value->getCode()) ? $value->getCode() : 0,
                'trace_hash' => hash('sha256', $value->getFile().':'.$value->getLine()),
            ];
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof Stringable) {
            return $this->message((string) $value);
        }
        if (is_string($value)) {
            return $this->message($value);
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return ['object_class' => $value::class];
    }

    private function sensitiveKey(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true)
            || preg_match('/(?:^|_)(?:password|passwd|passphrase|secret|token|api_key|private_key)$/', $key) === 1;
    }

    private function normaliseKey(string $key): string
    {
        return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? ''), '_');
    }
}
