<?php

namespace Tests\Unit;

use App\Services\Medication\Controlled\ControlledLocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/** Pure timestamp checks: a validator factory only, no application or DB boot. */
class ControlledLocalTimeTest extends TestCase
{
    private ?Container $previousFacadeApplication = null;

    private mixed $previousValidator = null;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Container::getInstance();
        $this->previousValidator = $container->bound('validator') ? $container->make('validator') : null;
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('validator');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03T08:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $container = Container::getInstance();
        $container->forgetInstance('validator');
        if ($this->previousValidator !== null) {
            $container->instance('validator', $this->previousValidator);
        }
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Facade::clearResolvedInstance('validator');
        parent::tearDown();
    }

    public function test_local_wall_time_is_converted_to_utc_with_the_nz_seasonal_offset(): void
    {
        $parser = new ControlledLocalTime;
        $this->assertSame('2026-10-02T18:15:00+00:00', $parser->parse('2026-10-03T07:15', 'occurred_at')->toIso8601String());
        $this->assertSame('2026-07-02T19:15:00+00:00', $parser->parse('2026-07-03T07:15', 'occurred_at')->toIso8601String());
    }

    public function test_explicit_nz_offsets_select_the_correct_utc_instant(): void
    {
        $parser = new ControlledLocalTime;
        $this->assertSame('2026-10-02T18:15:00+00:00', $parser->parse('2026-10-03T07:15+13:00', 'occurred_at')->toIso8601String());
        $this->assertSame('2026-07-02T19:15:00+00:00', $parser->parse('2026-07-03T07:15+12:00', 'occurred_at')->toIso8601String());
    }

    public function test_daylight_saving_gap_and_fold_require_a_real_unambiguous_time(): void
    {
        $this->assertInvalid('2026-09-27T02:15', 'occurred_at');
        $this->assertInvalid('2026-04-05T02:15', 'occurred_at');
        $parser = new ControlledLocalTime;
        $summer = $parser->parse('2026-04-05T02:15+13:00', 'occurred_at');
        $winter = $parser->parse('2026-04-05T02:15+12:00', 'occurred_at');
        $this->assertSame('2026-04-04T13:15:00+00:00', $summer->toIso8601String());
        $this->assertSame('2026-04-04T14:15:00+00:00', $winter->toIso8601String());
        $this->assertSame(3600, $winter->getTimestamp() - $summer->getTimestamp());
        $this->assertInvalid('2026-09-27T02:15+12:00', 'occurred_at');
        $this->assertInvalid('2026-09-27T02:15+13:00', 'occurred_at');
    }

    public function test_invalid_dates_times_and_trailing_input_are_rejected_on_the_supplied_field(): void
    {
        foreach ([null, '', 'invalid', '2026-10-03', '2026-02-30T07:15', '2026-10-03T25:00', '2026-10-03T07:15garbage', '2026-10-03T07:15:00garbage'] as $value) {
            $this->assertInvalid($value, 'witnessed_at');
        }
    }

    public function test_explicit_seconds_are_preserved_in_valid_iso_input(): void
    {
        $parser = new ControlledLocalTime;
        $this->assertSame('2026-10-02T18:15:30+00:00', $parser->parse('2026-10-03T07:15:30+13:00', 'occurred_at')->toIso8601String());
    }

    public function test_utc_z_is_an_absolute_instant_rather_than_an_auckland_wall_time(): void
    {
        $parser = new ControlledLocalTime;
        $this->assertSame('2026-10-03T07:15:30+00:00', $parser->parse('2026-10-03T07:15:30Z', 'occurred_at')->toIso8601String());
        $this->assertSame('2026-09-27T02:15:30+00:00', $parser->parse('2026-09-27T02:15:30Z', 'occurred_at')->toIso8601String(), 'A UTC instant remains real even when that wall-clock hour is a gap in Auckland.');
        $this->assertInvalid('2026-10-03T08:00:01Z', 'occurred_at');
    }

    public function test_iso_fractional_seconds_are_preserved_for_transport_instants(): void
    {
        $parser = new ControlledLocalTime;
        $this->assertSame('2026-10-03T07:15:30.123000+00:00', $parser->parse('2026-10-03T07:15:30.123Z', 'occurred_at')->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-10-02T18:15:30.250000+00:00', $parser->parse('2026-10-03T07:15:30.250+13:00', 'occurred_at')->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-10-03T07:15:30.123456+00:00', $parser->parse('2026-10-03T07:15:30.123456Z', 'occurred_at')->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-10-02T18:15:30.654321+00:00', $parser->parse('2026-10-03T07:15:30.654321+13:00', 'occurred_at')->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-10-02T18:15:30.400000+00:00', $parser->parse('2026-10-03T07:15:30.4+13:00', 'occurred_at')->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-04-04T14:15:30.654321+00:00', $parser->parse('2026-04-05T02:15:30.654321+12:00', 'occurred_at')->format('Y-m-d\TH:i:s.uP'));
        $this->assertInvalid('2026-09-27T02:15:30.123456', 'occurred_at');
        $this->assertInvalid('2026-04-05T02:15:30.123456', 'occurred_at');
        $this->assertInvalid('2026-10-03T08:00:00.000001Z', 'occurred_at');
        $this->assertInvalid('2026-10-03T07:15:30.1234567Z', 'occurred_at');
        $this->assertInvalid('2026-10-03T08:00:00.001Z', 'occurred_at');
    }

    public function test_future_events_are_refused_unless_the_call_explicitly_allows_them(): void
    {
        $this->assertInvalid('2026-10-03T21:01', 'counted_at');
        $future = (new ControlledLocalTime)->parse('2026-10-03T21:01', 'expires_at', false, true);
        $this->assertSame('2026-10-03T08:01:00+00:00', $future->toIso8601String());
    }

    public function test_default_now_is_used_only_when_authorised_by_the_call(): void
    {
        $parser = new ControlledLocalTime;
        $this->assertSame('2026-10-03T08:00:00+00:00', $parser->parse(null, 'recorded_at', true)->toIso8601String());
        $this->assertSame('2026-10-03T08:00:00+00:00', $parser->parse('', 'recorded_at', true)->toIso8601String());
        $this->assertInvalid(null, 'recorded_at');
    }

    private function assertInvalid(?string $value, string $field): void
    {
        try {
            (new ControlledLocalTime)->parse($value, $field);
            $this->fail('Expected a validation error for '.var_export($value, true));
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
            $this->assertNotEmpty($error->errors()[$field]);
        }
    }
}
