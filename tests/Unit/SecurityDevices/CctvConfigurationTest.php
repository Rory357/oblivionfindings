<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Services\CctvSessions;
use Carbon\CarbonImmutable;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CctvConfigurationTest extends TestCase
{
    private function configuration(array $environment): array
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        $original = $property->getValue();
        $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        foreach ($environment as $key => $value) {
            $repository->set($key, $value);
        }
        $property->setValue(null, $repository);
        try {
            return require dirname(__DIR__, 3).'/config/cctv.php';
        } finally {
            $property->setValue(null, $original);
        }
    }

    private function runtime(array $configuration, string $method): mixed
    {
        $previous = Container::getInstance();
        $container = new Application;
        $container->instance('config', new Repository(['cctv' => $configuration, 'app' => ['key' => 'isolated-test-key']]));
        Container::setInstance($container);
        try {
            $arguments = $method === 'turn' ? ['11111111-1111-4111-8111-111111111111', CarbonImmutable::parse('2026-10-08T00:05:00Z')] : [];

            return (new ReflectionMethod(CctvSessions::class, $method))->invokeArgs(new CctvSessions, $arguments);
        } finally {
            Container::setInstance($previous);
        }
    }

    private function environment(): array
    {
        return ['CCTV_LIVE_ENABLED' => 'true', 'CCTV_LIVE_MAX_SESSION_SECONDS' => '900', 'CCTV_TURN_URLS' => ' turns:relay.example.test:5349?transport=tcp, ,turn:relay.example.test:3478?transport=udp ', 'CCTV_TURN_REST_SECRET' => str_repeat('isolated-secret-', 4), 'CCTV_TURN_RELAY_CIDRS' => ' 192.0.2.0/24, ,2001:db8::/120 ', 'CCTV_TURN_RELAY_PORT_MIN' => '39000', 'CCTV_TURN_RELAY_PORT_MAX' => '41000'];
    }

    public function test_default_deployment_remains_disabled_without_relay_credentials(): void
    {
        $config = $this->configuration([]);
        self::assertFalse($config['enabled']);
        self::assertSame(300, $this->runtime($config, 'duration'));
        self::assertSame(3600, $config['reference_max_window_seconds']);
        self::assertSame(7200, $this->configuration(['CCTV_REFERENCE_MAX_WINDOW_SECONDS' => '7200'])['reference_max_window_seconds']);
        self::assertSame([], $config['relay_urls']);
        self::assertSame([], $config['relay_cidrs']);
        self::assertNull($config['relay_secret']);
        try {
            $this->runtime($config, 'turn');
            self::fail('Unconfigured relay accepted.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertSame('temporarily_unavailable', $exception->getMessage());
        }
    }

    public function test_actual_environment_configuration_drives_bounded_runtime_relay_credentials(): void
    {
        $config = $this->configuration($this->environment());
        self::assertTrue($config['enabled']);
        self::assertSame(900, $this->runtime($config, 'duration'));
        $turn = $this->runtime($config, 'turn');
        self::assertSame(['turns:relay.example.test:5349?transport=tcp', 'turn:relay.example.test:3478?transport=udp'], $turn['urls']);
        self::assertSame(['192.0.2.0/24', '2001:db8::/120'], $turn['relay_cidrs']);
        self::assertSame(39000, $turn['relay_port_min']);
        self::assertSame(41000, $turn['relay_port_max']);
        self::assertSame('2026-10-08T00:05:00Z', $turn['expires_at']);
        self::assertSame(base64_encode(hash_hmac('sha1', $turn['username'], $config['relay_secret'], true)), $turn['credential']);
        self::assertArrayNotHasKey('relay_secret', $turn);
        self::assertStringNotContainsString($config['relay_secret'], json_encode($turn, JSON_THROW_ON_ERROR));
        foreach (['1' => 60, '7200' => 3600] as $duration => $expected) {
            self::assertSame($expected, $this->runtime($this->configuration(array_replace($this->environment(), ['CCTV_LIVE_MAX_SESSION_SECONDS' => (string) $duration])), 'duration'));
        }
    }

    public function test_environment_does_not_bypass_existing_relay_destination_and_secret_validation(): void
    {
        foreach ([['CCTV_TURN_URLS' => 'https://relay.example.test'], ['CCTV_TURN_URLS' => 'turns:relay.example.test:5349?transport=udp'], ['CCTV_TURN_REST_SECRET' => 'short'], ['CCTV_TURN_RELAY_CIDRS' => '0.0.0.0/0'], ['CCTV_TURN_RELAY_CIDRS' => ''], ['CCTV_TURN_RELAY_PORT_MIN' => '39000junk'], ['CCTV_TURN_RELAY_PORT_MIN' => '22'], ['CCTV_TURN_RELAY_PORT_MAX' => '38000'], ['CCTV_TURN_RELAY_PORT_MAX' => '65536']] as $change) {
            try {
                $this->runtime($this->configuration(array_replace($this->environment(), $change)), 'turn');
                self::fail('Unsafe deployment configuration accepted.');
            } catch (HttpExceptionInterface $exception) {
                self::assertSame(503, $exception->getStatusCode());
                self::assertSame('temporarily_unavailable', $exception->getMessage());
            }
        }
    }
}
