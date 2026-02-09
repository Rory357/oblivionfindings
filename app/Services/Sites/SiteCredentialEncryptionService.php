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
     * Re-encrypt all credentials (for key rotation)
     */
    public function rotateAllCredentials(): int
    {
        $count = 0;
        
        \App\Models\SiteCredential::chunk(100, function ($credentials) use (&$count) {
            foreach ($credentials as $credential) {
                try {
                    $decrypted = $this->decrypt($credential->encrypted_value);
                    $newEncrypted = $this->encrypt($decrypted);
                    
                    $credential->update([
                        'encrypted_value' => $newEncrypted['value'],
                        'last_rotated_at' => now(),
                    ]);
                    
                    $count++;
                } catch (\Exception $e) {
                    // Log error but continue
                    \Log::error('Failed to rotate credential', [
                        'credential_id' => $credential->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $count;
    }

    /**
     * Mask a credential value for display
     */
    public function mask(string $value, string $type = 'password'): string
    {
        $length = strlen($value);
        
        return match ($type) {
            'password', 'pin' => str_repeat('•', min($length, 8)),
            'key', 'combo' => str_repeat('•', 4) . '-' . str_repeat('•', 4),
            default => str_repeat('•', min($length, 8)),
        };
    }
}
