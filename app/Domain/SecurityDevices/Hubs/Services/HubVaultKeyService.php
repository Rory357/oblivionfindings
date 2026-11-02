<?php

namespace App\Domain\SecurityDevices\Hubs\Services;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Hubs\Exceptions\HubAwaitingApproval;
use App\Domain\SecurityDevices\Hubs\Exceptions\HubRemoved;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * The key that unlocks a hub's encrypted login store (plan v10, "Hub security").
 *
 * The hub keeps saved logins on disk encrypted with this key and holds the key only in
 * memory, fetching it from here each time it starts. The server hands it out only while the
 * hub is approved and not removed, and removing a hub destroys it, so a removed hub can't
 * read its old logins even if it was offline when it was removed.
 */
final class HubVaultKeyService
{
    private const KEY_BYTES = 32;

    /** Make a key for a hub that doesn't have one yet (on approval). */
    public function ensureKey(MonitoringCollector $collector): void
    {
        if (is_string($collector->vault_key_ciphertext) && $collector->vault_key_ciphertext !== '') {
            return;
        }
        $key = random_bytes(self::KEY_BYTES);
        try {
            $collector->forceFill([
                'vault_key_ciphertext' => Crypt::encryptString(base64_encode($key)),
                'vault_key_rotated_at' => now(),
            ])->save();
        } finally {
            sodium_memzero($key);
        }
    }

    /** Destroy the key: everything the hub stored with it becomes unreadable. */
    public function destroyKey(MonitoringCollector $collector): void
    {
        $collector->forceFill(['vault_key_ciphertext' => null, 'vault_key_rotated_at' => now()])->save();
    }

    /**
     * The key sealed to this hub's own public key, so only the hub can open it.
     *
     * @return array{version: int, collector_id: string, sealed_key: string, rotated_at: string|null}
     */
    public function sealedFor(MonitoringCollector $collector): array
    {
        if ($collector->revoked_at !== null || $collector->status === 'revoked') {
            throw new HubRemoved;
        }
        if ($collector->approved_at === null) {
            throw new HubAwaitingApproval;
        }
        if (! is_string($collector->vault_key_ciphertext) || $collector->vault_key_ciphertext === '') {
            $this->ensureKey($collector);
            $collector->refresh();
        }

        $encoded = Crypt::decryptString((string) $collector->vault_key_ciphertext);
        $key = base64_decode($encoded, true);
        sodium_memzero($encoded);
        if (! is_string($key) || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('Hub login-store key is unavailable.');
        }

        try {
            $sealed = sodium_crypto_box_seal($key, $this->encryptionPublicKey($collector));
        } finally {
            sodium_memzero($key);
        }

        return [
            'version' => 1,
            'collector_id' => (string) $collector->collector_uuid,
            'sealed_key' => base64_encode($sealed),
            'rotated_at' => $collector->vault_key_rotated_at?->toIso8601String(),
        ];
    }

    private function encryptionPublicKey(MonitoringCollector $collector): string
    {
        $signing = is_string($collector->public_key) ? base64_decode($collector->public_key, true) : false;
        if (! is_string($signing) || strlen($signing) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Hub encryption identity is unavailable.');
        }

        return sodium_crypto_sign_ed25519_pk_to_curve25519($signing);
    }
}
