<?php

namespace Oblivion\Collector\Security;

use JsonException;
use Oblivion\Collector\Exceptions\ConfigurationRejected;

final readonly class EnvelopeVerifier
{
    private string $publicKey;

    public function __construct(string $publicKey)
    {
        $decoded = strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? $publicKey
            : base64_decode($publicKey, true);

        if (! is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ConfigurationRejected('Pinned signing public key is invalid.');
        }

        $this->publicKey = $decoded;
    }

    /** @return array<string, mixed> */
    public function verify(string $envelope): array
    {
        if ($envelope === '' || strlen($envelope) > 2_097_152) {
            throw new ConfigurationRejected('Configuration signature envelope is invalid.');
        }

        try {
            $decoded = json_decode($envelope, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigurationRejected('Configuration signature envelope is invalid.', previous: $exception);
        }

        $payload = is_array($decoded) ? ($decoded['payload'] ?? null) : null;
        $signature = is_array($decoded) ? ($decoded['signature'] ?? null) : null;
        $payloadBytes = is_string($payload) ? base64_decode($payload, true) : false;
        $signatureBytes = is_string($signature) ? base64_decode($signature, true) : false;

        if (! is_string($payloadBytes)
            || ! is_string($signatureBytes)
            || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signatureBytes, $payloadBytes, $this->publicKey)
        ) {
            throw new ConfigurationRejected('Configuration signature verification failed.');
        }

        try {
            $verified = json_decode($payloadBytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigurationRejected('Signed configuration payload is invalid.', previous: $exception);
        }

        if (! is_array($verified) || array_is_list($verified)) {
            throw new ConfigurationRejected('Signed configuration payload is invalid.');
        }

        return $verified;
    }
}
