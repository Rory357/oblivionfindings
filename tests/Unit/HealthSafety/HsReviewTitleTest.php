<?php

namespace Tests\Unit\HealthSafety;

use App\Support\HealthSafety\HsReviewTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** B1.1/B7: a review's title uses its source's words only for people who can read the source. */
class HsReviewTitleTest extends TestCase
{
    /** @return array<string, array{0: array<int, mixed>, 1: string}> */
    public static function cases(): array
    {
        return [
            'incident, reader' => [['incident', true, 'Fall in the shower', 'Amelia Wilson', 'Hemi Walker', 'Harbour Respite'], 'Fall in the shower — Amelia Wilson'],
            'incident, H&S viewer who cannot read it' => [['incident', false, 'Fall in the shower', 'Amelia Wilson', 'Hemi Walker', 'Harbour Respite'], 'Incident — Amelia Wilson'],
            'near miss, cannot read' => [['near_miss', false, null, 'Tane Wineera', null, 'Kauri House'], 'Near miss — Tane Wineera'],
            'reader, but the source has no words' => [['incident', true, '  ', 'Liam Davis', null, 'Kauri House'], 'Incident — Liam Davis'],
            'chemical exposure, reader' => [['exposure', true, null, null, 'Mere Tane', 'Kauri House'], 'Chemical exposure — Mere Tane'],
            'chemical exposure, cannot read' => [['exposure', false, null, null, 'Mere Tane', 'Kauri House'], 'Chemical exposure — Kauri House'],
            'safeguarding, reader' => [['safeguarding', true, null, 'Aroha Kingi', null, 'Kauri House'], 'Safeguarding — Aroha Kingi'],
            'safeguarding, cannot read' => [['safeguarding', false, null, 'Aroha Kingi', null, 'Kauri House'], 'Safeguarding — Kauri House'],
            'first aid by direct link, cannot read' => [['injury', false, null, 'Oliver Chen', 'Jordan Peri', 'Rimu House', true], 'Injury — Rimu House'],
            'injury names the worker' => [['injury', true, null, 'Oliver Chen', 'Sam Wong', 'Rimu House'], 'Injury — Sam Wong'],
            'hazard with no person' => [['hazard', false, null, null, null, 'Rimu House'], 'Hazard — Rimu House'],
            'nothing to name' => [['vehicle_incident', false, null, null, null, null], 'Vehicle incident'],
        ];
    }

    /** @param array<int, mixed> $input */
    #[DataProvider('cases')]
    public function test_titles(array $input, string $expected): void
    {
        $this->assertSame($expected, HsReviewTitle::make(...$input));
    }
}
