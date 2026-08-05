<?php

namespace Tests\Support;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;
use Carbon\CarbonImmutable;
use RuntimeException;

final class FakeIntegrationSecretBackend implements SecretManagerLeaseIssuer, SecretManagerSecretStore
{
    public bool $available = true;

    public int $issues = 0;

    public int $revocations = 0;

    public int $issueFailuresRemaining = 0;

    public int $softDeleteFailuresRemaining = 0;

    /** @var array<string, array<int, array<string, scalar|null>>> */
    private array $versions = [];

    /** @var array<string, array<int, bool>> */
    private array $deleted = [];

    public function put(SecretWriteRequest $request): StoredSecretVersion
    {
        $this->assertAvailable();
        $reference = $request->opaqueReference();
        $current = $this->currentVersion($reference);
        if ($current !== $request->expectedVersion()) {
            throw new RuntimeException('Static secret compare and set failed.');
        }

        $version = $current + 1;
        $this->versions[$reference][$version] = $request->consumeMaterial();
        $this->deleted[$reference][$version] = false;

        return $this->stored($reference, $version);
    }

    public function metadata(SecretReferenceRequest $request): StoredSecretVersion
    {
        $this->assertAvailable();
        $reference = $request->opaqueReference();
        $version = $this->currentVersion($reference);
        if ($version < 1) {
            throw new RuntimeException('Static secret metadata is unavailable.');
        }

        return $this->stored($reference, $version);
    }

    public function softDelete(SecretVersionRequest $request): StoredSecretVersion
    {
        $this->assertAvailable();
        if ($this->softDeleteFailuresRemaining > 0) {
            $this->softDeleteFailuresRemaining--;

            throw new RuntimeException('Static secret soft delete failed.');
        }
        $this->assertVersion($request);
        $this->deleted[$request->opaqueReference()][$request->version()] = true;

        return $this->stored($request->opaqueReference(), $request->version());
    }

    public function restore(SecretVersionRequest $request): StoredSecretVersion
    {
        $this->assertAvailable();
        $this->assertVersion($request);
        $this->deleted[$request->opaqueReference()][$request->version()] = false;

        return $this->stored($request->opaqueReference(), $request->version());
    }

    public function destroy(SecretVersionRequest $request): StoredSecretVersion
    {
        $this->assertAvailable();
        $this->assertVersion($request);
        unset($this->versions[$request->opaqueReference()][$request->version()]);

        return $this->stored($request->opaqueReference(), $request->version());
    }

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        $this->assertAvailable();
        if ($this->issueFailuresRemaining > 0) {
            $this->issueFailuresRemaining--;

            throw new RuntimeException('Credential lease is unavailable.');
        }
        $reference = $request->secretManagerReference();
        $version = $request->secretVersion() ?? $this->currentVersion($reference);
        $material = $this->versions[$reference][$version] ?? null;
        if (! is_array($material) || ($this->deleted[$reference][$version] ?? true)) {
            throw new RuntimeException('Credential lease is unavailable.');
        }
        $this->issues++;

        return new CredentialLease(
            'fake-provider-lease-'.$this->issues,
            CarbonImmutable::now('UTC')->addMinute(),
            $material,
        );
    }

    public function revoke(#[\SensitiveParameter] string $leaseId): void
    {
        $this->assertAvailable();
        $this->revocations++;
    }

    /** @return array<string, scalar|null>|null */
    public function material(string $reference, int $version): ?array
    {
        return $this->versions[$reference][$version] ?? null;
    }

    private function assertAvailable(): void
    {
        if (! $this->available) {
            throw new RuntimeException('Fake secret manager is unavailable.');
        }
    }

    private function assertVersion(SecretVersionRequest $request): void
    {
        if (! isset($this->versions[$request->opaqueReference()][$request->version()])) {
            throw new RuntimeException('Static secret version is unavailable.');
        }
    }

    private function currentVersion(string $reference): int
    {
        $versions = array_keys($this->versions[$reference] ?? []);

        return $versions === [] ? 0 : max($versions);
    }

    private function stored(string $reference, int $version): StoredSecretVersion
    {
        return new StoredSecretVersion(
            $reference,
            $version,
            hash_hmac('sha256', $reference, (string) config('app.key')),
        );
    }
}
