<?php

namespace Tests\Unit\HealthSafety;

use App\Support\HealthSafety\HsLabels;
use PHPUnit\Framework\TestCase;

/** The server labels match the shared contract the client mirror (hs-labels.ts) also checks. */
class HsLabelsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 2).'/fixtures/health-safety/labels.json';

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_label_maps_match_the_shared_fixture(): void
    {
        $this->assertSame($this->fixture()['labels'], HsLabels::LABELS);
    }

    public function test_unknown_values_are_made_readable(): void
    {
        foreach ($this->fixture()['humanise'] as $input => $expected) {
            $this->assertSame($expected, HsLabels::humanise((string) $input), "humanise({$input})");
        }
        $this->assertSame('New kind of thing', HsLabels::label('review_category', 'new_kind_of_thing'));
    }

    public function test_approved_words_and_missing_values(): void
    {
        $this->assertSame('Near miss', HsLabels::label('review_category', 'near_miss'));
        $this->assertSame('Urgent', HsLabels::label('action_priority', 'critical'));
        $this->assertSame('Not set', HsLabels::label('severity', null));
        $this->assertSame('Not available', HsLabels::label('record_state', ''));
    }
}
