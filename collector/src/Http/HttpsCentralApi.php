<?php

namespace Oblivion\Collector\Http;

use Closure;
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
        /** @var null|Closure(string, string, string, list<string>, bool): array{transport_ok: bool, status: int, body: string} */
        private ?Closure $testTransport = null,
    ) {
        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
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

    /** @return array{state: string, expected_identity_state: string, pinned_https_contract: string, initial_response: string, replay_attempt: string, samples: int} */
    public function verifyTransport(string $collectorId, string $expectedIdentityState, int $samples = 5): array
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $collectorId) !== 1
            || ! in_array($expectedIdentityState, ['active', 'revoked'], true)
            || $samples < 1
            || $samples > 20) {
            throw new CentralApiFailure('Collector transport evidence scope is invalid.');
        }

        $method = 'POST';
        $path = '/api/monitoring/collectors/configuration';
        $body = json_encode([
            'collector_id' => $collectorId,
            // Authentication must succeed before the controller rejects this
            // deliberately non-integer checkpoint. No configuration is issued.
            'after_sequence' => 'transport-evidence-only',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (range(1, $samples) as $_sample) {
            $headers = $this->requestHeaders($method, $path, $body);
            $first = $this->send($method, $path, $body, $headers, true);
            if ($expectedIdentityState === 'revoked') {
                $this->assertEvidenceResponse($first, 401, 'Collector authentication failed.');

                continue;
            }

            $this->assertEvidenceResponse($first, 422, 'Collector request is invalid.');
            $replay = $this->send($method, $path, $body, $headers, true);
            $this->assertEvidenceResponse($replay, 401, 'Collector authentication failed.');
        }

        return [
            'state' => 'response_contract_matched',
            'expected_identity_state' => $expectedIdentityState,
            'pinned_https_contract' => 'matched',
            'initial_response' => $expectedIdentityState === 'active'
                ? 'validation_rejected'
                : 'authentication_denied',
            'replay_attempt' => $expectedIdentityState === 'active'
                ? 'authentication_denied'
                : 'not_exercised',
            'samples' => $samples,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $method, string $path, array $payload, ?string $oneTimeToken = null): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > 2_097_152) {
            throw new CentralApiFailure('Collector request exceeds its size limit.');
        }
        $headers = $this->requestHeaders($method, $path, $body, $oneTimeToken);
        $result = $this->send($method, $path, $body, $headers, $oneTimeToken === null);
        if (! $result['transport_ok'] || $result['status'] < 200 || $result['status'] >= 300) {
            throw new CentralApiFailure('Central HTTPS request failed with a bounded transport outcome: '.($result['status'] ?: 'unavailable').'.');
        }

        try {
            $decoded = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CentralApiFailure('Central HTTPS response is invalid.', previous: $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new CentralApiFailure('Central HTTPS response is invalid.');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function requestHeaders(
        string $method,
        string $path,
        string $body,
        ?string $oneTimeToken = null,
    ): array {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($oneTimeToken !== null) {
            $headers[] = 'Authorization: Bearer '.$oneTimeToken;

            return $headers;
        }
        if ($this->requestSigningSecretKey === null) {
            throw new CentralApiFailure('Collector request signing key is unavailable.');
        }
        $timestamp = gmdate(DATE_ATOM);
        $nonce = bin2hex(random_bytes(24));
        $signature = sodium_crypto_sign_detached(
            $method."\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256', $body),
            $this->requestSigningSecretKey,
        );
        $headers[] = 'X-Oblivion-Collector-Timestamp: '.$timestamp;
        $headers[] = 'X-Oblivion-Collector-Nonce: '.$nonce;
        $headers[] = 'X-Oblivion-Collector-Signature: '.base64_encode($signature);

        return $headers;
    }

    /** @param list<string> $headers @return array{transport_ok: bool, status: int, body: string} */
    private function send(string $method, string $path, string $body, array $headers, bool $useMtls): array
    {
        if ($this->testTransport !== null) {
            $result = ($this->testTransport)($method, $path, $body, $headers, $useMtls);
            if (! is_bool($result['transport_ok'] ?? null)
                || ! is_int($result['status'] ?? null)
                || ! is_string($result['body'] ?? null)) {
                throw new CentralApiFailure('Collector test transport returned an invalid bounded outcome.');
            }

            return $result;
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
        if ($useMtls) {
            $options[CURLOPT_SSLCERT] = $this->clientCertificateFile;
            $options[CURLOPT_SSLKEY] = $this->clientPrivateKeyFile;
        }
        curl_setopt_array($handle, $options);
        $success = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [
            'transport_ok' => $success !== false,
            'status' => $status,
            'body' => $response,
        ];
    }

    /** @param array{transport_ok: bool, status: int, body: string} $result */
    private function assertEvidenceResponse(array $result, int $expectedStatus, string $expectedMessage): void
    {
        if (! $result['transport_ok'] || $result['status'] !== $expectedStatus) {
            throw new CentralApiFailure('Collector transport evidence response was not the expected fail-closed outcome.');
        }
        try {
            $decoded = json_decode($result['body'], true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new CentralApiFailure('Collector transport evidence response was invalid.', previous: $exception);
        }
        if (! is_array($decoded)
            || array_is_list($decoded)
            || ($decoded['message'] ?? null) !== $expectedMessage
            || array_diff(array_keys($decoded), ['message']) !== []) {
            throw new CentralApiFailure('Collector transport evidence response did not match the expected response contract.');
        }
    }
}
