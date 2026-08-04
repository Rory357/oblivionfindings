<?php

namespace App\Domain\Monitoring\Data;

use App\Domain\Monitoring\Exceptions\EgressDenied;
use Throwable;

final readonly class ProbeTarget
{
    private const MAX_URL_BYTES = 8192;

    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public ?string $path,
    ) {}

    public static function tcp(string $host, int $port): self
    {
        return new self('tcp', self::normaliseHost($host), self::validPort($port), null);
    }

    public static function icmp(string $host): self
    {
        return new self('icmp', self::normaliseHost($host), 0, null);
    }

    public static function dns(string $server, int $port = 53): self
    {
        return new self('dns', self::normaliseHost($server), self::validPort($port), null);
    }

    public static function tls(string $host, int $port = 443): self
    {
        return new self('tls', self::normaliseHost($host), self::validPort($port), null);
    }

    public static function snmp(string $host, int $port = 161): self
    {
        return new self('snmp', self::normaliseHost($host), self::validPort($port), null);
    }

    public static function ssh(string $host, int $port = 22): self
    {
        return new self('ssh', self::normaliseHost($host), self::validPort($port), null);
    }

    public static function winrm(string $url): self
    {
        $target = self::http($url);
        if ($target->scheme !== 'https' || $target->path !== '/wsman') {
            throw new EgressDenied('WinRM requires an HTTPS /wsman target');
        }

        return new self('winrm', $target->host, $target->port, $target->path);
    }

    public static function http(string $url): self
    {
        if ($url === '' || strlen($url) > self::MAX_URL_BYTES
            || preg_match('/[\\x00-\\x20\\x7f]/', $url) === 1
            || str_contains($url, '\\')) {
            throw new EgressDenied('invalid target or path is forbidden');
        }

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            throw new EgressDenied('invalid target');
        }

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new EgressDenied('invalid target');
        }

        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new EgressDenied('userinfo is forbidden');
        }

        if (array_key_exists('fragment', $parts)) {
            throw new EgressDenied('fragment is forbidden');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new EgressDenied('scheme is forbidden');
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')
            || ! self::validEncodedComponent($path)) {
            throw new EgressDenied('path is forbidden');
        }

        if (array_key_exists('query', $parts)) {
            $query = (string) $parts['query'];
            if (! self::validEncodedComponent($query)) {
                throw new EgressDenied('path is forbidden');
            }
            self::assertNoCredentialParameters($query);
            $path .= '?'.$query;
        }

        return new self(
            $scheme,
            self::normaliseHost($parts['host']),
            self::validPort((int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))),
            $path,
        );
    }

    private static function validPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new EgressDenied('port is invalid');
        }

        return $port;
    }

    private static function validEncodedComponent(string $value): bool
    {
        $decoded = rawurldecode($value);

        return strlen($value) <= self::MAX_URL_BYTES
            && preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 1
            && preg_match('/[\\x00-\\x1f\\x7f]/', $decoded) !== 1
            && ! str_contains($decoded, '\\');
    }

    private static function assertNoCredentialParameters(string $query): void
    {
        $credentialNames = [
            'auth',
            'authorization',
            'credential',
            'credentials',
            'key',
            'password',
            'passwd',
            'secret',
            'sig',
            'signature',
            'token',
        ];

        foreach (preg_split('/[&;]/', $query) ?: [] as $parameter) {
            $encodedName = explode('=', $parameter, 2)[0];
            $name = str_replace('+', ' ', $encodedName);

            for ($decodePass = 0; $decodePass < 3; $decodePass++) {
                $decodedName = rawurldecode($name);
                if ($decodedName === $name) {
                    break;
                }
                $name = $decodedName;
            }

            if (rawurldecode($name) !== $name) {
                throw new EgressDenied('credential query parameters are forbidden');
            }

            $name = strtolower($name);
            $segments = preg_split('/[^a-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $compactName = implode('', $segments);

            if (array_intersect($segments, $credentialNames) !== []
                || preg_match('/(?:token|secret|password|passwd|signature|credentials?)$/', $compactName) === 1
                || preg_match('/^(?:api|auth|private|public|secret|signing|access)?key$/', $compactName) === 1
                || in_array($compactName, ['bearer', 'jwt', 'sas'], true)) {
                throw new EgressDenied('credential query parameters are forbidden');
            }
        }
    }

    private static function normaliseHost(string $host): string
    {
        $host = trim($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === '' || str_contains($host, '%') || preg_match('/[\\x00-\\x20\\x7f\\/\\\\@]/', $host) === 1) {
            throw new EgressDenied('host is invalid');
        }

        $packed = @inet_pton($host);
        if ($packed !== false) {
            if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                return inet_ntop(substr($packed, 12));
            }

            return strtolower((string) inet_ntop($packed));
        }

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if ($host === '' || str_ends_with($host, '.')) {
            throw new EgressDenied('host is invalid');
        }

        if (preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host) === 1) {
            throw new EgressDenied('host is invalid');
        }

        if (! function_exists('idn_to_ascii')
            || ! defined('IDNA_NONTRANSITIONAL_TO_ASCII')
            || ! defined('INTL_IDNA_VARIANT_UTS46')) {
            throw new EgressDenied('host normalisation is unavailable');
        }

        $info = [];
        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46, $info);
        $ascii = is_string($ascii) ? strtolower($ascii) : '';

        if ($ascii === '' || strlen($ascii) > 253 || ($info['errors'] ?? 0) !== 0) {
            throw new EgressDenied('host is invalid');
        }

        foreach (explode('.', $ascii) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                throw new EgressDenied('host is invalid');
            }
        }

        return $ascii;
    }
}
