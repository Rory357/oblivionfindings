<?php

namespace Tests\Unit;

use App\Support\MedicationJourney;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MedicationJourneyTest extends TestCase
{
    #[DataProvider('returnLocations')]
    public function test_only_known_local_journeys_can_be_return_locations(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, MedicationJourney::returnTo($value));
    }

    public static function returnLocations(): array
    {
        return [
            'my day' => ['/my-day?date=2026-10-03', '/my-day?date=2026-10-03'],
            'filtered directory' => ['/emar/mar?site_id=3&work=overdue', '/emar/mar?site_id=3&work=overdue'],
            'profile section' => ['/operations/clients/5?tab=medical#conditions', '/operations/clients/5?tab=medical#conditions'],
            'shift' => ['/operations/shifts/12?tab=medications', '/operations/shifts/12?tab=medications'],
            'calendar' => ['/sites/2?tab=calendar', '/sites/2?tab=calendar'],
            'all sites calendar' => ['/calendar?view=week', '/calendar?view=week'],
            'attendance handover' => ['/attendance?tab=handovers', '/attendance?tab=handovers'],
            'nested source' => ['/emar/prn?date=2026-10-02&return_to=%2Fmeds%2Ftoday#history', '/emar/prn?date=2026-10-02#history'],
            'external' => ['https://example.com/emar', null],
            'protocol relative' => ['//example.com/emar', null],
            'encoded protocol relative' => ['/%2fexample.com/emar', null],
            'prefix confusion' => ['/emar-foreign', null],
            'unrelated route' => ['/logout', null],
            'traversal' => ['/emar/../logout', null],
            'encoded traversal' => ['/emar/%2e%2e/logout', null],
            'encoded slash traversal' => ['/emar%2f..%2flogout', null],
            'backslash' => ['/emar\\evil', null],
            'encoded backslash' => ['/emar/%5cevil', null],
            'encoded control' => ['/emar?x=%0d%0aLocation:evil', null],
            'array input' => [['/emar'], null],
            'too long' => ['/emar?q='.str_repeat('x', 2048), null],
        ];
    }
}
