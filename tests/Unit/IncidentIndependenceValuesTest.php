<?php

namespace Tests\Unit;

use App\Services\Incidents\IncidentIndependenceValues as Values;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class IncidentIndependenceValuesTest extends TestCase
{
    private mixed $previousFacadeApplication;
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $container = new \Illuminate\Container\Container;
        $container->instance('validator', new \Illuminate\Validation\Factory(new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader, 'en')));
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    }
    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }
    public function test_named_and_unknown_are_explicit_reported_intent_not_findings(): void
    {
        $this->assertSame([2, 9], Values::handling(['requested' => true, 'reason_code' => 'other_staff_involved', 'identification' => 'named', 'involved_user_ids' => [9, 2]])['involved_user_ids']);
        $this->assertSame([], Values::handling(['requested' => true, 'reason_code' => 'usual_manager_involved', 'identification' => 'unknown', 'involved_user_ids' => []])['involved_user_ids']);
    }
    public function test_unknown_cannot_smuggle_named_people_or_narrative(): void
    {
        foreach ([['involved_user_ids' => [4]], ['finding' => 'misconduct'], ['requested' => 'true']] as $extra) {
            try { Values::handling([...['requested' => true, 'reason_code' => 'usual_manager_involved', 'identification' => 'unknown', 'involved_user_ids' => []], ...$extra]); $this->fail('Expected closed intent refusal.'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
        }
    }
    public function test_named_ids_are_distinct_bounded_and_required(): void
    {
        foreach ([[], [1, 1], [0], [2147483648], [[1]], range(1, 11)] as $ids) {
            try { Values::handling(['requested' => true, 'reason_code' => 'other_staff_involved', 'identification' => 'named', 'involved_user_ids' => $ids]); $this->fail('Expected source identity refusal.'); }
            catch (ValidationException $error) { $this->assertNotEmpty($error->errors()); }
        }
    }
    public function test_policy_and_declaration_revisions_have_separate_meanings(): void
    {
        $uuid = (string) Str::uuid(); $input = ['request_uuid' => strtoupper($uuid), 'report_request_uuid' => (string) Str::uuid(), 'expected_revision' => 3,
            'expected_policy_revision' => 8, 'intent' => 'submit', 'capture_context' => ['client_id' => 1, 'site_id' => 2, 'shift_id' => null],
            'independent_handling' => ['requested' => true, 'reason_code' => 'usual_manager_involved', 'identification' => 'unknown', 'involved_user_ids' => []]];
        $values = Values::intakeCommand($input); $this->assertSame(3, $values['expected_revision']); $this->assertSame(8, $values['expected_policy_revision']); $this->assertSame($uuid, $values['request_uuid']);
        unset($input['expected_policy_revision']); $this->expectException(ValidationException::class); Values::intakeCommand($input);
    }
    public function test_policy_has_only_two_explicit_modes_and_requires_reason(): void
    {
        foreach (['block_involved_access', 'assignment_only'] as $mode) { $this->assertSame($mode, Values::policyCommand(['mode' => $mode, 'expected_policy_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Actual decision.'])['mode']); }
        $this->expectException(ValidationException::class); Values::policyCommand(['mode' => 'public', 'expected_policy_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Actual decision.']);
    }
    public function test_typed_digest_is_order_independent_but_does_not_coerce_scalar_identity(): void
    {
        $this->assertSame(Values::digest(['b' => [2, 1], 'a' => true]), Values::digest(['a' => true, 'b' => [2, 1]]));
        $this->assertNotSame(Values::digest(['id' => 1]), Values::digest(['id' => '1']));
    }
}
