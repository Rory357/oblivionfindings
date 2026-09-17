<?php

namespace App\Services\Sites;

use Illuminate\Support\Facades\Crypt;

class SiteCredentialEncryptionService
{
    /**
     * Encrypt credential value
     */
    public function encrypt(string $value): array
    {
        $encrypted = Crypt::encryptString($value);
        
        return [
            'value' => $encrypted,
            'hash' => hash_hmac('sha256', $value, config('app.key')),
        ];
    }

    /**
     * Decrypt credential value
     */
    public function decrypt(string $encryptedValue): string
    {
        return Crypt::decryptString($encryptedValue);
    }

    /**
     * Verify credential matches hash (without exposing value)
     */
    public function verify(string $encryptedValue, string $hash): bool
    {
        try {
            $decrypted = $this->decrypt($encryptedValue);
            $calculatedHash = hash_hmac('sha256', $decrypted, config('app.key'));
            return hash_equals($hash, $calculatedHash);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Explicit storage-key maintenance. This never attests an external change.
     * Previous application keys must remain available until backups expire.
     */
    public function rotateAllCredentials(\App\Models\User $actor): int
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($actor) {
            $actor = \App\Models\User::findOrFail($actor->id);
            $access = app(SiteCredentialAccess::class);
            $count = 0;
            foreach (\App\Models\SiteCredential::orderBy('id')->lockForUpdate()->get() as $credential) {
                $access->authorize($actor, $credential, 'manage', true);
                app(SiteCredentialHistory::class)->retain($credential, $actor, 'before_key_maintenance');
                $credential->encrypted_value = $this->encrypt($this->decrypt($credential->encrypted_value))['value'];
                if ($credential->totp_secret_encrypted) $credential->totp_secret_encrypted = Crypt::encryptString(Crypt::decryptString($credential->totp_secret_encrypted));
                $credential->iv = null;
                $credential->storage_key_maintained_at = now();
                $credential->lock_version++;
                $credential->save();
                app(SiteCredentialHistory::class)->retain($credential, $actor, 'storage_key_maintenance');
                \App\Models\SiteCredentialAuditLog::create([
                    'credential_id' => $credential->id, 'site_id' => $credential->site_id,
                    'credential_label' => $credential->label, 'credential_type' => $credential->credential_type,
                    'user_id' => $actor->id, 'action' => 'storage_key_maintenance', 'created_at' => now(),
                ]);
                $count++;
            }
            return $count;
        });
    }

    /**
     * Mask a credential value for display
     */
    public function mask(string $value, string $type = 'password'): string
    {
        $length = strlen($value);
        
        return match ($type) {
            'password', 'pin' => str_repeat('*', min($length, 8)),
            'key', 'combo' => str_repeat('*', 4) . '-' . str_repeat('*', 4),
            default => str_repeat('*', min($length, 8)),
        };
    }
}
