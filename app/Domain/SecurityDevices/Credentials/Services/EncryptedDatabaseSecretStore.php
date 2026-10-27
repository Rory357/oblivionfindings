<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;
use App\Domain\SecurityDevices\Credentials\Models\MonitoringEncryptedSecret;
use Closure;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EncryptedDatabaseSecretStore implements SecretManagerSecretStore
{
    /** @param (Closure(): Encrypter)|null $encrypterResolver */
    public function __construct(
        private readonly ?Closure $encrypterResolver = null,
    ) {}

    public function put(SecretWriteRequest $request): StoredSecretVersion
    {
        $reference = $request->opaqueReference();
        $expectedVersion = $request->expectedVersion();
        $referenceHash = $this->fingerprint($reference);
        $material = $request->consumeMaterial();
        $encrypter = $this->encrypter();
        $json = '';

        try {
            $json = json_encode($material, JSON_THROW_ON_ERROR);
            $encryptedPayload = $encrypter->encryptString($json);
            $encryptedReference = $encrypter->encryptString($reference);
        } finally {
            $this->scrubArray($material);
            $this->scrubString($json);
        }

        $version = DB::transaction(function () use ($referenceHash, $expectedVersion, $encryptedReference, $encryptedPayload): int {
            $currentVersion = (int) (MonitoringEncryptedSecret::query()
                ->where('reference_hash', $referenceHash)
                ->lockForUpdate()
                ->max('version') ?? 0);

            if ($currentVersion !== $expectedVersion) {
                throw new RuntimeException('Static secret compare and set failed.');
            }

            $nextVersion = $currentVersion + 1;

            MonitoringEncryptedSecret::query()->create([
                'reference_hash' => $referenceHash,
                'opaque_reference_encrypted' => $encryptedReference,
                'version' => $nextVersion,
                'encrypted_payload' => $encryptedPayload,
            ]);

            return $nextVersion;
        }, 3);

        return new StoredSecretVersion($reference, $version, $referenceHash);
    }

    public function metadata(SecretReferenceRequest $request): StoredSecretVersion
    {
        $reference = $request->opaqueReference();
        $referenceHash = $this->fingerprint($reference);

        $version = (int) (MonitoringEncryptedSecret::query()
            ->where('reference_hash', $referenceHash)
            ->max('version') ?? 0);

        if ($version < 1) {
            throw new RuntimeException('Static secret metadata is unavailable.');
        }

        return new StoredSecretVersion($reference, $version, $referenceHash);
    }

    public function softDelete(SecretVersionRequest $request): StoredSecretVersion
    {
        $reference = $request->opaqueReference();
        $referenceHash = $this->fingerprint($reference);
        $version = $request->version();

        DB::transaction(function () use ($referenceHash, $version): void {
            $record = MonitoringEncryptedSecret::query()
                ->where('reference_hash', $referenceHash)
                ->where('version', $version)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof MonitoringEncryptedSecret || $record->destroyed_at !== null) {
                throw new RuntimeException('Static secret version is unavailable.');
            }

            if ($record->soft_deleted_at === null) {
                $record->forceFill(['soft_deleted_at' => now()])->save();
            }
        }, 3);

        return new StoredSecretVersion($reference, $version, $referenceHash);
    }

    public function restore(SecretVersionRequest $request): StoredSecretVersion
    {
        $reference = $request->opaqueReference();
        $referenceHash = $this->fingerprint($reference);
        $version = $request->version();

        DB::transaction(function () use ($referenceHash, $version): void {
            $record = MonitoringEncryptedSecret::query()
                ->where('reference_hash', $referenceHash)
                ->where('version', $version)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof MonitoringEncryptedSecret || $record->destroyed_at !== null) {
                throw new RuntimeException('Static secret version is unavailable.');
            }

            $record->forceFill(['soft_deleted_at' => null])->save();
        }, 3);

        return new StoredSecretVersion($reference, $version, $referenceHash);
    }

    public function destroy(SecretVersionRequest $request): StoredSecretVersion
    {
        $reference = $request->opaqueReference();
        $referenceHash = $this->fingerprint($reference);
        $version = $request->version();

        DB::transaction(function () use ($referenceHash, $version): void {
            $record = MonitoringEncryptedSecret::query()
                ->where('reference_hash', $referenceHash)
                ->where('version', $version)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof MonitoringEncryptedSecret) {
                throw new RuntimeException('Static secret version is unavailable.');
            }

            $record->forceFill([
                'encrypted_payload' => null,
                'destroyed_at' => now(),
            ])->save();
        }, 3);

        return new StoredSecretVersion($reference, $version, $referenceHash);
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

    /** @param array<string, scalar|null> $material */
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
