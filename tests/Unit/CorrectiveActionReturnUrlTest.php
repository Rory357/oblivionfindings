<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use PHPUnit\Framework\TestCase;

class CorrectiveActionReturnUrlTest extends TestCase
{
    public function test_action_return_preserves_only_a_section_and_never_an_automatic_command(): void
    {
        self::assertSame('/health-safety/corrective-actions/42?section=evidence', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/corrective-actions/42?section=evidence&action=verify&return_to=https://other.example'));
        self::assertSame('/tasks?sources=alert&bucket=in_progress', RecoverableTaskAuthorizationException::validatedReturnTo('/tasks?sources=alert&bucket=in_progress'));
    }

    public function test_external_and_non_record_returns_are_not_accepted(): void
    {
        foreach (['https://other.example/health-safety/corrective-actions/42', '//other.example/health-safety/corrective-actions/42', '/health-safety/corrective-actions/0', '/health-safety/corrective-actions/42/complete', '/health-safety/corrective-actions/42#execute', '/health-safety/corrective-actions'] as $value) {
            self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo($value), $value);
        }
    }
}
