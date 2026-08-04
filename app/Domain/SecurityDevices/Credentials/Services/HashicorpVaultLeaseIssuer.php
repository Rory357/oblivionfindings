<?php

namespace App\Domain\SecurityDevices\Credentials\Services;

use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerLeaseIssuer;
use App\Domain\SecurityDevices\Credentials\Contracts\SecretManagerRestoreProbe;
use App\Domain\SecurityDevices\Credentials\Data\SecretLeaseRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class HashicorpVaultLeaseIssuer implements SecretManagerLeaseIssuer, SecretManagerRestoreProbe
{
    public function healthy(): bool
    {
        try {
            // HashiCorp Vault documents HEAD /v1/sys/health as a read-only
            // health check. The flags make healthy HA standbys return 2xx;
            // sealed, uninitialised, removed, or unhealthy nodes still fail.
            // It must be called from the root namespace, so no token or
            // namespace header is sent by this health-only request.
            [$url] = $this->configuration();

            return $this->baseClient($url)->head('/v1/sys/health', [
                'standbyok' => 'true',
                'perfstandbyok' => 'true',
            ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function issue(SecretLeaseRequest $request): CredentialLease
    {
        $response = $this->client()->get('/v1/'.$this->path($request->secretManagerReference()));
        if (! $response->successful()) {
            throw new RuntimeException('Vault did not issue a credential lease.');
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Vault returned an invalid credential lease.');
        }
        $material = data_get($payload, 'data.data');
        if (! is_array($material)) {
            $material = $payload['data'] ?? null;
        }
        $material = $this->material($material);
        $duration = filter_var($payload['lease_duration'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $expiresAt = $request->expiresAt;
        if ($duration !== false) {
            $expiresAt = min($expiresAt, CarbonImmutable::now('UTC')->addSeconds((int) $duration));
        }
        $leaseId = is_string($payload['lease_id'] ?? null) && trim($payload['lease_id']) !== ''
            ? trim($payload['lease_id'])
            : 'local-vault:'.Str::orderedUuid();
        unset($payload);

        return new CredentialLease($leaseId, $expiresAt, $material);
    }

    public function revoke(#[\SensitiveParameter] string $leaseId): void
    {
        if ($leaseId === '' || str_starts_with($leaseId, 'local-vault:')) {
            return;
        }
        $response = $this->client()->post('/v1/sys/leases/revoke', ['lease_id' => $leaseId]);
        if (! $response->successful()) {
            throw new RuntimeException('Vault lease revocation failed.');
        }
    }

    private function client(): PendingRequest
    {
        [$url, $token, $namespace] = $this->configuration();
        $headers = ['X-Vault-Token' => $token];
        if ($namespace !== '') {
            $headers['X-Vault-Namespace'] = $namespace;
        }

        return $this->baseClient($url)->withHeaders($headers);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function configuration(): array
    {
        $url = rtrim((string) config('monitoring.credentials.vault.url'), '/');
        $token = (string) config('monitoring.credentials.vault.token');
        if ($url === '' || $token === '' || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
            || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Vault is not securely configured.');
        }
        $namespace = trim((string) config('monitoring.credentials.vault.namespace'));

        return [$url, $token, $namespace];
    }

    private function baseClient(string $url): PendingRequest
    {
        return Http::baseUrl($url)
            ->acceptJson()
            ->connectTimeout(max(1, min(10, (int) config('monitoring.credentials.vault.connect_timeout_seconds', 3))))
            ->timeout(max(1, min(30, (int) config('monitoring.credentials.vault.response_timeout_seconds', 10))))
            ->withoutRedirecting();
    }

    private function path(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || strlen($path) > 512
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/@-]*(?:/[A-Za-z0-9._@-]+)*$#', $path) !== 1
            || str_contains($path, '..')) {
            throw new RuntimeException('Vault secret path is invalid.');
        }

        return $path;
    }

    /** @return array<string, scalar|null> */
    private function material(#[\SensitiveParameter] mixed $material): array
    {
        if (! is_array($material) || $material === [] || count($material) > 64) {
            throw new RuntimeException('Vault credential material is invalid.');
        }
        $safe = [];
        $bytes = 0;
        foreach ($material as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/', $key) !== 1
                || (! is_scalar($value) && $value !== null)) {
                throw new RuntimeException('Vault credential material is invalid.');
            }
            $bytes += is_string($value) ? strlen($value) : 16;
            if ($bytes > 1_048_576) {
                throw new RuntimeException('Vault credential material is too large.');
            }
            $safe[$key] = $value;
        }

        return $safe;
    }
}
