<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerSecretStore;
use App\Domain\SecurityDevices\Credentials\Data\SecretReferenceRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretVersionRequest;
use App\Domain\SecurityDevices\Credentials\Data\SecretWriteRequest;
use App\Domain\SecurityDevices\Credentials\Data\StoredSecretVersion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HashicorpVaultSecretStore implements SecretManagerSecretStore
{
    public function put(SecretWriteRequest $request): StoredSecretVersion
    {
        $path = $this->path($request->opaqueReference());
        $fingerprint = $this->fingerprint($path);
        $client = $this->client();
        $material = $request->consumeMaterial();

        try {
            $response = $client->post($path->apiPath('data'), [
                'data' => $material,
                'options' => ['cas' => $request->expectedVersion()],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Vault static secret write failed.');
        } finally {
            $this->destroyMaterial($material);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Vault static secret write failed.');
        }
        $version = filter_var($response->json('data.version'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($version === false) {
            throw new RuntimeException('Vault returned invalid static secret version metadata.');
        }

        return $this->result($path, (int) $version, $fingerprint);
    }

    public function metadata(SecretReferenceRequest $request): StoredSecretVersion
    {
        $path = $this->path($request->opaqueReference());
        $fingerprint = $this->fingerprint($path);
        $client = $this->client();

        try {
            $response = $client->get($path->apiPath('metadata'));
        } catch (Throwable) {
            throw new RuntimeException('Vault static secret metadata is unavailable.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Vault static secret metadata is unavailable.');
        }
        $version = filter_var($response->json('data.current_version'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($version === false) {
            throw new RuntimeException('Vault returned invalid static secret version metadata.');
        }

        return $this->result($path, (int) $version, $fingerprint);
    }

    public function softDelete(SecretVersionRequest $request): StoredSecretVersion
    {
        return $this->versionOperation($request, 'delete', 'soft delete');
    }

    public function restore(SecretVersionRequest $request): StoredSecretVersion
    {
        return $this->versionOperation($request, 'undelete', 'restore');
    }

    public function destroy(SecretVersionRequest $request): StoredSecretVersion
    {
        return $this->versionOperation($request, 'destroy', 'final delete');
    }

    private function versionOperation(
        SecretVersionRequest $request,
        string $operation,
        string $failureAction,
    ): StoredSecretVersion {
        $path = $this->path($request->opaqueReference());
        $fingerprint = $this->fingerprint($path);
        $client = $this->client();

        try {
            $response = $client->post($path->apiPath($operation), [
                'versions' => [$request->version()],
            ]);
        } catch (Throwable) {
            throw new RuntimeException("Vault static secret {$failureAction} failed.");
        }
        if (! $response->successful()) {
            throw new RuntimeException("Vault static secret {$failureAction} failed.");
        }

        return $this->result($path, $request->version(), $fingerprint);
    }

    private function fingerprint(VaultKvV2Path $path): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('Static secret fingerprint key is unavailable.');
        }

        return hash_hmac('sha256', $path->opaqueReference(), $key);
    }

    private function result(VaultKvV2Path $path, int $version, string $fingerprint): StoredSecretVersion
    {
        return new StoredSecretVersion(
            opaqueReference: $path->opaqueReference(),
            version: $version,
            fingerprint: $fingerprint,
        );
    }

    private function path(#[\SensitiveParameter] string $opaqueReference): VaultKvV2Path
    {
        return VaultKvV2Path::fromReference(
            $opaqueReference,
            (string) config('monitoring.credentials.vault.kv_v2_mount'),
            (string) config('monitoring.credentials.vault.provider_secret_prefix'),
        );
    }

    private function client(): PendingRequest
    {
        [$url, $token, $namespace] = $this->configuration();
        $headers = [
            'X-Vault-Request' => 'true',
            'X-Vault-Token' => $token,
        ];
        if ($namespace !== '') {
            $headers['X-Vault-Namespace'] = $namespace;
        }

        return Http::baseUrl($url)
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->connectTimeout(max(1, min(10, (int) config('monitoring.credentials.vault.connect_timeout_seconds', 3))))
            ->timeout(max(1, min(30, (int) config('monitoring.credentials.vault.response_timeout_seconds', 10))))
            ->withoutRedirecting();
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function configuration(): array
    {
        $url = rtrim((string) config('monitoring.credentials.vault.url'), '/');
        $token = (string) config('monitoring.credentials.vault.token');
        $namespace = trim((string) config('monitoring.credentials.vault.namespace'));
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || $token === ''
            || strlen($token) > 16_384
            || preg_match('/\s|[\x00-\x1f\x7f]/', $token) === 1
            || strlen($namespace) > 256
            || ($namespace !== ''
                && preg_match('#^(?:[A-Za-z0-9][A-Za-z0-9._-]*)(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*$#', $namespace) !== 1)) {
            throw new RuntimeException('Vault static secret store is not securely configured.');
        }

        return [$url, $token, $namespace];
    }

    /** @param array<string, scalar|null> $material */
    private function destroyMaterial(#[\SensitiveParameter] array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($value);
                } else {
                    $value = str_repeat("\0", strlen($value));
                }
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }
}
