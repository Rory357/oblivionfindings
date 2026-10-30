<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CctvLiveController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CctvSourceController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\CollectorMediaController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\HikvisionDiscoveryController;
use App\Domain\SecurityDevices\Cctv\Http\Controllers\HikvisionSourceController;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CctvPublicErrorTest extends TestCase
{
    public static function boundaries(): array
    {
        $cases = [];
        foreach ([CctvLiveController::class, CollectorMediaController::class, HikvisionSourceController::class, HikvisionDiscoveryController::class, CctvSourceController::class] as $controller) {
            foreach ([[404, 'unavailable', 'unavailable'], [409, 'active_source_exists', 'active_source_exists'], [409, 'context_changed', 'context_changed'], [503, 'temporarily_unavailable', 'temporarily_unavailable'], [409, 'synthetic-secret https://private.example.test stack-marker', 'context_changed'], [503, 'synthetic-secret https://private.example.test stack-marker', 'temporarily_unavailable'], [0, 'synthetic-secret https://private.example.test stack-marker', 'temporarily_unavailable']] as [$status, $text, $public]) {
                $cases[] = [$controller, $status, $text, $public];
            }
        }

        return $cases;
    }

    #[DataProvider('boundaries')]
    public function test_each_http_adapter_keeps_only_closed_public_codes_including_with_debug_environment(string $controller, int $status, string $text, string $public): void
    {
        $container = Container::getInstance();
        $factoryKey = \Illuminate\Contracts\Routing\ResponseFactory::class;
        $previousFactory = $container->bound($factoryKey) ? $container->make($factoryKey) : null;
        $container->instance($factoryKey, new ResponseFactory($this->createStub(Factory::class), $this->createStub(Redirector::class)));
        $before = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';
        try {
            $action = static function () use ($status, $text): never {
                throw ($status ? new HttpException($status, $text) : new \RuntimeException($text));
            };
            $method = new \ReflectionMethod($controller, $controller === CollectorMediaController::class ? 'handle' : 'json');
            $arguments = [$action];
            if ($controller === CollectorMediaController::class) {
                $request = Request::create('/api/monitoring/collectors/media/work');
                $request->attributes->set('monitoring_collector', new MonitoringCollector);
                $arguments = [$request, $action];
            }
            $response = $method->invoke(new $controller, ...$arguments);
            self::assertSame($status ?: 503, $response->getStatusCode());
            self::assertSame(['message' => $public], json_decode($response->getContent(), true, 8, JSON_THROW_ON_ERROR));
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            foreach (['synthetic-secret', 'private.example.test', 'stack-marker', 'trace'] as $marker) {
                self::assertStringNotContainsString($marker, $response->getContent());
            }
        } finally {
            if ($previousFactory) {
                $container->instance($factoryKey, $previousFactory);
            } else {
                $container->forgetInstance($factoryKey);
            }
            if ($before === null) {
                unset($_ENV['APP_DEBUG']);
            } else {
                $_ENV['APP_DEBUG'] = $before;
            }
        }
    }
}
