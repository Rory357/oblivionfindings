<?php

namespace App\Services\Integration;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class UnifiTransportSecurity
{
    private const MAX_CA_BUNDLE_BYTES = 5 * 1024 * 1024;

    private const PEM_CERTIFICATE_PATTERN = '/-----BEGIN CERTIFICATE-----[\r\n]+[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----/';

    /** @param array<string, string> $headers */
    public function request(array $headers = []): PendingRequest
    {
        // Resolve and validate trust before a credential-bearing header can be
        // attached. Guzzle performs certificate-chain and hostname validation
        // when verify is true or a CA bundle path.
        $request = Http::withoutRedirecting()->withOptions([
            'verify' => $this->verificationOption(),
        ]);

        return $headers === [] ? $request : $request->withHeaders($headers);
    }

    public function verificationOption(): bool|string
    {
        $configured = config('integration-capabilities.unifi.ca_bundle');
        if ($configured === null || $configured === '') {
            return true;
        }

        if (! is_string($configured)) {
            throw new UnifiTransportConfigurationException;
        }

        $candidate = trim($configured);
        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            throw new UnifiTransportConfigurationException;
        }

        $resolved = realpath($candidate);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            throw new UnifiTransportConfigurationException;
        }

        clearstatcache(true, $resolved);
        $size = filesize($resolved);
        if ($size === false || $size < 1 || $size > self::MAX_CA_BUNDLE_BYTES) {
            throw new UnifiTransportConfigurationException;
        }

        $contents = @file_get_contents($resolved);
        if (! is_string($contents) || ! $this->isValidPemCaBundle($contents)) {
            throw new UnifiTransportConfigurationException;
        }

        return $resolved;
    }

    private function isValidPemCaBundle(string $contents): bool
    {
        $count = preg_match_all(self::PEM_CERTIFICATE_PATTERN, $contents, $matches);
        if (! is_int($count) || $count < 1 || ! function_exists('openssl_x509_read')) {
            return false;
        }

        foreach ($matches[0] as $certificate) {
            if (@openssl_x509_read($certificate) === false) {
                return false;
            }
        }

        $remainder = preg_replace(self::PEM_CERTIFICATE_PATTERN, '', $contents);
        if (! is_string($remainder)) {
            return false;
        }

        foreach (preg_split('/\R/', $remainder) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#')) {
                return false;
            }
        }

        return true;
    }
}
