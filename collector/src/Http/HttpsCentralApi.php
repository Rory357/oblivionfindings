<?php

namespace Oblivion\Collector\Http;

use JsonException;
use Oblivion\Collector\Contracts\CentralApi;
use Oblivion\Collector\Exceptions\CentralApiFailure;

final readonly class HttpsCentralApi implements CentralApi
{
    private const int MAX_RESPONSE_BYTES = 2_097_152;

    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private string $tlsPublicKeyPin,
        private ?string $requestSigningSecretKey = null,
        private ?string $clientCertificateFile = null,
        private ?string $clientPrivateKeyFile = null,
    ) {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new CentralApiFailure('Collector central URL must be a clean HTTPS origin.');
        }
        if (preg_match('/\Asha256\/\/[A-Za-z0-9+\/=]{43,45}\z/', $tlsPublicKeyPin) !== 1) {
            throw new CentralApiFailure('Collector TLS public-key pin is invalid.');
        }
        if ($requestSigningSecretKey !== null
            && strlen($requestSigningSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
        ) {
            throw new CentralApiFailure('Collector request signing key is invalid.');
        }
        if ($requestSigningSecretKey !== null
            && (! is_string($clientCertificateFile) || ! is_file($clientCertificateFile)
                || ! is_string($clientPrivateKeyFile) || ! is_file($clientPrivateKeyFile))) {
            throw new CentralApiFailure('Collector mTLS identity is unavailable.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function enrol(string $oneTimeToken, string $collectorId, string $collectorPublicKey): array
    {
        if ($oneTimeToken === ''
            || strlen($oneTimeToken) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $oneTimeToken) === 1
        ) {
            throw new CentralApiFailure('Collector enrolment token is invalid.');
        }

        return $this->request('POST', '/api/monitoring/collectors/enrol', [
            'collector_id' => $collectorId,
            'collector_public_key' => base64_encode($collectorPublicKey),
        ], $oneTimeToken);
    }

    public function configuration(string $collectorId, int $afterSequence): string
    {
        $response = $this->request('POST', '/api/monitoring/collectors/configuration', [
            'collector_id' => $collectorId,
            'after_sequence' => max(0, $afterSequence),
        ]);
        $envelope = $response['envelope'] ?? null;
        if (! is_string($envelope) || $envelope === '') {
            throw new CentralApiFailure('Central configuration response is invalid.');
        }

        return $envelope;
    }

    public function upload(string $collectorId, array $items): array
    {
        if ($items === [] || count($items) > 1000) {
            throw new CentralApiFailure('Collector upload batch is invalid.');
        }
        $response = $this->request('POST', '/api/monitoring/collectors/observations', [
            'collector_id' => $collectorId,
            'items' => array_values($items),
        ]);
        $ids = $response['acknowledged_ids'] ?? null;
        $sequence = $response['acknowledged_source_sequence'] ?? null;
        if (! is_array($ids)
            || ! array_is_list($ids)
            || array_any($ids, fn (mixed $id): bool => ! is_string($id) || $id === '')
            || count(array_unique($ids)) !== count($ids)
            || ! is_int($sequence)
            || $sequence < 0
        ) {
            throw new CentralApiFailure('Central acknowledgement is invalid.');
        }

        return ['acknowledged_ids' => $ids, 'acknowledged_source_sequence' => $sequence];
    }

    public function heartbeat(string $collectorId, array $status): void
    {
        $this->request('POST', '/api/monitoring/collectors/heartbeat', [
            'collector_id' => $collectorId,
            'status' => $status,
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $payload, ?string $oneTimeToken = null): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > 2_097_152) {
            throw new CentralApiFailure('Collector request exceeds its size limit.');
        }
        $timestamp = gmdate(DATE_ATOM);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($oneTimeToken !== null) {
            $headers[] = 'Authorization: Bearer '.$oneTimeToken;
        } else {
            if ($this->requestSigningSecretKey === null) {
                throw new CentralApiFailure('Collector request signing key is unavailable.');
            }
            $nonce = bin2hex(random_bytes(24));
            $signature = sodium_crypto_sign_detached(
                $method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body),
                $this->requestSigningSecretKey,
            );
            $headers[] = 'X-Oblivion-Collector-Timestamp: '.$timestamp;
            $headers[] = 'X-Oblivion-Collector-Nonce: '.$nonce;
            $headers[] = 'X-Oblivion-Collector-Signature: '.base64_encode($signature);
        }

        $response = '';
        $handle = curl_init($this->baseUrl.$path);
        if ($handle === false) {
            throw new CentralApiFailure('Collector HTTPS client is unavailable.');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PINNEDPUBLICKEY => $this->tlsPublicKeyPin,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;

                return strlen($chunk);
            },
        ];
        if ($oneTimeToken === null) {
            $options[CURLOPT_SSLCERT] = $this->clientCertificateFile;
            $options[CURLOPT_SSLKEY] = $this->clientPrivateKeyFile;
        }
        curl_setopt_array($handle, $options);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($success === false || $status < 200 || $status >= 300) {
            throw new CentralApiFailure('Central HTTPS request failed with a bounded transport outcome: '.($status ?: 'unavailable').'.');
        }

        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CentralApiFailure('Central HTTPS response is invalid.', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new CentralApiFailure('Central HTTPS response is invalid.');
        }

        return $decoded;
    }
}
