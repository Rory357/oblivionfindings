<?php

namespace Tests\Unit\Clinical;

use App\Domain\Clinical\Enums\News2Band;
use App\Domain\Clinical\Services\News2Scorer;
use Tests\TestCase;

class News2ScorerTest extends TestCase
{
    private News2Scorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new News2Scorer();
    }

    /**
     * A complete, all-normal vitals set.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function vitals(array $overrides = []): array
    {
        return array_merge([
            'systolic' => 120,
            'diastolic' => 80,
            'pulse' => 70,
            'respiration_rate' => 16,
            'o2_saturation' => 98,
            'temperature' => 36.5,
            'consciousness' => 'A',
            'on_oxygen' => false,
            'spo2_scale' => 1,
        ], $overrides);
    }

    public function test_all_normal_scores_zero_low(): void
    {
        $result = $this->scorer->score($this->vitals());

        $this->assertNotNull($result);
        $this->assertSame(0, $result->score);
        $this->assertSame(News2Band::Low, $result->band);
        $this->assertFalse($result->redFlag);
    }

    public function test_single_parameter_three_triggers_low_medium_red_flag(): void
    {
        // Respiratory rate 6 scores 3; everything else 0 → aggregate 3 with a red flag.
        $result = $this->scorer->score($this->vitals(['respiration_rate' => 6]));

        $this->assertSame(3, $result->score);
        $this->assertTrue($result->redFlag);
        $this->assertSame(News2Band::LowMedium, $result->band);
    }

    public function test_aggregate_five_to_six_is_medium(): void
    {
        // RR 22 (2) + SpO2 93 Scale1 (2) + pulse 95 (1) = 5.
        $result = $this->scorer->score($this->vitals([
            'respiration_rate' => 22,
            'o2_saturation' => 93,
            'pulse' => 95,
        ]));

        $this->assertSame(5, $result->score);
        $this->assertSame(News2Band::Medium, $result->band);
    }

    public function test_aggregate_seven_or_more_is_high(): void
    {
        // RR 26 (3) + SpO2 90 Scale1 (3) + systolic 88 (3) = 9.
        $result = $this->scorer->score($this->vitals([
            'respiration_rate' => 26,
            'o2_saturation' => 90,
            'systolic' => 88,
        ]));

        $this->assertGreaterThanOrEqual(7, $result->score);
        $this->assertSame(News2Band::High, $result->band);
        $this->assertTrue($result->redFlag);
    }

    public function test_supplemental_oxygen_adds_two_points(): void
    {
        $onAir = $this->scorer->score($this->vitals(['on_oxygen' => false]));
        $onOxygen = $this->scorer->score($this->vitals(['on_oxygen' => true]));

        $this->assertSame(0, $onAir->score);
        $this->assertSame(2, $onOxygen->score);
        $this->assertSame(2, $onOxygen->breakdown['air_or_oxygen']);
    }

    public function test_consciousness_other_than_alert_scores_three(): void
    {
        $result = $this->scorer->score($this->vitals(['consciousness' => 'C']));

        $this->assertSame(3, $result->breakdown['consciousness']);
        $this->assertTrue($result->redFlag);
        $this->assertSame(News2Band::LowMedium, $result->band);
    }

    public function test_scale_two_on_air_in_target_range_scores_zero(): void
    {
        // COPD patient on air at 90% — within the 88–92 target range → SpO2 scores 0.
        $result = $this->scorer->score($this->vitals([
            'o2_saturation' => 90,
            'spo2_scale' => 2,
            'on_oxygen' => false,
        ]));

        $this->assertSame(0, $result->breakdown['spo2']);
        $this->assertSame(0, $result->score);
        $this->assertSame(News2Band::Low, $result->band);
    }

    public function test_scale_two_on_oxygen_high_saturation_scores_three(): void
    {
        // On oxygen at 98% under Scale 2 is dangerous (over-oxygenation) → SpO2 3 + oxygen 2.
        $result = $this->scorer->score($this->vitals([
            'o2_saturation' => 98,
            'spo2_scale' => 2,
            'on_oxygen' => true,
        ]));

        $this->assertSame(3, $result->breakdown['spo2']);
        $this->assertSame(5, $result->score);
        $this->assertSame(News2Band::Medium, $result->band);
    }

    public function test_temperature_bands(): void
    {
        $this->assertSame(3, $this->scorer->score($this->vitals(['temperature' => 35.0]))->breakdown['temperature']);
        $this->assertSame(1, $this->scorer->score($this->vitals(['temperature' => 35.5]))->breakdown['temperature']);
        $this->assertSame(0, $this->scorer->score($this->vitals(['temperature' => 37.0]))->breakdown['temperature']);
        $this->assertSame(2, $this->scorer->score($this->vitals(['temperature' => 39.5]))->breakdown['temperature']);
    }

    public function test_returns_null_when_physiological_set_incomplete(): void
    {
        // Missing temperature → NEWS2 cannot be validly computed.
        $data = $this->vitals();
        unset($data['temperature']);

        $this->assertNull($this->scorer->score($data));
    }

    public function test_defaults_consciousness_to_alert_and_air_when_absent(): void
    {
        $data = $this->vitals();
        unset($data['consciousness'], $data['on_oxygen'], $data['spo2_scale']);

        $result = $this->scorer->score($data);

        $this->assertNotNull($result);
        $this->assertSame(0, $result->breakdown['consciousness']);
        $this->assertSame(0, $result->breakdown['air_or_oxygen']);
        $this->assertSame(News2Band::Low, $result->band);
    }
}
