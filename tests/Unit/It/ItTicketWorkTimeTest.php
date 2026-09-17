<?php

namespace Tests\Unit\It;

use App\Domain\It\Services\ItTicketWorkTime;
use App\Support\It\BusinessHours;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class ItTicketWorkTimeTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['app' => ['worker_timezone' => 'Pacific/Auckland']]));
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousContainer);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_browser_and_serialized_fractional_timestamps_keep_actual_minutes(): void
    {
        $period = (new ItTicketWorkTime)->period(['starts_at' => '2026-07-01T00:00:00.000Z', 'ends_at' => '2026-07-01T01:00:00.000000Z', 'break_minutes' => 10]);
        self::assertSame(50, $period['minutes']);
    }

    public function test_split_uses_the_real_boundary_and_preserves_breaks_and_net_time(): void
    {
        $service = new ItTicketWorkTime;
        $result = $service->split(['starts_at' => '2026-07-01T16:30:30+12:00', 'ends_at' => '2026-07-01T17:30:00+12:00', 'break_minutes' => 11], BusinessHours::nzDefault());
        self::assertCount(2, $result['periods']);
        self::assertSame('2026-07-01T05:00:00.000000Z', $result['periods'][0]['ends_at']);
        self::assertSame([false, true], array_column($result['periods'], 'after_hours'));
        self::assertSame(11, array_sum(array_column($result['periods'], 'break_minutes')));
        self::assertSame(48, array_sum(array_map(fn ($row) => $service->period($row)['minutes'], $result['periods'])));
    }

    public function test_split_does_not_silently_lose_partial_minutes(): void
    {
        $this->expectException(ValidationException::class);
        (new ItTicketWorkTime)->split(['starts_at' => '2026-07-01T16:30:30+12:00', 'ends_at' => '2026-07-01T17:30:30+12:00'], BusinessHours::nzDefault());
    }

    public function test_split_rejects_breaks_that_cannot_leave_positive_entries(): void
    {
        $this->expectException(ValidationException::class);
        (new ItTicketWorkTime)->split(['starts_at' => '2026-07-01T16:30+12:00', 'ends_at' => '2026-07-01T17:30+12:00', 'break_minutes' => 59], BusinessHours::nzDefault());
    }
}
