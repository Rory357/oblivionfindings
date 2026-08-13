<?php

namespace Tests\Unit\Services;

use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Eligibility\AssignmentEligibilityStatus;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignmentEligibilityGatewayTest extends TestCase
{
    #[DataProvider('resultStates')]
    public function test_it_maps_rule_results_to_an_explicit_assignment_state(
        EligibilityResult $result,
        AssignmentEligibilityStatus $expectedStatus,
    ): void {
        $service = Mockery::mock(ShiftStaffEligibilityService::class);
        $service->shouldReceive('evaluate')->once()->andReturn($result);

        $decision = (new AssignmentEligibilityGateway($service))->decide(
            $this->shift(),
            $this->user(),
        );

        $this->assertSame($expectedStatus, $decision->status);
        $this->assertSame($result, $decision->result);
    }

    public function test_it_converts_unexpected_rule_stack_failures_to_a_safe_unavailable_decision(): void
    {
        Log::spy();
        $service = Mockery::mock(ShiftStaffEligibilityService::class);
        $service->shouldReceive('evaluate')->once()->andThrow(new \RuntimeException('database host and credential details'));

        $decision = (new AssignmentEligibilityGateway($service))->decide(
            $this->shift(),
            $this->user(),
        );

        $this->assertSame(AssignmentEligibilityStatus::Unavailable, $decision->status);
        $this->assertNull($decision->result);
        Log::shouldHaveReceived('error')->once()->with(
            'Assignment eligibility decision unavailable',
            Mockery::on(fn (array $context): bool => $context['exception_class'] === \RuntimeException::class
                && ! array_key_exists('message', $context)
                && ! str_contains(json_encode($context), 'credential details')),
        );
    }

    public function test_a_decision_cannot_be_reused_after_eligibility_critical_shift_data_changes(): void
    {
        $shift = $this->shift();
        $user = $this->user();
        $service = Mockery::mock(ShiftStaffEligibilityService::class);
        $service->shouldReceive('evaluate')->once()->andReturn(self::eligibilityResult());

        $decision = (new AssignmentEligibilityGateway($service))->decide($shift, $user);
        $this->assertTrue($decision->matches($shift, $user));

        $shift->starts_at = $shift->starts_at->addHour();

        $this->assertFalse($decision->matches($shift, $user));
    }

    public static function resultStates(): array
    {
        return [
            'pass' => [self::eligibilityResult(), AssignmentEligibilityStatus::Pass],
            'warning' => [self::eligibilityResult(warnings: ['Fatigue threshold warning.']), AssignmentEligibilityStatus::Warning],
            'hard block' => [self::eligibilityResult(blocks: ['Current credential is missing.']), AssignmentEligibilityStatus::HardBlock],
        ];
    }

    private static function eligibilityResult(array $blocks = [], array $warnings = []): EligibilityResult
    {
        return new EligibilityResult(
            is_allowed: $blocks === [],
            blocking_reasons: $blocks,
            warnings: $warnings,
            checked_rules: [],
            overrideable_warnings: $warnings === [] ? [] : [[
                'rule' => 'fatigue_weekly',
                'message' => $warnings[0],
                'overrideable' => true,
            ]],
        );
    }

    private function shift(): Shift
    {
        $shift = new Shift([
            'starts_at' => Carbon::parse('2026-08-20 09:00:00'),
            'ends_at' => Carbon::parse('2026-08-20 13:00:00'),
            'site_id' => 20,
            'client_id' => 30,
            'coverage_roles' => [],
            'required_licence_endorsements' => [],
        ]);
        $shift->id = 10;

        return $shift;
    }

    private function user(): User
    {
        $user = new User([
            'approved_at' => Carbon::parse('2026-08-01 09:00:00'),
            'organization_id' => 1,
        ]);
        $user->id = 40;
        $user->updated_at = Carbon::parse('2026-08-01 09:00:00');

        return $user;
    }
}
