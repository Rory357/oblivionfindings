<?php

namespace Tests\Unit;

use App\Services\Medication\Controlled\ControlledSettingsFragment;
use App\Services\Medication\Controlled\WeeklyCountAnchorCodec;
use PHPUnit\Framework\TestCase;

class WeeklyCountAnchorCodecTest extends TestCase
{
    public function test_complete_day_and_time_are_one_canonical_value_with_explicit_timezone_metadata(): void
    {
        $codec = new WeeklyCountAnchorCodec;
        $this->assertSame('weekly_anchor', $codec->kind());
        $this->assertSame('{"day":1,"time":"09:00"}', $codec->normalise('{"time":"09:00","day":1}', 'off'));
        $this->assertSame('Monday at 9:00 am (Pacific/Auckland)', $codec->format('{"day":1,"time":"09:00"}'));
        $this->assertSame('Not configured', $codec->format('off'));
        $this->assertTrue($codec->accepts('off'));
        $meta = $codec->toClient();
        $this->assertSame('Pacific/Auckland', $meta['timezone']);
        $this->assertCount(7, $meta['weekday_options']);
        $this->assertSame(['value' => '1', 'label' => 'Monday'], $meta['weekday_options'][0]);
        $this->assertSame(['value' => '7', 'label' => 'Sunday'], $meta['weekday_options'][6]);
        $group = collect(ControlledSettingsFragment::groups())->firstWhere('key', 'controlled_counts');
        $definition = $group->definition('weekly_anchor');
        $this->assertSame('off', $definition->default);
        $this->assertSame('organisation', $definition->scope);
        $this->assertSame('weekly_anchor', $definition->toClient()['kind']);
    }

    public function test_partial_ambiguous_or_noncanonical_input_does_not_become_a_clinical_schedule(): void
    {
        $codec = new WeeklyCountAnchorCodec;
        foreach ([
            '', '{}', '{"day":1}', '{"time":"09:00"}', '{"day":null,"time":""}',
            '{"day":0,"time":"09:00"}', '{"day":8,"time":"09:00"}', '{"day":"1","time":"09:00"}',
            '{"day":1.5,"time":"09:00"}', '{"day":1,"time":"9:00"}', '{"day":1,"time":"24:00"}',
            '{"day":1,"time":"09:60"}', '{"day":1,"time":"09:00","timezone":"UTC"}',
            '{"day":7,"time":"02:00"}', '{"day":7,"time":"02:59"}',
        ] as $value) {
            $this->assertFalse($codec->accepts($value), $value);
            $this->assertSame('off', $codec->normalise($value, 'off'), $value);
        }
        $this->assertSame('off', $codec->normalise(['day' => 1, 'time' => '09:00'], 'off'));
        foreach (['{"day":7,"time":"01:59"}', '{"day":7,"time":"03:00"}', '{"day":1,"time":"02:30"}'] as $valid) {
            $this->assertTrue($codec->accepts($valid), $valid);
        }
        $this->assertStringContainsString('Sunday 2:00–2:59 am', $codec->invalidMessage('Weekly count day and time'));
    }

    public function test_any_change_to_existing_weekly_timing_requires_the_existing_loosening_review(): void
    {
        $codec = new WeeklyCountAnchorCodec;
        $from = '{"day":1,"time":"09:00"}';
        $this->assertFalse($codec->loosens('off', $from));
        $this->assertFalse($codec->loosens($from, '{"time":"09:00","day":1}'));
        $this->assertTrue($codec->loosens($from, '{"day":2,"time":"09:00"}'));
        $this->assertTrue($codec->loosens($from, '{"day":1,"time":"08:00"}'));
        $this->assertTrue($codec->loosens($from, 'off'));
    }
}
