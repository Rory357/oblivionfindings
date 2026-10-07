<?php

namespace Tests\Unit\Medication;

use App\Services\Medication\ExternalClinical\ExternalClinicalPrescription;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ExternalClinicalPrescriptionTest extends TestCase
{
    public function test_portable_json_retains_calendar_dates_and_empty_prn_times(): void
    {
        $payload = ExternalClinicalPrescription::normalise([
            'start_date' => CarbonImmutable::parse('2026-10-07', 'Pacific/Auckland'),
            'end_date' => new \DateTimeImmutable('2026-10-20', new \DateTimeZone('Pacific/Auckland')),
            'review_date' => null, 'is_prn' => true, 'dose_times' => null, 'dosage' => '10 mg',
        ]);
        $packet = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2026-10-07', $packet['start_date']);
        $this->assertSame('2026-10-20', $packet['end_date']);
        $this->assertNull($packet['review_date']);
        $this->assertSame([], $packet['dose_times']);
        $this->assertSame('10 mg', $packet['dosage']);
    }

    public function test_scheduled_times_and_existing_calendar_strings_survive_round_trip(): void
    {
        $payload = ExternalClinicalPrescription::normalise(['start_date' => '2026-10-07', 'dose_times' => ['09:00', '21:00']]);
        $packet = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2026-10-07', $packet['start_date']);
        $this->assertSame(['09:00', '21:00'], $packet['dose_times']);
    }
}
