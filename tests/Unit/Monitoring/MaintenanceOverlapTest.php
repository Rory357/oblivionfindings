<?php

namespace Tests\Unit\Monitoring;

use App\Domain\Monitoring\Services\MaintenanceOverlap;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MaintenanceOverlapTest extends TestCase
{
    public function test_adjacent_windows_and_disjoint_interleaved_series_do_not_overlap(): void
    {
        $engine = new MaintenanceOverlap;
        $daily = $this->spec('2026-09-26T09:00:00+12:00', '2026-09-26T10:00:00+12:00', 'daily', '2026-10-03T10:00:00+13:00');
        self::assertNull($engine->firstOverlap($daily, $this->spec('2026-09-27T10:00:00+13:00', '2026-09-27T11:00:00+13:00')));
        self::assertNull($engine->firstOverlap($daily, $this->spec('2026-09-26T11:00:00+12:00', '2026-09-26T12:00:00+12:00', 'daily', '2026-10-03T12:00:00+13:00')));
        self::assertNull($engine->firstOverlap($this->spec('2026-10-01T09:00Z', '2026-10-01T10:00Z'), $this->spec('2026-09-30T08:00Z', '2026-09-30T09:00Z')));
    }

    public function test_later_recurrence_overlap_uses_actual_utc_offsets_and_exclusive_series_end(): void
    {
        $daily = $this->spec('2026-09-26T09:00:00+12:00', '2026-09-26T10:00:00+12:00', 'daily', '2026-10-03T09:30:00+13:00');
        $overlap = (new MaintenanceOverlap)->firstOverlap($daily, $this->spec('2026-10-02T09:30:00+13:00', '2026-10-02T10:30:00+13:00'));
        self::assertSame('2026-10-01T20:30:00+00:00', $overlap['starts_at']->toIso8601String());
        self::assertSame('2026-10-01T21:00:00+00:00', $overlap['ends_at']->toIso8601String());
        self::assertNull((new MaintenanceOverlap)->firstOverlap($daily, $this->spec('2026-10-03T09:30:00+13:00', '2026-10-03T10:30:00+13:00')));
    }

    public function test_skipped_gap_does_not_conflict_with_a_later_real_window(): void
    {
        $a = [...$this->spec('2026-09-26T02:15:00+12:00', '2026-09-26T03:45:00+12:00', 'daily', '2026-09-29T04:00:00+13:00'), 'dst_gap' => 'skip'];
        self::assertNull((new MaintenanceOverlap)->firstOverlap($a, $this->spec('2026-09-27T03:15:00+13:00', '2026-09-27T03:30:00+13:00')));
        self::assertNotNull((new MaintenanceOverlap)->firstOverlap($a, $this->spec('2026-09-28T03:15:00+13:00', '2026-09-28T03:30:00+13:00')));
    }

    public function test_selected_repeated_hour_is_compared_by_instant(): void
    {
        $a = [...$this->spec('2026-04-04T02:15:00+13:00', '2026-04-04T02:45:00+13:00', 'daily', '2026-04-07T03:00:00+12:00'), 'dst_overlap' => 'later'];
        self::assertNull((new MaintenanceOverlap)->firstOverlap($a, $this->spec('2026-04-05T02:20:00+13:00', '2026-04-05T02:30:00+13:00')));
        self::assertNotNull((new MaintenanceOverlap)->firstOverlap($a, $this->spec('2026-04-05T02:20:00+12:00', '2026-04-05T02:30:00+12:00')));
    }

    public function test_weekly_overnight_windows_cross_the_date_boundary(): void
    {
        $a = $this->spec('2026-10-01T23:30:00+13:00', '2026-10-02T00:30:00+13:00', 'weekly', '2026-10-30T01:00:00+13:00');
        self::assertNotNull((new MaintenanceOverlap)->firstOverlap($a, $this->spec('2026-10-09T00:15:00+13:00', '2026-10-09T00:45:00+13:00')));
        self::assertNull((new MaintenanceOverlap)->firstOverlap($a, $this->spec('2026-10-09T00:30:00+13:00', '2026-10-09T00:45:00+13:00')));
    }

    public function test_unbounded_legacy_recurrence_cannot_be_declared_disjoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MaintenanceOverlap)->firstOverlap($this->spec('2026-10-01T09:00Z', '2026-10-01T10:00Z', 'daily'), $this->spec('2026-10-02T09:00Z', '2026-10-02T10:00Z'));
    }

    private function spec(string $start, string $end, ?string $recurrence = null, ?string $until = null): array
    {
        return ['starts_at' => CarbonImmutable::parse($start)->utc(), 'ends_at' => CarbonImmutable::parse($end)->utc(), 'recurrence' => $recurrence, 'recurrence_until' => $until === null ? null : CarbonImmutable::parse($until)->utc(), 'timezone' => 'Pacific/Auckland', 'dst_overlap' => 'reject', 'dst_gap' => 'reject'];
    }
}
