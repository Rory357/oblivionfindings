<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Support\HealthSafety\RiskAssessmentReturn;
use PHPUnit\Framework\TestCase;

class RiskAssessmentReturnTest extends TestCase
{
    public function test_register_context_preserves_only_bounded_filters(): void
    {
        self::assertSame('/health-safety/risk-assessments?tab=due&site_id=12&search=loose%20mat&page=2',
            RiskAssessmentReturn::sanitize('/health-safety/risk-assessments?tab=due&site_id=12&search=loose%20mat&page=2&assessment=8&action=archive&return_to=https://other.example'));
        self::assertSame('/health-safety/risk-assessments', RiskAssessmentReturn::sanitize('/health-safety/risk-assessments?tab=bad&risk_level[]=high&page=0&status=bad&risk_acceptable=unknown'));
        self::assertSame('/health-safety/risk-assessments?tab=active', RiskAssessmentReturn::fromRegister(['assessment' => 8, 'tab' => 'active']));
    }

    public function test_source_context_preserves_real_profile_tabs_without_recursive_returns(): void
    {
        foreach (['/sites/7?tab=risk_assessments', '/operations/clients/9?tab=risk_management', '/health-safety/events/3?section=actions', '/tasks?sources=corrective_action&done=true', '/my-day'] as $origin) {
            self::assertSame($origin, RiskAssessmentReturn::sanitize($origin));
            self::assertSame('/health-safety/risk-assessments/42?return_to='.rawurlencode($origin), RiskAssessmentReturn::href(42, $origin));
        }
        self::assertSame('/sites/7?tab=risk_assessments', RiskAssessmentReturn::sanitize('/sites/7?tab=risk_assessments&return_to=%2Ftasks&action=delete'));
    }

    public function test_linked_workspace_sanitizer_accepts_risk_record_and_one_safe_origin_only(): void
    {
        $origin = '/health-safety/risk-assessments?tab=due&site_id=7&search=mat';
        $record = RiskAssessmentReturn::href(42, $origin);
        self::assertSame($record, RecoverableTaskAuthorizationException::validatedReturnTo($record));
        self::assertSame('/health-safety/risk-assessments/42?section=reviews', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/risk-assessments/42?section=reviews&action=complete-review'));
        foreach (['https://other.example/tasks', '//other.example/tasks', '%2F%2Fother.example/tasks', '/health-safety/risk-assessments/9?return_to=/tasks'] as $origin) {
            self::assertSame('/health-safety/risk-assessments/42', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/risk-assessments/42?return_to='.rawurlencode($origin)));
        }
        foreach (['/health-safety/risk-assessments/0', '/health-safety/risk-assessments/42/activate', '/health-safety/risk-assessments/42#archive', '/health-safety/risk-assessments/42%2farchive', '/health-safety/risk-assessments/42\\evil.example'] as $record) {
            self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo($record));
        }
        self::assertSame('/health-safety/risk-assessments/42', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/risk-assessments/42?section[]=reviews&return_to[]=https://other.example'));
    }

    public function test_external_encoded_protocol_malformed_recursive_and_array_targets_are_rejected(): void
    {
        foreach ([null, [], '', 'https://other.example/tasks', '//other.example/tasks', '%2F%2Fother.example/tasks', '/%2f%2fother.example/tasks', '/sites/0', '/sites/7/delete', '/sites/7#command', '/sites/7\\other.example', '/sites/7%00', '/sites/7%2fdelete', '/health-safety/events/-3', '/health-safety/risk-assessments/42?return_to=/tasks'] as $origin) {
            self::assertNull(RiskAssessmentReturn::sanitize($origin));
        }
        self::assertSame('/sites/7', RiskAssessmentReturn::sanitize('/sites/7?tab[]=risk_assessments&section[]=archive'));
        self::assertSame('/health-safety/risk-assessments/42', RiskAssessmentReturn::href(42, 'https://other.example'));
    }
}
