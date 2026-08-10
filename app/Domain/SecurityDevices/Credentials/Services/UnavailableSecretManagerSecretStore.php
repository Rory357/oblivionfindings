<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;
use RuntimeException;

final class UnavailableSecretManagerSecretStore implements SecretManagerSecretStore
{
    public function put(SecretWriteRequest $request): StoredSecretVersion
    {
        throw $this->unavailable();
    }

    public function metadata(SecretReferenceRequest $request): StoredSecretVersion
    {
        throw $this->unavailable();
    }

    public function softDelete(SecretVersionRequest $request): StoredSecretVersion
    {
        throw $this->unavailable();
    }

    public function restore(SecretVersionRequest $request): StoredSecretVersion
    {
        throw $this->unavailable();
    }

    public function destroy(SecretVersionRequest $request): StoredSecretVersion
    {
        throw $this->unavailable();
    }

    private function unavailable(): RuntimeException
    {
        return new RuntimeException('Static secret manager is not configured.');
    }
}
