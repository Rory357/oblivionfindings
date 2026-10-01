<?php

namespace Tests\Unit\Medication\DoseSlots;

use App\Services\Medication\DoseSlots\DoseTimeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one dose-time reading (moved unchanged from MarScheduleService).
 */
class DoseTimeParserTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string|null, 2: list<string>}>
     */
    public static function orders(): array
    {
        return [
            'structured times win and are sorted' => [['20:00', '08:00', '08:00'], 'morning', ['08:00', '20:00']],
            'invalid structured times are ignored' => [['8:00', '24:00', 'noon', '07:30'], null, ['07:30']],
            'no structured times falls back to the frequency' => [[], '08:00, 20:00', ['08:00', '20:00']],
            'non-array column falls back to the frequency' => [null, '8 am and 6pm', ['08:00', '18:00']],
            '12-hour times' => [[], '8:30am, 12pm, 12am', ['00:00', '08:30', '12:00']],
            'keywords' => [[], 'Morning and night', ['08:00', '21:00']],
            'evening and bedtime' => [[], 'evening, bedtime', ['18:00', '21:00']],
            'duplicates merge' => [[], 'midday 12:00 noon', ['12:00']],
            'unreadable' => [[], 'As directed', []],
            'nothing at all' => [[], null, []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('orders')]
    public function test_reads_dose_times(mixed $column, ?string $frequency, array $expected): void
    {
        $this->assertSame($expected, DoseTimeParser::parse($column, $frequency));
    }
}
