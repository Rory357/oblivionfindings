<?php

namespace Tests\Unit\Monitoring;

use App\Domain\Monitoring\Services\MaintenanceSchedule;
use App\Support\ZonedLocalTime;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MaintenanceScheduleTest extends TestCase
{
    public function test_local_input_uses_reviewed_timezone_and_preserves_precise_explicit_instants(): void
    {
        $clock = new ZonedLocalTime;
        self::assertSame('2026-09-25T21:00:00+00:00', $clock->parse('2026-09-26T09:00', 'Pacific/Auckland')->toIso8601String());
        self::assertSame('2026-09-26T09:00:00+00:00', $clock->parse('2026-09-26T09:00Z', 'Pacific/Auckland')->toIso8601String());
        self::assertSame('123456', $clock->parse('2026-09-26T09:00:01.123456+12:00', 'Pacific/Auckland')->format('u'));
        self::assertSame('2026-09-26T09:00:00+00:00', $clock->parse('2026-09-26T09:00', 'UTC')->toIso8601String());
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_or_unresolved_clock_input_is_denied(string $value, string $zone, string $overlap, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        (new ZonedLocalTime)->parse($value, $zone, $overlap);
    }

    public static function invalidInputs(): array
    {
        return [
            ['2026-02-30T09:00', 'Pacific/Auckland', 'reject', 'invalid_local_time'],
            ['tomorrow', 'Pacific/Auckland', 'reject', 'invalid_local_time'],
            ['2026-09-26', 'Pacific/Auckland', 'reject', 'invalid_local_time'],
            ['2026-09-27T02:15', 'Pacific/Auckland', 'reject', 'nonexistent_local_time'],
            ['2026-04-05T02:15', 'Pacific/Auckland', 'reject', 'ambiguous_local_time'],
            ['2026-04-05T02:15+11:00', 'Pacific/Auckland', 'reject', 'invalid_local_offset'],
            ['2026-09-26T09:00Z', 'Unapproved/Invalid', 'reject', 'invalid_timezone'],
            ['2026-09-26T09:00', 'Pacific/Auckland', 'automatic', 'invalid_overlap_policy'],
        ];
    }

    public function test_repeated_local_hour_requires_first_or_second_selection(): void
    {
        $clock = new ZonedLocalTime;
        self::assertCount(2, $clock->candidates('2026-04-05T02:15', 'Pacific/Auckland'));
        self::assertSame('2026-04-04T13:15:00+00:00', $clock->parse('2026-04-05T02:15', 'Pacific/Auckland', 'earlier')->toIso8601String());
        self::assertSame('2026-04-04T14:15:00+00:00', $clock->parse('2026-04-05T02:15', 'Pacific/Auckland', 'later')->toIso8601String());
        self::assertSame($clock->parse('2026-04-05T02:15', 'Pacific/Auckland', 'later')->format('U.u'), $clock->parse('2026-04-05T02:15+12:00', 'Pacific/Auckland')->format('U.u'));
    }

    public function test_daily_and_weekly_schedules_keep_local_time_and_exclusive_boundaries(): void
    {
        $engine = new MaintenanceSchedule;
        $daily = $this->spec('2026-09-26T09:00:00+12:00', '2026-09-26T10:00:00+12:00', '2026-10-03T10:00:00+13:00');
        self::assertTrue($engine->contains($daily, $this->at('2026-09-27T09:00:00+13:00')));
        self::assertTrue($engine->contains($daily, $this->at('2026-09-27T09:59:59+13:00')));
        self::assertFalse($engine->contains($daily, $this->at('2026-09-27T10:00:00+13:00')));
        self::assertFalse($engine->contains($daily, $this->at('2026-09-27T08:59:59+13:00')));
        $weekly = [...$daily, 'recurrence' => 'weekly'];
        self::assertFalse($engine->contains($weekly, $this->at('2026-09-27T09:30:00+13:00')));
        self::assertTrue($engine->contains($weekly, $this->at('2026-10-03T09:30:00+13:00')));
    }

    public function test_nonexistent_recurrence_does_not_move_to_a_later_hour_and_needs_explicit_skip(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-09-26T02:15:00+12:00', '2026-09-26T03:45:00+12:00', '2026-09-29T04:00:00+13:00');
        self::assertFalse($engine->contains($spec, $this->at('2026-09-27T03:30:00+13:00')));
        $this->assertDenied(fn () => $engine->occurrences($spec), 'nonexistent_local_time');
        $spec['dst_gap'] = 'skip';
        $rows = $engine->occurrences($spec);
        self::assertSame('skipped', $rows[1]['state']);
        self::assertNull($rows[1]['starts_at']);
        self::assertSame('nonexistent_local_time', $rows[1]['reason_code']);
        self::assertFalse($engine->contains($spec, $this->at('2026-09-27T03:30:00+13:00')));
        self::assertTrue($engine->contains($spec, $this->at('2026-09-28T02:30:00+13:00')));
    }

    public function test_repeated_recurrence_is_never_chosen_implicitly(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-04-04T02:15:00+13:00', '2026-04-04T02:45:00+13:00', '2026-04-07T03:00:00+12:00');
        $first = $this->at('2026-04-05T02:30:00+13:00');
        $second = $this->at('2026-04-05T02:30:00+12:00');
        self::assertFalse($engine->contains($spec, $first));
        self::assertFalse($engine->contains($spec, $second));
        $this->assertDenied(fn () => $engine->occurrences($spec), 'ambiguous_local_time');
        self::assertTrue($engine->contains([...$spec, 'dst_overlap' => 'earlier'], $first));
        self::assertFalse($engine->contains([...$spec, 'dst_overlap' => 'earlier'], $second));
        self::assertTrue($engine->contains([...$spec, 'dst_overlap' => 'later'], $second));
        self::assertFalse($engine->contains([...$spec, 'dst_overlap' => 'later'], $first));
    }

    public function test_series_end_clips_an_occurrence_and_is_exclusive(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-07-23T09:00:00+12:00', '2026-07-23T10:00:00+12:00', '2026-07-24T09:30:00+12:00');
        self::assertTrue($engine->contains($spec, $this->at('2026-07-24T09:29:59+12:00')));
        self::assertFalse($engine->contains($spec, $this->at('2026-07-24T09:30:00+12:00')));
        $rows = $engine->occurrences($spec);
        self::assertSame($spec['recurrence_until']->format('U.u'), $rows[1]['ends_at']->format('U.u'));
    }

    public function test_first_reviewed_offset_and_overnight_interval_are_preserved(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-04-05T02:15:00+12:00', '2026-04-05T02:45:00+12:00', '2026-04-07T03:00:00+12:00');
        self::assertTrue($engine->contains($spec, $this->at('2026-04-05T02:30:00+12:00')));
        self::assertFalse($engine->contains($spec, $this->at('2026-04-05T02:30:00+13:00')));
        $overnight = $this->spec('2026-09-26T23:30:00+12:00', '2026-09-27T01:30:00+12:00', '2026-09-30T02:00:00+13:00');
        self::assertTrue($engine->contains($overnight, $this->at('2026-09-28T00:30:00+13:00')));
        self::assertFalse($engine->contains($overnight, $this->at('2026-09-28T01:30:00+13:00')));
    }

    public function test_unsafe_recurrence_or_clock_policy_cannot_suppress_and_cannot_preview(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-07-23T09:00:00+12:00', '2026-07-23T10:00:00+12:00', '2028-07-23T10:00:00+12:00');
        self::assertFalse($engine->contains($spec, $this->at('2026-07-24T09:30:00+12:00')));
        $this->assertDenied(fn () => $engine->occurrences($spec), 'invalid_recurrence_limit');
        $spec['recurrence_until'] = $this->at('2026-07-25T10:00:00+12:00');
        $spec['dst_overlap'] = 'automatic';
        self::assertFalse($engine->contains($spec, $this->at('2026-07-24T09:30:00+12:00')));
        $this->assertDenied(fn () => $engine->occurrences($spec), 'invalid_clock_policy');
    }

    public function test_a_gap_after_the_series_cutoff_is_not_a_scheduled_occurrence(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-09-26T02:15:00+12:00', '2026-09-26T03:45:00+12:00', '2026-09-27T01:00:00+12:00');
        self::assertCount(1, $engine->occurrences($spec));
    }

    public function test_recurring_local_duration_cannot_overlap_its_period(): void
    {
        $engine = new MaintenanceSchedule;
        $spec = $this->spec('2026-07-23T09:00:00+12:00', '2026-07-25T10:00:00+12:00', '2026-07-30T10:00:00+12:00');
        self::assertFalse($engine->contains($spec, $this->at('2026-07-24T09:30:00+12:00')));
        $this->assertDenied(fn () => $engine->occurrences($spec), 'invalid_recurring_duration');
    }

    private function spec(string $start, string $end, string $until): array
    {
        return ['starts_at' => $this->at($start), 'ends_at' => $this->at($end), 'recurrence_until' => $this->at($until), 'recurrence' => 'daily', 'timezone' => 'Pacific/Auckland', 'dst_overlap' => 'reject', 'dst_gap' => 'reject'];
    }

    private function at(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->utc();
    }

    private function assertDenied(callable $action, string $reason): void
    {
        try {
            $action();
            self::fail('Expected clock denial.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }
}
