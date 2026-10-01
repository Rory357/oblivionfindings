<?php

namespace Tests\Unit\Medication\DoseSlots;

use App\Services\Medication\DoseSlots\DoseWindow;
use App\Services\Medication\DoseSlots\DoseWindowOverride;
use App\Services\Medication\DoseSlots\DoseWindowResolver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The one dose window (P01 foundation C2): config 30/60 today, P11's Dose
 * timing later through the same forOrder() call and the time-critical hook.
 */
class DoseWindowResolverTest extends TestCase
{
    public function test_the_default_window_comes_from_config(): void
    {
        $window = app(DoseWindowResolver::class)->forOrder(1);

        $this->assertSame(30, $window->beforeMinutes);
        $this->assertSame(60, $window->afterMinutes);
        $this->assertSame(DoseWindow::SOURCE_CONFIG, $window->source);

        config([
            'medications.mar.window_before_minutes' => 15,
            'medications.mar.window_after_minutes' => 45,
        ]);
        $changed = app(DoseWindowResolver::class)->forOrder(1);
        $this->assertSame([15, 45], [$changed->beforeMinutes, $changed->afterMinutes]);
    }

    public function test_a_time_critical_override_sets_the_late_time_for_its_order_only(): void
    {
        $insulinOrder = new class implements DoseWindowOverride
        {
            public function lateMinutesForOrder(int $orderId): ?int
            {
                return $orderId === 9 ? 15 : null;
            }
        };
        $resolver = new DoseWindowResolver(30, 60, [$insulinOrder]);

        $critical = $resolver->forOrder(9);
        $this->assertSame([30, 15], [$critical->beforeMinutes, $critical->afterMinutes]);
        $this->assertSame(DoseWindow::SOURCE_TIME_CRITICAL, $critical->source);

        $ordinary = $resolver->forOrder(10);
        $this->assertSame([30, 60], [$ordinary->beforeMinutes, $ordinary->afterMinutes]);
        $this->assertSame(DoseWindow::SOURCE_CONFIG, $ordinary->source);
    }

    public function test_the_window_includes_both_ends(): void
    {
        $due = CarbonImmutable::parse('2026-06-15 08:00', 'Pacific/Auckland')->utc();
        $window = new DoseWindow(30, 60);

        $this->assertTrue($window->opensAt($due)->equalTo($due->subMinutes(30)));
        $this->assertTrue($window->closesAt($due)->equalTo($due->addMinutes(60)));
        $this->assertFalse($window->contains($due, $due->subMinutes(31)));
        $this->assertTrue($window->contains($due, $due->subMinutes(30)));
        $this->assertTrue($window->contains($due, $due));
        $this->assertTrue($window->contains($due, $due->addMinutes(60)));
        $this->assertFalse($window->contains($due, $due->addMinutes(61)));
    }

    public function test_a_negative_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoseWindow(-1, 60);
    }
}
