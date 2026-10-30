<?php

namespace Tests\Unit\Eligibility;

use App\Models\Shift;
use App\Models\StaffCredential;
use App\Services\Eligibility\WorkforceEligibilitySources;
use PHPUnit\Framework\TestCase;

/** Pure representation contract; no application bootstrap, source write or DB. */
class WorkforceEligibilitySourceFingerprintTest extends TestCase
{
    public function test_saved_and_database_reloaded_date_attributes_have_the_same_source_fingerprint_and_scope(): void
    {
        $saved = new StaffCredential;
        $saved->setDateFormat('Y-m-d H:i:s');
        $saved->forceFill(['id' => 17, 'user_id' => 42, 'type' => 'DATE-REGRESSION',
            'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31'])->syncOriginal();
        $fresh = new StaffCredential;
        $fresh->setRawAttributes([...$saved->getAttributes(), 'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31'], true);
        $this->assertSame('2027-12-31 00:00:00', $saved->getAttributes()['expires_at']);
        $this->assertSame('2027-12-31', $fresh->getAttributes()['expires_at']);
        $sources = new WorkforceEligibilitySources;

        $this->assertSame($sources->describe($saved), $sources->describe($fresh));
        $this->assertSame([42], array_values(array_unique($sources->describe($fresh)['scope']['user_ids'])));
        $this->assertFalse($sources->describe($fresh)['scope']['all_assigned']);
    }

    public function test_real_date_changes_and_null_dates_still_have_distinct_fingerprints_with_the_same_scope(): void
    {
        $sources = new WorkforceEligibilitySources;
        $original = $this->credential(['expires_at' => '2027-12-31']);
        $changed = $this->credential(['expires_at' => '2028-01-01']);
        $unbounded = $this->credential(['expires_at' => null]);

        $this->assertNotSame($sources->describe($original)['fingerprint'], $sources->describe($changed)['fingerprint']);
        $this->assertNotSame($sources->describe($original)['fingerprint'], $sources->describe($unbounded)['fingerprint']);
        $this->assertSame($sources->describe($original)['scope'], $sources->describe($changed)['scope']);
        $this->assertSame($sources->describe($original)['scope'], $sources->describe($unbounded)['scope']);
        $this->assertNull($unbounded->getAttributes()['expires_at']);
    }

    public function test_formatted_immutable_date_casts_are_canonical_but_invalid_dates_are_not_guessed(): void
    {
        $sources = new WorkforceEligibilitySources;
        $saved = $this->credential(['issued_at' => '2026-01-01 00:00:00', 'expires_at' => null]);
        $saved->mergeCasts(['issued_at' => 'immutable_date:d/m/Y', 'expires_at' => 'date:Y/m/d']);
        $fresh = $this->credential(['issued_at' => '2026-01-01', 'expires_at' => null]);
        $fresh->mergeCasts(['issued_at' => 'immutable_date:d/m/Y', 'expires_at' => 'date:Y/m/d']);
        $this->assertSame($sources->describe($saved), $sources->describe($fresh));

        $invalidDate = $this->credential(['expires_at' => '2026-02-30']);
        $invalidClock = $this->credential(['expires_at' => '2026-02-30 00:00:00']);
        $this->assertNotSame($sources->describe($invalidDate)['fingerprint'], $sources->describe($invalidClock)['fingerprint']);
        $arbitrarySuffix = $this->credential(['expires_at' => '2027-12-31 unavailable']);
        $valid = $this->credential(['expires_at' => '2027-12-31']);
        $this->assertNotSame($sources->describe($arbitrarySuffix)['fingerprint'], $sources->describe($valid)['fingerprint']);
    }

    public function test_datetime_casts_and_arbitrary_date_looking_strings_keep_their_material_differences(): void
    {
        $sources = new WorkforceEligibilitySources;
        $morning = new Shift;
        $morning->setRawAttributes(['id' => 19, 'user_id' => 42, 'starts_at' => '2026-10-06 09:00:00'], true);
        $evening = new Shift;
        $evening->setRawAttributes(['id' => 19, 'user_id' => 42, 'starts_at' => '2026-10-06 17:00:00'], true);
        $this->assertNotSame($sources->describe($morning)['fingerprint'], $sources->describe($evening)['fingerprint']);
        $this->assertSame($sources->describe($morning)['scope'], $sources->describe($evening)['scope']);
        $stringWithClock = $this->credential(['type' => '2027-12-31 00:00:00']);
        $dateString = $this->credential(['type' => '2027-12-31']);
        $this->assertNotSame($sources->describe($stringWithClock)['fingerprint'], $sources->describe($dateString)['fingerprint']);
    }

    private function credential(array $attributes): StaffCredential
    {
        $credential = new StaffCredential;
        $credential->setRawAttributes(['id' => 17, 'user_id' => 42, 'type' => 'DATE-REGRESSION',
            'issued_at' => '2026-01-01', 'expires_at' => '2027-12-31', ...$attributes], true);

        return $credential;
    }
}
