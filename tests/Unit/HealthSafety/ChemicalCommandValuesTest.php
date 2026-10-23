<?php

namespace Tests\Unit\HealthSafety;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Whole pure value contract: no database, source authority or file acceptance. */
final class ChemicalCommandValuesTest extends TestCase
{
    private const KEY = 'CFC12721-28D6-42AC-A24E-0626A1A85071';

    private mixed $previousFacadeApplication;

    private mixed $previousTestNow;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousTestNow = Carbon::getTestNow();
        $this->previousTimezone = date_default_timezone_get();
        $container = new Container;
        $container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en')));
        $container->instance('date', new DateFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-10-12T12:00:00+13:00')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow($this->previousTestNow);
        date_default_timezone_set($this->previousTimezone);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_new_capture_has_uuid_without_an_existing_revision(): void
    {
        $values = ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'name' => 'Recorded cleaner'],
            ['name' => ['required', 'string', 'max:255']], true);
        $this->assertSame(['idempotency_key' => strtolower(self::KEY), 'name' => 'Recorded cleaner'], $values);
        $this->invalid('command', fn () => ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'expected_version' => 0], [], true));
    }

    public function test_existing_commands_require_revision_and_allow_genuine_legacy_zero(): void
    {
        $this->invalid('expected_version', fn () => ChemicalCommandValues::normalize(['idempotency_key' => self::KEY], []));
        foreach ([0, '0', 1, '4294967294'] as $revision) {
            $values = ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'expected_version' => $revision], []);
            $this->assertSame((int) $revision, $values['expected_version']);
        }
        foreach ([-1, 4294967295, true, 1.5, '1e2', '01'] as $revision) {
            $this->invalid('expected_version', fn () => ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'expected_version' => $revision], []));
        }
    }

    public function test_missing_malformed_or_non_string_uuid_never_accepts_an_intent(): void
    {
        foreach ([null, '', 'accepted-yesterday', 123, ['uuid' => self::KEY], self::KEY.' '] as $key) {
            $this->invalid('idempotency_key', fn () => ChemicalCommandValues::normalize(['idempotency_key' => $key], [], true));
        }
    }

    public function test_http_framing_and_unreviewed_domain_keys_are_rejected(): void
    {
        foreach (['_method', 'return_to', 'actor_id', 'source_revision', 'worksafe_notifiable', 'medical_override'] as $key) {
            $this->invalid('command', fn () => ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, $key => 'x'], [], true));
        }
    }

    public function test_domain_rules_cannot_weaken_envelope_or_add_create_expected_revision(): void
    {
        foreach (['idempotency_key', 'expected_version'] as $reserved) {
            try {
                ChemicalCommandValues::normalize(['idempotency_key' => self::KEY], [$reserved => ['nullable']], true);
                $this->fail('Domain rules must not replace acceptance identity.');
            } catch (LogicException $failure) {
                $this->assertStringContainsString('envelope', $failure->getMessage());
            }
        }
    }

    public function test_boolean_form_and_json_values_are_typed_without_coercing_arbitrary_text(): void
    {
        foreach ([true, 1, '1', false, 0, '0'] as $input) {
            $values = ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'properly_labelled' => $input],
                ['properly_labelled' => ['required', 'boolean']], true);
            $this->assertSame(in_array($input, [true, 1, '1'], true), $values['properly_labelled']);
        }
        foreach (['false', 'yes', 2, 0.0, null] as $input) {
            $this->invalid('properly_labelled', fn () => ChemicalCommandValues::normalize(
                ['idempotency_key' => self::KEY, 'properly_labelled' => $input], ['properly_labelled' => ['required', 'boolean']], true));
        }
    }

    public function test_source_identity_refuses_boolean_float_scientific_and_rounded_values(): void
    {
        $rules = ['user_id' => ['required', 'integer', 'min:1', 'max:9007199254740991']];
        $this->assertSame(12, ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'user_id' => '12'], $rules, true)['user_id']);
        foreach ([true, 12.0, '1.2', '1e2', '012', 0, -1] as $id) {
            $this->invalid('user_id', fn () => ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'user_id' => $id], $rules, true));
        }
    }

    public function test_recorded_classifications_controls_and_exposure_text_are_preserved_without_certification(): void
    {
        $facts = ['hazard_classifications' => ['Supplier statement', 'Other recorded class'], 'ghs_pictograms' => ['GHS03', 'GHS05'],
            'exposure_limit_type' => 'Recorded supplier limit', 'exposure_limit_value' => 'See retained document',
            'circumstances' => 'The worker reported a splash.', 'medical_outcome' => null];
        $rules = ['hazard_classifications' => ['array'], 'hazard_classifications.*' => ['string', 'max:100'],
            'ghs_pictograms' => ['array'], 'ghs_pictograms.*' => ['string', 'max:20'], 'exposure_limit_type' => ['string'],
            'exposure_limit_value' => ['string'], 'circumstances' => ['string', 'max:5000'], 'medical_outcome' => ['nullable', 'string']];
        $values = ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, ...$facts], $rules, true);
        $this->assertSame($facts, array_diff_key($values, ['idempotency_key' => true]));
        $this->assertArrayNotHasKey('worksafe_notifiable', $values);
    }

    public function test_nested_reviewed_rules_reject_unreviewed_nested_attributes(): void
    {
        $rules = ['observation' => ['required', 'array:reported'], 'observation.reported' => ['required', 'string']];
        $values = ChemicalCommandValues::normalize(['idempotency_key' => self::KEY, 'observation' => ['reported' => 'Recorded fact']], $rules, true);
        $this->assertSame(['reported' => 'Recorded fact'], $values['observation']);
        $this->invalid('observation', fn () => ChemicalCommandValues::normalize(
            ['idempotency_key' => self::KEY, 'observation' => ['reported' => 'Recorded fact', 'approved' => true]], $rules, true));
    }

    public function test_calendar_day_validates_real_leap_day_and_keeps_unknown_distinct(): void
    {
        $this->assertSame('2024-02-29', ChemicalCommandValues::day('2024-02-29', 'issue_date', true));
        $this->assertNull(ChemicalCommandValues::day(null, 'review_date'));
        foreach (['2026-02-29', '2026-04-31', '2026-1-2', '2026-10-12T00:00:00Z', '0000-01-01', true] as $day) {
            $this->invalid('issue_date', fn () => ChemicalCommandValues::day($day, 'issue_date'));
        }
    }

    public function test_completed_day_uses_auckland_today_while_planned_review_can_be_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11T11:30:00Z')->utc());
        $this->assertSame('2026-10-12', ChemicalCommandValues::day('2026-10-12', 'last_audit_date', true));
        $this->invalid('last_audit_date', fn () => ChemicalCommandValues::day('2026-10-13', 'last_audit_date', true));
        $this->assertSame('2027-10-12', ChemicalCommandValues::day('2027-10-12', 'review_date'));
    }

    public function test_actual_instant_requires_offset_and_preserves_microseconds_in_utc(): void
    {
        $this->assertSame('2026-10-11 23:00:00.123456', ChemicalCommandValues::instant('2026-10-12T12:00:00.123456+13:00', 'exposed_at', false));
        $this->assertSame('2026-10-11 23:00:00.000000', ChemicalCommandValues::instant('2026-10-12T12:00+13:00', 'exposed_at'));
        $this->assertNull(ChemicalCommandValues::instant(null, 'completed_at'));
        foreach (['2026-10-12T11:00', '2026-10-12 11:00:00', '2026-02-29T00:00:00Z', '2026-10-12T24:00:00Z',
            '2026-10-12T01:60:00Z', '2026-10-12T01:00:60Z', '2026-10-12T01:00:00.1234567Z',
            '2026-10-12T01:00:00+99:00', '2026-10-12T01:00:00+14:01', '2026-10-12T01:00:00+13:60'] as $instant) {
            $this->invalid('exposed_at', fn () => ChemicalCommandValues::instant($instant, 'exposed_at', false));
        }
    }

    public function test_actual_future_instant_is_denied_without_clamping_reported_time(): void
    {
        $this->invalid('exposed_at', fn () => ChemicalCommandValues::instant('2026-10-12T12:00:01+13:00', 'exposed_at'));
        $this->assertSame('2026-10-12 00:00:00.000000', ChemicalCommandValues::instant('2026-10-12T13:00:00+13:00', 'next_review_at', false));
    }

    public function test_dst_offsets_preserve_actual_instants_not_reinterpreted_local_wall_times(): void
    {
        $this->assertSame('2026-09-26 14:30:00.000000', ChemicalCommandValues::instant('2026-09-27T03:30:00+13:00', 'exposed_at'));
        $this->assertSame('2026-04-04 13:30:00.000000', ChemicalCommandValues::instant('2026-04-05T02:30:00+13:00', 'exposed_at'));
        $this->assertSame('2026-04-04 14:30:00.000000', ChemicalCommandValues::instant('2026-04-05T02:30:00+12:00', 'exposed_at'));
    }

    public function test_storage_decimal_preserves_zero_unknown_and_database_precision(): void
    {
        $this->assertNull(ChemicalCommandValues::decimalQuantity(null, 'current_quantity'));
        foreach ([[0, '0.00'], [20, '20.00'], ['20.5', '20.50'], ['99999999.99', '99999999.99'], [0.25, '0.25']] as [$value, $expected]) {
            $this->assertSame($expected, ChemicalCommandValues::decimalQuantity($value, 'current_quantity'));
        }
    }

    public function test_storage_decimal_refuses_rounding_overflow_and_nonfactual_values(): void
    {
        foreach ([-1, '0.001', '100000000', '1e2', '00.50', ' 2 ', true, [], INF, NAN] as $quantity) {
            $this->invalid('current_quantity', fn () => ChemicalCommandValues::decimalQuantity($quantity, 'current_quantity'));
        }
    }

    public function test_digest_is_stable_for_object_keys_but_retains_list_order_and_types(): void
    {
        $first = ['scope' => ['site_id' => 12, 'worker_id' => 34], 'facts' => ['text' => 'Māori reported facts', 'unknown' => null]];
        $second = ['facts' => ['unknown' => null, 'text' => 'Māori reported facts'], 'scope' => ['worker_id' => 34, 'site_id' => 12]];
        $this->assertSame(ChemicalCommandValues::digest($first), ChemicalCommandValues::digest($second));
        $this->assertNotSame(ChemicalCommandValues::digest(['a', 'b']), ChemicalCommandValues::digest(['b', 'a']));
        foreach ([[1, '1'], [1, 1.0], [false, 0], [null, ''], [[], null]] as [$left, $right]) {
            $this->assertNotSame(ChemicalCommandValues::digest($left), ChemicalCommandValues::digest($right));
        }
    }

    public function test_digest_never_serializes_objects_or_nonfinite_numbers_as_factual_evidence(): void
    {
        foreach ([new \stdClass, INF, NAN, ['nested' => new \stdClass]] as $value) {
            try {
                ChemicalCommandValues::digest($value);
                $this->fail('Non-detached values must not become an accepted source digest.');
            } catch (LogicException $failure) {
                $this->assertStringContainsString('detached factual', $failure->getMessage());
            }
        }
    }

    public function test_fingerprint_binds_actor_verb_source_parent_revision_and_typed_payload(): void
    {
        $values = ['idempotency_key' => strtolower(self::KEY), 'expected_version' => 3, 'medical_outcome' => null];
        $expected = ChemicalCommandValues::fingerprint('exposure', 12, 45, 67, 'correct_exposure', $values);
        foreach ([['exposure', 12, 45, 68, 'correct_exposure', $values], ['exposure', 13, 45, 67, 'correct_exposure', $values],
            ['exposure', 12, 46, 67, 'correct_exposure', $values], ['storage', 12, 45, 67, 'correct_exposure', $values],
            ['exposure', 12, 45, 67, 'review_exposure', $values], ['exposure', 12, 45, 67, 'correct_exposure', [...$values, 'expected_version' => 4]],
            ['exposure', 12, 45, 67, 'correct_exposure', [...$values, 'medical_outcome' => 'Recorded outcome']]] as $intent) {
            $this->assertNotSame($expected, ChemicalCommandValues::fingerprint(...$intent));
        }
        $this->assertSame($expected, ChemicalCommandValues::fingerprint('exposure', 12, 45, 67, 'correct_exposure', [...$values, 'idempotency_key' => 'another-ledger-key']));
        $this->assertNotSame($expected, ChemicalCommandValues::fingerprint('exposure', null, 45, 67, 'correct_exposure', $values));
    }

    public function test_fingerprint_refuses_noncanonical_source_and_actor_identity(): void
    {
        foreach ([['', 12, 45, 67, 'create'], ['exposure', 0, 45, 67, 'create'], ['exposure', 12, 0, 67, 'create'],
            ['exposure', 12, 45, 0, 'create'], ['exposure', 12, 45, 67, '']] as $identity) {
            try {
                ChemicalCommandValues::fingerprint(...[...$identity, []]);
                $this->fail('Malformed identity must not produce an accepted intent.');
            } catch (LogicException $failure) {
                $this->assertStringContainsString('canonical', $failure->getMessage());
            }
        }
    }

    private function invalid(string $field, callable $operation): void
    {
        try {
            $operation();
            $this->fail('The invalid factual value must be rejected.');
        } catch (ValidationException $failure) {
            $this->assertArrayHasKey($field, $failure->errors());
        }
    }
}
