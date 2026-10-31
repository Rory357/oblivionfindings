<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use PHPUnit\Framework\TestCase;

class HazardReturnUrlTest extends TestCase
{
    public function test_hazard_return_preserves_section_and_one_sanitized_origin_without_automatic_commands(): void
    {
        $origin = '/compliance/hazards?tab=overdue&q=mat&page=2&action=close';
        $value = '/hazards/42?section=connections&action=close&return_to='.rawurlencode($origin);
        self::assertSame('/hazards/42?section=connections&return_to='.rawurlencode('/compliance/hazards?tab=overdue&q=mat&page=2'), RecoverableTaskAuthorizationException::validatedReturnTo($value));
        foreach (['/sites/7/hazards?tab=open', '/sites/7?section=safety', '/operations/clients/9?section=safety', '/my-day', '/tasks?sources=hazard&done=true'] as $origin) {
            self::assertSame('/hazards/42?return_to='.rawurlencode($origin), RecoverableTaskAuthorizationException::validatedReturnTo('/hazards/42?return_to='.rawurlencode($origin)));
        }
    }

    public function test_external_encoded_protocol_and_malformed_hazard_targets_are_rejected(): void
    {
        foreach (['https://other.example/hazards/42', '//other.example/hazards/42', '%2F%2Fother.example/hazards/42', '/%2f%2fother.example/hazards/42', '/hazards/0', '/hazards/-2', '/hazards/42/close', '/hazards/42#execute', '/hazards/42\\other.example', '/hazards/42%00', '/hazards/42%2Fclose'] as $value) {
            self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo($value), $value);
        }
    }

    public function test_nested_external_recursive_and_array_origins_never_survive(): void
    {
        foreach (['https://other.example/tasks', '//other.example/tasks', '%2F%2Fother.example/tasks', '/hazards/99?return_to=/tasks', '/sites/0/hazards', '/tasks#execute'] as $origin) {
            self::assertSame('/hazards/42', RecoverableTaskAuthorizationException::validatedReturnTo('/hazards/42?return_to='.rawurlencode($origin)), $origin);
        }
        self::assertSame('/hazards/42', RecoverableTaskAuthorizationException::validatedReturnTo('/hazards/42?return_to[]=https://other.example&section[]=close'));
        $recursive = '/tasks?return_to='.rawurlencode('https://other.example').'&action=close&page=0';
        self::assertSame('/hazards/42?return_to=%2Ftasks', RecoverableTaskAuthorizationException::validatedReturnTo('/hazards/42?return_to='.rawurlencode($recursive)));
    }
}
