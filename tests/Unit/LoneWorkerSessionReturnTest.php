<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Support\HealthSafety\LoneWorkerSessionReturn;
use PHPUnit\Framework\TestCase;

class LoneWorkerSessionReturnTest extends TestCase
{
    public function test_register_preserves_actual_filters_and_strips_commands_and_legacy_selection(): void
    {
        self::assertSame('/health-safety/lone-workers?tab=sessions&q=home%20visit&site_id=2&user_id=8&status=overdue&period=today&page=3', LoneWorkerSessionReturn::fromRegister(['tab' => 'sessions', 'q' => 'home visit', 'site_id' => 2, 'user_id' => 8, 'status' => 'overdue', 'period' => 'today', 'page' => 3, 'session' => 5, 'action' => 'end']));
        self::assertSame('/health-safety/lone-workers', LoneWorkerSessionReturn::sanitize('/health-safety/lone-workers?tab[]=alerts&user_id=-1&period=yesterday&status=safe'));
    }

    public function test_filtered_alert_register_is_a_recoverable_leaf_with_canonical_statuses_only(): void
    {
        foreach (['open', 'ack', 'triaging', 'confirmed', 'resolved', 'closed', 'dismissed'] as $status) {
            $url = '/health-safety/lone-workers?tab=alerts&q=home&site_id=2&user_id=8&status='.$status.'&period=all&page=3';
            self::assertSame($url, RecoverableTaskAuthorizationException::validatedReturnTo($url));
        }
        self::assertSame('/health-safety/lone-workers?tab=alerts', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/lone-workers?tab=alerts&status=acknowledged&alert=cr_7&action=resolve'));
        self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/lone-workers?return_to=%2Ftasks'));
        foreach (['https://example.com/health-safety/lone-workers', '//example.com/health-safety/lone-workers', '/health-safety/lone-workers#private'] as $url) {
            self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo($url));
        }
    }

    public function test_actual_tracking_people_shift_and_response_origins_are_bounded_read_context(): void
    {
        foreach (['/security-devices/tracking?tab=history&attention=stale', '/operations/people-locations/map?population=staff&site=2&selected=s8&source=5', '/operations/shifts/7', '/control-room/alerts/3?section=tasks', '/my-day', '/tasks?bucket=open&assigned=me', '/calendar?view=week', '/sites/2?tab=calendar'] as $url) {
            self::assertSame($url, LoneWorkerSessionReturn::sanitize($url));
        }
        self::assertSame('/security-devices/tracking', LoneWorkerSessionReturn::sanitize('/security-devices/tracking?tab=private&attention=delete&device=2&action=locate'));
        self::assertSame('/operations/shifts/7', LoneWorkerSessionReturn::sanitize('/operations/shifts/7?section=private&action=clockout'));
    }

    public function test_exact_section_and_one_leaf_survive_recoverable_destination_without_cycles(): void
    {
        $leaf = '/health-safety/lone-workers?tab=alerts&site_id=2';
        $url = LoneWorkerSessionReturn::href(5, $leaf, 'response');
        self::assertSame('/health-safety/lone-workers/sessions/5?section=response&return_to='.rawurlencode($leaf), $url);
        self::assertSame($url, LoneWorkerSessionReturn::sanitize($url));
        self::assertSame($url, RecoverableTaskAuthorizationException::validatedReturnTo($url));
        self::assertNull(LoneWorkerSessionReturn::leaf($url));
        self::assertSame('/control-room/alerts/3?section=tasks&return_to='.rawurlencode($leaf), LoneWorkerSessionReturn::sanitize('/control-room/alerts/3?section=tasks&return_to='.rawurlencode($leaf)));
        self::assertSame('/health-safety/lone-workers/sessions/5', LoneWorkerSessionReturn::href(5, '/control-room/alerts/3?return_to=/health-safety/lone-workers/sessions/5'));
        self::assertSame('/health-safety/lone-workers/sessions/5', LoneWorkerSessionReturn::sanitize('/health-safety/lone-workers/sessions/5?return_to='.rawurlencode('/control-room/alerts/3?return_to=/tasks')));
    }

    public function test_untrusted_or_malformed_origins_never_become_redirects(): void
    {
        foreach ([null, [], '', 'https://example.com/my-day', '//example.com/my-day', '%2F%2Fexample.com/my-day', '/%2f%2fexample.com/my-day', '/health-safety/lone-workers/sessions/0', '/health-safety/lone-workers/sessions/5/end', '/health-safety/lone-workers/sessions/5#end', '/my-day\\private', "/my-day\n", '/health-safety/lone-workers?'.str_repeat('x', 1500)] as $url) {
            self::assertNull(LoneWorkerSessionReturn::sanitize($url));
        }
        self::assertSame('/health-safety/lone-workers/sessions/5', LoneWorkerSessionReturn::sanitize('/health-safety/lone-workers/sessions/5?section[]=location&return_to[]=https://example.com'));
    }
}
