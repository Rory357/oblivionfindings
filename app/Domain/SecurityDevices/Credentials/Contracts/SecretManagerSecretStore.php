<?php

namespace App\Domain\SecurityDevices\Credentials\Contracts;

use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;

interface SecretManagerSecretStore
{
    public function put(SecretWriteRequest $request): StoredSecretVersion;

    public function metadata(SecretReferenceRequest $request): StoredSecretVersion;

    public function softDelete(SecretVersionRequest $request): StoredSecretVersion;

    public function restore(SecretVersionRequest $request): StoredSecretVersion;

    public function destroy(SecretVersionRequest $request): StoredSecretVersion;
}
