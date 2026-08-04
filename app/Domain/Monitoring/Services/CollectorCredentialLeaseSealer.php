<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Models\MonitoringCollector;
use RuntimeException;
use Throwable;

final class CollectorCredentialLeaseSealer
{
    /**
     * @return array{
     *     version: int,
     *     collector_id: string,
     *     site_id: int,
     *     device_id: string,
     *     protocol: string,
     *     target: string,
     *     expires_at: string,
     *     sealed_material: string
     * }
     */
    public function seal(
        MonitoringCollector $collector,
        string $deviceId,
        string $protocol,
        string $target,
        CredentialLease $lease,
    ): array {
        $collectorId = (string) $collector->collector_uuid;
        $siteId = (int) $collector->site_id;
        $expiresAt = $lease->expiresAt->utc();
        $material = null;
        $plaintext = null;

        try {
            $publicKey = $this->encryptionPublicKey($collector);
            $material = $lease->material();
            $plaintext = json_encode([
                'version' => 1,
                'collector_id' => $collectorId,
                'site_id' => $siteId,
                'device_id' => $deviceId,
                'protocol' => $protocol,
                'target' => $target,
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'material' => $material,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($plaintext) > 1_048_576) {
                throw new RuntimeException('Collector credential lease material is too large.');
            }
            $sealed = sodium_crypto_box_seal($plaintext, $publicKey);

            return [
                'version' => 1,
                'collector_id' => $collectorId,
                'site_id' => $siteId,
                'device_id' => $deviceId,
                'protocol' => $protocol,
                'target' => $target,
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'sealed_material' => base64_encode($sealed),
            ];
        } catch (Throwable $exception) {
            throw new RuntimeException('Collector credential lease could not be protected.', previous: $exception);
        } finally {
            if (is_string($plaintext) && $plaintext !== '') {
                sodium_memzero($plaintext);
            }
            if (is_array($material)) {
                $this->destroy($material);
            }
        }
    }

    private function encryptionPublicKey(MonitoringCollector $collector): string
    {
        $signingPublicKey = is_string($collector->public_key)
            ? base64_decode($collector->public_key, true)
            : false;
        if (! is_string($signingPublicKey)
            || strlen($signingPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Collector encryption identity is unavailable.');
        }

        $encryptionPublicKey = sodium_crypto_sign_ed25519_pk_to_curve25519($signingPublicKey);
        if (strlen($encryptionPublicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new RuntimeException('Collector encryption identity is invalid.');
        }

        return $encryptionPublicKey;
    }

    /** @param array<string, scalar|null> $material */
    private function destroy(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
