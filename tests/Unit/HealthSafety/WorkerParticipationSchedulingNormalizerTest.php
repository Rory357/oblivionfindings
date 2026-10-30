<?php

namespace Tests\Unit\HealthSafety;

use App\Services\HealthSafety\WorkerParticipationSchedulingNormalizer as Scheduling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure date operands; no models, database or notification transport. */
final class WorkerParticipationSchedulingNormalizerTest extends TestCase
{
    public static function uniqueTimes(): array
    {
        return [
            ['2026-01-15T12:30', null, '2026-01-14T23:30:00Z'],
            ['2026-07-15T12:30', null, '2026-07-15T00:30:00Z'],
            ['2026-01-15T12:30', '+13:00', '2026-01-14T23:30:00Z'],
            ['2026-07-15T12:30', '+12:00', '2026-07-15T00:30:00Z'],
            ['2024-02-29T00:00', '', '2024-02-28T11:00:00Z'],
            ['2026-04-05T01:59', null, '2026-04-04T12:59:00Z'],
            ['2026-04-05T03:00', null, '2026-04-04T15:00:00Z'],
            ['2026-09-27T01:59', null, '2026-09-26T13:59:00Z'],
            ['2026-09-27T03:00', null, '2026-09-26T14:00:00Z'],
            ['1850-01-01T12:30', null, '1850-01-01T00:50:56Z'],
        ];
    }

    #[DataProvider('uniqueTimes')]
    public function test_unique_real_minute_is_normalized_to_utc(string $wall, ?string $offset, string $utc): void
    {
        $this->assertSame(['value' => $utc, 'errors' => []], Scheduling::normalize($wall, Scheduling::TIMEZONE, $offset));
    }

    public static function folds(): array
    {
        return [
            ['2026-04-05T02:00', '+13:00', '2026-04-04T13:00:00Z'],
            ['2026-04-05T02:00', '+12:00', '2026-04-04T14:00:00Z'],
            ['2026-04-05T02:59', '+13:00', '2026-04-04T13:59:00Z'],
            ['2026-04-05T02:59', '+12:00', '2026-04-04T14:59:00Z'],
        ];
    }

    #[DataProvider('folds')]
    public function test_explicit_offset_preserves_each_original_fold_instant(string $wall, string $offset, string $utc): void
    {
        $this->assertSame(['value' => $utc, 'errors' => []], Scheduling::normalize($wall, Scheduling::TIMEZONE, $offset));
    }

    public static function invalidTimes(): array
    {
        return [
            ['2026-09-27T02:00', null, 'scheduled_at'],
            ['2026-09-27T02:59', '+12:00', 'scheduled_at'],
            ['2026-09-27T02:30', '+13:00', 'scheduled_at'],
            ['2026-04-05T02:30', null, 'scheduled_offset'],
            ['2026-04-05T02:30', '', 'scheduled_offset'],
            ['2026-01-15T12:30', '+12:00', 'scheduled_offset'],
            ['2026-07-15T12:30', '+13:00', 'scheduled_offset'],
            ['2026-04-05T02:30', '+11:00', 'scheduled_offset'],
            ['1850-01-01T12:30', '+11:39', 'scheduled_offset'],
            ['2026-01-15T12:30', 13, 'scheduled_offset'],
            ['2026-02-29T12:30', null, 'scheduled_at'],
            ['2026-02-30T12:30', null, 'scheduled_at'],
            ['2026-13-01T12:30', null, 'scheduled_at'],
            ['2026-01-01T24:00', null, 'scheduled_at'],
            ['2026-01-01T12:60', null, 'scheduled_at'],
            ['2026-01-01T12:30:00', null, 'scheduled_at'],
            ['2026-01-01 12:30', null, 'scheduled_at'],
            ['2026-01-01T12:30Z', null, 'scheduled_at'],
            ["2026-01-01T12:30\n", null, 'scheduled_at'],
            ['2026-1-01T12:30', null, 'scheduled_at'],
            ['0999-01-01T12:30', null, 'scheduled_at'],
            [null, null, 'scheduled_at'],
            [[], null, 'scheduled_at'],
        ];
    }

    #[DataProvider('invalidTimes')]
    public function test_invalid_or_unresolved_time_binds_its_exact_form_field(mixed $wall, mixed $offset, string $field): void
    {
        $result = Scheduling::normalize($wall, Scheduling::TIMEZONE, $offset);
        $this->assertNull($result['value']);
        $this->assertSame([$field], array_keys($result['errors']));
    }

    public static function legacyInputs(): array
    {
        return [['2026-10-15T12:30'], ['2026-10-15T12:30:00+12:00'], ['2026-10-15T00:30:00Z'], ['2026-04-05T02:30'], [null]];
    }

    #[DataProvider('legacyInputs')]
    public function test_unmarked_legacy_operand_is_unchanged_not_reinterpreted(mixed $value): void
    {
        $this->assertSame(['value' => $value, 'errors' => []], Scheduling::normalize($value));
    }

    public function test_offset_without_marker_is_not_ignored(): void
    {
        $this->assertSame(['scheduled_offset'], array_keys(Scheduling::normalize('2026-10-15T12:30', null, '+13:00')['errors']));
    }

    public function test_explicit_unknown_zone_does_not_fall_back_to_legacy(): void
    {
        $this->assertSame(['scheduled_at'], array_keys(Scheduling::normalize('2026-10-15T12:30', 'UTC')['errors']));
    }

    public function test_process_timezone_does_not_reinterpret_explicit_configured_nz_time(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Tokyo');
            $this->assertSame(['value' => '2026-01-14T23:30:00Z', 'errors' => []], Scheduling::normalize('2026-01-15T12:30', Scheduling::TIMEZONE));
        } finally { date_default_timezone_set($original); }
    }

    public function test_explicit_configured_zone_and_real_offset_are_used_without_global_config(): void
    {
        $this->assertSame(['value' => '2026-07-15T07:00:00Z', 'errors' => []], Scheduling::normalize('2026-07-15T12:30', 'Asia/Kolkata', '+05:30', 'Asia/Kolkata'));
        $this->assertSame(['scheduled_at'], array_keys(Scheduling::normalize('2026-07-15T12:30', 'Invalid/Zone', null, 'Invalid/Zone')['errors']));
    }

    public static function storageBoundaryTimes(): array
    {
        return [
            ['1000-01-01T00:00', 'Pacific/Auckland', null],
            ['1000-01-01T00:00', '+05:30', '+05:30'],
            ['9999-12-31T23:59', 'America/New_York', null],
            ['9999-12-31T23:59', 'America/New_York', '-05:00'],
        ];
    }

    #[DataProvider('storageBoundaryTimes')]
    public function test_normalized_utc_instant_must_fit_database_date_range(string $wall, string $zone, ?string $offset): void
    {
        $result = Scheduling::normalize($wall, $zone, $offset, $zone);
        $this->assertNull($result['value']);
        $this->assertSame(['scheduled_at'], array_keys($result['errors']));
    }
}
