<?php

namespace Tests\Unit\Medication;

use App\Services\Medication\BackupDelivery\BackupScheduleClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BackupScheduleClockTest extends TestCase
{
    #[DataProvider('scheduledDays')]
    public function test_nz_schedule_resolves_daytime_dst_gap_and_repeated_minute(string $day, string $time, string $utc, bool $adjusted, bool $ambiguous): void
    {
        $result = (new BackupScheduleClock)->resolve($day, $time);
        $this->assertSame($utc, $result['instant']->format('Y-m-d H:i'));
        $this->assertSame($adjusted, $result['adjusted']);
        $this->assertSame($ambiguous, $result['ambiguous']);
        $this->assertSame($day, $result['instant']->setTimezone('Pacific/Auckland')->toDateString());
    }

    public static function scheduledDays(): array
    {
        return [
            'NZDT' => ['2026-01-20', '07:30', '2026-01-19 18:30', false, false],
            'NZST' => ['2026-07-20', '07:30', '2026-07-19 19:30', false, false],
            'spring gap next valid minute' => ['2026-09-27', '02:30', '2026-09-26 14:00', true, false],
            'autumn fold first occurrence' => ['2026-04-05', '02:30', '2026-04-04 13:30', false, true],
        ];
    }

    public function test_invalid_local_day_is_rejected_instead_of_normalized(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BackupScheduleClock)->resolve('2026-02-30', '07:30');
    }
}
