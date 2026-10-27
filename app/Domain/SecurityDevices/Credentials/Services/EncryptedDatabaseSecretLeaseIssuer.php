<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Models\MonitoringEncryptedSecret;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EncryptedDatabaseSecretLeaseIssuer implements SecretManagerLeaseIssuer, SecretManagerRestoreProbe
{
    /** @param (Closure(): Encrypter)|null $encrypterResolver */
    public function __construct(
        private readonly ?Closure $encrypterResolver = null,
    ) {}

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        $reference = $request->secretManagerReference();
        $referenceHash = $this->fingerprint($reference);

        $query = MonitoringEncryptedSecret::query()
            ->where('reference_hash', $referenceHash)
            ->whereNull('soft_deleted_at')
            ->whereNull('destroyed_at')
            ->whereNotNull('encrypted_payload');

        if ($request->secretVersion() !== null) {
            $query->where('version', $request->secretVersion());
        } else {
            $query->orderByDesc('version');
        }

        $record = $query->first();
        if (! $record instanceof MonitoringEncryptedSecret || ! is_string($record->encrypted_payload) || $record->encrypted_payload === '') {
            throw new RuntimeException('Credential lease is unavailable.');
        }

        $decrypted = '';
        $material = [];

        try {
            $decrypted = $this->encrypter()->decryptString($record->encrypted_payload);
            $decoded = json_decode($decrypted, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || $decoded === []) {
                throw new RuntimeException('Credential lease is unavailable.');
            }
            $material = $decoded;

            return new CredentialLease(
                leaseId: 'db-lease:'.Str::uuid(),
                expiresAt: $request->expiresAt,
                material: $material,
            );
        } catch (Throwable) {
            throw new RuntimeException('Credential lease is unavailable.');
        } finally {
            $this->scrubString($decrypted);
            $this->scrubArray($material);
        }
    }

    public function revoke(#[\SensitiveParameter] string $leaseId): void
    {
        if (trim($leaseId) === '') {
            throw new RuntimeException('Credential lease identifier is invalid.');
        }
    }

    public function healthy(): bool
    {
        try {
            if (trim((string) config('app.key')) === '') {
                return false;
            }

            if (! Schema::hasTable('monitoring_encrypted_secrets')) {
                return false;
            }

            $encrypter = $this->encrypter();
            $probe = $encrypter->encryptString('oblivion-readiness-probe');

            return $encrypter->decryptString($probe) === 'oblivion-readiness-probe';
        } catch (Throwable) {
            return false;
        }
    }

    private function encrypter(): Encrypter
    {
        if ($this->encrypterResolver instanceof Closure) {
            return ($this->encrypterResolver)();
        }

        return app(Encrypter::class);
    }

    private function fingerprint(#[\SensitiveParameter] string $reference): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Application encryption key is unavailable.');
        }

        return hash_hmac('sha256', $reference, $key);
    }

    /** @param array<string, mixed> $material */
    private function scrubArray(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value)) {
                $this->scrubString($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }

    private function scrubString(string &$value): void
    {
        if ($value === '') {
            return;
        }

        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        } else {
            $value = str_repeat("\0", strlen($value));
        }
    }
}
