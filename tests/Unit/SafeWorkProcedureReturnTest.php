<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use PHPUnit\Framework\TestCase;

class SafeWorkProcedureReturnTest extends TestCase
{
    public function test_supported_worklists_preserve_filters_and_drop_commands(): void
    {
        foreach ([
            '/incidents?tab=review&site_id=2&from=2026-10-01',
            '/incidents?from=2026-09-03&to=2026-09-19&kind=incidents&tab=all&site_id=3',
            '/health-safety/events?tab=all&q=mat&site_id=2',
            '/health-safety/corrective-actions?status=in_progress&overdue=true',
            '/compliance/hazards?tab=mitigated&due_state=overdue',
            '/health-safety/risk-assessments?tab=due&risk_acceptable=0',
            '/health-safety/procedures?tab=review_due&q=manual%20handling&page=2',
            '/tasks?sources=corrective_action&bucket=open&done=true',
            '/my-day', '/hr/my',
        ] as $origin) {
            self::assertSame($origin, SafeWorkProcedureReturn::sanitize($origin.(str_contains($origin, '?') ? '&' : '?').'action=approve&procedure=99&edit=1'));
        }
        self::assertSame('/health-safety/procedures?tab=approved', SafeWorkProcedureReturn::fromRegister(['tab' => 'approved', 'procedure' => 8, 'action' => 'archive']));
        self::assertSame('/incidents?site_id=3', SafeWorkProcedureReturn::leaf('/incidents?kind=unsupported&site_id=3'));
        self::assertSame('/incidents?kind=incidents&site_id=3', \App\Support\HealthSafety\FirstAidReturn::leaf('/incidents?kind=incidents&site_id=3'));
    }

    public function test_actual_profile_tabs_and_record_sections_are_preserved(): void
    {
        foreach ([
            '/sites/7?tab=hazards', '/sites/7?tab=risk_assessments',
            '/operations/clients/9?tab=risk_management', '/hr/people/3?tab=training',
            '/incidents/2?section=linked', '/hazards/5?section=risk',
            '/health-safety/events/6?section=actions', '/health-safety/events/6?section=handover',
            '/health-safety/events/6?section=investigation', '/health-safety/events/6?section=risk', '/health-safety/events/6?section=timeline',
            '/health-safety/corrective-actions/8?section=evidence',
            '/health-safety/risk-assessments/4?section=risk',
            '/health-safety/procedures/11?section=documents&version=25',
            '/health-safety/procedures/11?section=connections&revision=working&request=6',
        ] as $origin) {
            self::assertSame($origin, SafeWorkProcedureReturn::sanitize($origin));
        }
        self::assertSame('/sites/7', SafeWorkProcedureReturn::sanitize('/sites/7?tab=unknown'));
        self::assertSame('/hr/people/3', SafeWorkProcedureReturn::sanitize('/hr/people/3?tab=unknown'));
        self::assertSame('/health-safety/risk-assessments/4', SafeWorkProcedureReturn::sanitize('/health-safety/risk-assessments/4?section=risk-controls'));
        self::assertSame('/health-safety/procedures/11?version=25', SafeWorkProcedureReturn::sanitize('/health-safety/procedures/11?version=25&revision=working'));
    }

    public function test_one_nested_leaf_keeps_context_and_rejects_recursive_or_self_returns(): void
    {
        $leaf = '/health-safety/procedures?tab=review_due&site_id=7';
        $record = '/health-safety/procedures/11?section=connections&return_to='.rawurlencode($leaf);
        self::assertSame($record, SafeWorkProcedureReturn::sanitize($record));
        self::assertSame($record, RecoverableTaskAuthorizationException::validatedReturnTo($record));
        self::assertSame('/health-safety/procedures/11', SafeWorkProcedureReturn::sanitize('/health-safety/procedures/11?return_to='.rawurlencode('/health-safety/procedures/11?section=steps')));
        self::assertSame('/health-safety/procedures/11', SafeWorkProcedureReturn::sanitize('/health-safety/procedures/11?return_to='.rawurlencode('/hazards/2?return_to=/tasks')));
        self::assertNull(SafeWorkProcedureReturn::leaf($record));
        self::assertSame('/health-safety/procedures/11?section=steps&version=25&return_to='.rawurlencode($leaf), SafeWorkProcedureReturn::href(11, $leaf, 'steps', 25));
        self::assertSame('/health-safety/procedures/11?revision=working', SafeWorkProcedureReturn::href(11, '/hazards/2?return_to=/health-safety/procedures/11', null, null, true));
    }

    public function test_external_encoded_malformed_arrays_commands_and_oversized_origins_cannot_redirect(): void
    {
        foreach ([null, [], '', 'https://other.example/tasks', '//other.example/tasks', '%2F%2Fother.example/tasks', '/%2f%2fother.example/tasks', '/sites/0', '/sites/7/delete', '/sites/7#archive', '/sites/7\\other.example', '/sites/7%00', '/sites/7%2fdelete', '/hr/employees/3', '/health-safety/procedures/-3', '/health-safety/procedures/2/approve', '/tasks?'.str_repeat('q', 1500)] as $origin) {
            self::assertNull(SafeWorkProcedureReturn::sanitize($origin));
        }
        self::assertSame('/health-safety/procedures', SafeWorkProcedureReturn::sanitize('/health-safety/procedures?tab[]=approved&page=0&site_id=2abc&return_to=https://other.example'));
        self::assertSame('/health-safety/procedures/11', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/procedures/11?section[]=history&return_to[]=https://other.example&action=approve'));
        self::assertSame('/health-safety/procedures/11', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/procedures/11?return_to='.rawurlencode('%2F%2Fother.example/tasks')));
        self::assertSame('/health-safety/procedures', SafeWorkProcedureReturn::sanitize('/health-safety/procedures?from=2026-02-30&to=2026-10-01'));
        self::assertSame('/incidents?to=2026-10-01', SafeWorkProcedureReturn::sanitize('/incidents?from=2026-02-30&to=2026-10-01'));
    }
}
