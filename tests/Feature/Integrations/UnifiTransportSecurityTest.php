<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\Adapters\UnifiAdapter;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Services\Integration\UnifiTransportSecurity;
use App\Support\SafeOperationalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UnifiTransportSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    private IntegrationProviderConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('integration-capabilities.unifi.ca_bundle');
        $this->connection = IntegrationProviderConnection::query()->create([
            'provider' => 'unifi',
            'secret_encrypted' => Crypt::encryptString('unifi-transport-secret'),
            'secret_last4' => 'cret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_system_ca_verification_is_the_default_and_trusted_api_behavior_is_preserved(): void
    {
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => []], 200),
        ]);

        $this->assertTrue(app(UnifiTransportSecurity::class)->verificationOption());
        $this->assertTrue(app(UnifiAdapter::class)->testConnection($this->connection));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.ui.com/v1/sites'
            && $request->hasHeader('X-API-Key', 'unifi-transport-secret'));
    }

    public function test_valid_private_ca_bundle_is_selected_and_trusted_api_behavior_is_preserved(): void
    {
        $bundle = $this->temporaryFile($this->validCaCertificate());
        config()->set('integration-capabilities.unifi.ca_bundle', $bundle);
        Http::fake([
            'https://api.ui.com/v1/sites' => Http::response(['data' => []], 200),
        ]);

        $this->assertSame(realpath($bundle), app(UnifiTransportSecurity::class)->verificationOption());
        $this->assertTrue(app(UnifiAdapter::class)->testConnection($this->connection));
        Http::assertSentCount(1);
    }

    #[DataProvider('tlsHandshakeFailureProvider')]
    public function test_untrusted_and_hostname_mismatched_endpoints_fail_with_redacted_retry_exceptions(string $failure): void
    {
        Log::spy();
        Http::fake(Http::failedConnection($failure));

        try {
            app(UnifiAdapter::class)->discoverSites($this->connection);
            $this->fail('An untrusted UniFi endpoint was accepted.');
        } catch (IntegrationDiscoveryException $exception) {
            $this->assertSame(SafeOperationalData::failureSummary(), $exception->getMessage());
            $this->assertSame('connection_failure', $exception->failureCategory());
            $this->assertStringNotContainsString('unifi-transport-secret', $exception->getMessage());
            $this->assertStringNotContainsString('private-controller.example.test', $exception->getMessage());
            $this->assertStringNotContainsString('CERTIFICATE-SENTINEL', $exception->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'UniFi discoverSites failed'
                    && ($context['error_category'] ?? null) === 'connection_failure'
                    && ! str_contains($encoded, 'unifi-transport-secret')
                    && ! str_contains($encoded, 'private-controller.example.test')
                    && ! str_contains($encoded, 'CERTIFICATE-SENTINEL');
            })
            ->once();
    }

    /** @return array<string, array{string}> */
    public static function tlsHandshakeFailureProvider(): array
    {
        return [
            'untrusted certificate' => [
                'SSL certificate problem: unable to get local issuer certificate CERTIFICATE-SENTINEL Bearer unifi-transport-secret',
            ],
            'hostname mismatch' => [
                'SSL: no alternative certificate subject name matches target host name private-controller.example.test?token=unifi-transport-secret',
            ],
        ];
    }

    public function test_missing_ca_bundle_path_fails_before_any_request_and_redacts_the_path(): void
    {
        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-unifi-ca-'.bin2hex(random_bytes(8)).'.pem';
        config()->set('integration-capabilities.unifi.ca_bundle', $missing);
        Log::spy();
        Http::preventStrayRequests();

        $this->assertFalse(app(UnifiAdapter::class)->testConnection($this->connection));
        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'UniFi testConnection failed'
                && ($context['error_category'] ?? null) === 'transport_security_failure'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), basename($missing)))
            ->once();
    }

    public function test_unreadable_shape_or_invalid_ca_contents_fail_before_any_request_without_leaking_contents(): void
    {
        $invalid = $this->temporaryFile(<<<'PEM'
-----BEGIN PRIVATE KEY-----
PRIVATE-KEY-SENTINEL
-----END PRIVATE KEY-----
PEM);
        config()->set('integration-capabilities.unifi.ca_bundle', $invalid);
        Log::spy();
        Http::preventStrayRequests();

        $this->assertFalse(app(UnifiAdapter::class)->testConnection($this->connection));
        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($invalid): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'UniFi testConnection failed'
                    && ($context['error_category'] ?? null) === 'transport_security_failure'
                    && ! str_contains($encoded, 'PRIVATE-KEY-SENTINEL')
                    && ! str_contains($encoded, basename($invalid));
            })
            ->once();

        config()->set('integration-capabilities.unifi.ca_bundle', dirname($invalid));
        $this->assertFalse(app(UnifiAdapter::class)->testConnection($this->connection));
        Http::assertNothingSent();
    }

    public function test_invalid_ca_contents_expose_only_a_bounded_exception_to_retry_state(): void
    {
        $invalid = $this->temporaryFile(<<<'PEM'
-----BEGIN CERTIFICATE-----
CERTIFICATE-CONTENTS-SENTINEL
-----END CERTIFICATE-----
PEM);
        config()->set('integration-capabilities.unifi.ca_bundle', $invalid);
        Http::preventStrayRequests();

        try {
            app(UnifiAdapter::class)->discoverSites($this->connection);
            $this->fail('An invalid UniFi CA bundle was accepted.');
        } catch (IntegrationDiscoveryException $exception) {
            $this->assertSame(SafeOperationalData::failureSummary(), $exception->getMessage());
            $this->assertSame('transport_security_failure', $exception->failureCategory());
            $this->assertStringNotContainsString('CERTIFICATE-CONTENTS-SENTINEL', $exception->getMessage());
            $this->assertStringNotContainsString(basename($invalid), $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'unifi-ca-');
        if ($file === false || file_put_contents($file, $contents) === false) {
            throw new \RuntimeException('Unable to create the UniFi CA test fixture.');
        }
        $this->temporaryFiles[] = $file;

        return $file;
    }

    private function validCaCertificate(): string
    {
        return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICszCCAZugAwIBAgIJAL8i2684yECtMA0GCSqGSIb3DQEBCwUAMBkxFzAVBgNVBAMTDmNvbGxl
Y3Rvci50ZXN0MB4XDTI2MDgwMzIxNDM0N1oXDTI3MDgwNDIxNDM0N1owGTEXMBUGA1UEAxMOY29s
bGVjdG9yLnRlc3QwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQC4P9n46h+Pm8CD2zbw
+xZ82kxQ4qCjYv+1OhOIkO+sgppxnXw9RvQAQW7htP8p3LgcDcSMxiGfwGF6LNZ20LjWAm/GPdAg
53mcKKPGw7s5baMz3rYLcn1NNN4tuHcKTGVwMEO/a5nv5H4g6MoYnPmoCWLoeFbvTSuNPKuTFZAB
0HNhyyGJIObwMzKFs2qoUnYOYj48BUz9FDoSoVRw8kGVVwWPo/pupMbed4iAJkS5mTc3opp8d+MN
/2V88fHkHBJPCwgkWYq4fLBBi8lXHJAlAUjJx0BbT7+Uyw+G1ARD3RkeY0HFWqwkLM7P8M2TQvR2
rVyVuH33el+8IqNUtHDdAgMBAAEwDQYJKoZIhvcNAQELBQADggEBAB/nMY5Dn39zion44L0KmJqz
rWO97Np1Jk67sXM275m7OW9jKyemL9rDPSOlvut1cEAlOyCVPWLuSK4Fc+a+bkWHr4oIW7gSGImY
BIokAkRXxJ19UzQYR53oYycdqBqmGv5QvIUCe2z0z1L8APd/6jP3HQ6kE7IrsNCaoNxvaeN8Z8zq
TLEkCR0JF+SAyTw+vqtf+ghDUQMc/FUCnWIBfioExMHoH9eBaTCIBV0JptOQ9DzJQxtkMB10kbxd
kuyOWJHCq2eL+Mm/qF974WVccuRQd3z6TiV1Tt87sWyAZcKttHGZt+DWopzqcn5f1+hUFq/M7a4b
yQIzjb5ytY4xkZg=
-----END CERTIFICATE-----
PEM;
    }
}
