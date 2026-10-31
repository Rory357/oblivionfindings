<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Support\HealthSafety\EmergencyDrillReturn;
use PHPUnit\Framework\TestCase;

class EmergencyDrillReturnTest extends TestCase
{
    public function test_register_filters_and_supported_site_tasks_sources_keep_only_read_context(): void
    {
        self::assertSame('/health-safety/drills?tab=findings&q=house%20exercise&period=all&drill_type=fire&outcome=failed&site_id=7&page=2', EmergencyDrillReturn::fromRegister(['tab' => 'findings', 'q' => 'house exercise', 'period' => 'all', 'drill_type' => 'fire', 'outcome' => 'failed', 'site_id' => 7, 'page' => 2, 'drill' => 5, 'action' => 'complete']));
        foreach (['/sites/7?tab=drills', '/tasks?bucket=open&assigned=me', '/my-day', '/health-safety/events/6?section=actions', '/health-safety/corrective-actions/4?section=evidence'] as $url) {
            self::assertSame($url, EmergencyDrillReturn::sanitize($url));
        }
    }

    public function test_record_sections_selected_finding_and_one_leaf_survive_without_cycles(): void
    {
        $origin = '/health-safety/drills?tab=findings&site_id=7';
        $record = '/health-safety/drills/5?section=findings&finding=8&return_to='.rawurlencode($origin);
        self::assertSame($record, EmergencyDrillReturn::href(5, $origin, 'findings', 8));
        self::assertSame($record, EmergencyDrillReturn::sanitize($record));
        self::assertSame('/health-safety/events/6?section=actions&return_to='.rawurlencode($origin), EmergencyDrillReturn::sanitize('/health-safety/events/6?section=actions&return_to='.rawurlencode($origin)));
        self::assertNull(EmergencyDrillReturn::leaf($record));
        self::assertSame('/health-safety/drills/5', EmergencyDrillReturn::sanitize('/health-safety/drills/5?return_to='.rawurlencode('/health-safety/events/6?return_to=/tasks')));
        self::assertSame('/health-safety/drills/5', EmergencyDrillReturn::href(5, '/health-safety/events/6?return_to=/health-safety/drills/5'));
    }

    public function test_actual_calendar_and_control_room_paths_preserve_one_supported_source_leaf(): void
    {
        self::assertSame('/calendar?view=week', EmergencyDrillReturn::sanitize('/calendar?view=week&action=schedule'));
        self::assertSame('/sites/7/calendar?date=2026-10-08', EmergencyDrillReturn::sanitize('/sites/7/calendar?date=2026-10-08'));
        self::assertNull(EmergencyDrillReturn::sanitize('/sites/calendar'));
        $list = '/control-room/alerts?lens=history&search=drill&severity=high&queue_id=7&assigned_to=me&status=closed&date_from=2026-10-01&date_to=2026-10-08&sort=triggered_at&dir=desc&page=2';
        $record = '/control-room/alerts/4?section=tasks&return_to='.rawurlencode($list);
        self::assertSame($list, EmergencyDrillReturn::sanitize($list));
        self::assertSame($record, EmergencyDrillReturn::sanitize($record));
        self::assertSame('/control-room/alerts/4', EmergencyDrillReturn::sanitize('/control-room/alerts/4?tab=tasks&section=history&return_to=https://example.com'));
        self::assertSame('/control-room/alerts', EmergencyDrillReturn::sanitize('/control-room/alerts?queue_id[]=7&assigned_to=0&date_from=2026-02-30&lens=unknown'));
        self::assertSame('/control-room/alerts/4', EmergencyDrillReturn::sanitize('/control-room/alerts/4?return_to='.rawurlencode('/control-room/alerts/4?section=tasks')));
    }

    public function test_calendar_view_state_roundtrips_both_pages_and_profile_without_commands_or_forged_fields(): void
    {
        $state = 'date=2025-04-06&view=week&sources=event%2Cdrill&site_id=2&q=Assembly%20review&color=owner&density=compact';
        foreach (['/calendar', '/sites/2/calendar'] as $path) {
            $origin = $path.'?'.$state;
            self::assertSame($origin, EmergencyDrillReturn::sanitize($origin));
            $href = EmergencyDrillReturn::href(8, $origin, 'findings', 12);
            self::assertSame($href, EmergencyDrillReturn::sanitize($href));
            self::assertSame($href, RecoverableTaskAuthorizationException::validatedReturnTo($href));
        }
        self::assertSame('/sites/2?tab=calendar&'.$state, EmergencyDrillReturn::sanitize('/sites/2?tab=calendar&'.$state));
        self::assertSame('/calendar?view=agenda&sources=none', EmergencyDrillReturn::sanitize('/calendar?view=agenda&sources=none'));
        self::assertSame('/calendar?sources=drill%2Cevent', EmergencyDrillReturn::sanitize('/calendar?sources=drill,event,drill'));
        self::assertSame('/calendar', EmergencyDrillReturn::sanitize('/calendar?date=2026-02-30&view=delete&sources=drill,../private&site_id=-2&q[]=private&action=create&return_to=%2Fusers&density=huge'));
        $tooMany = implode(',', array_map(fn ($i) => 'layer_'.$i, range(1, 21)));
        self::assertSame('/calendar', EmergencyDrillReturn::sanitize('/calendar?sources='.$tooMany));
        self::assertSame('/calendar', EmergencyDrillReturn::sanitize('/calendar?q='.str_repeat('x', 201)));
    }

    public function test_recoverable_destination_preserves_exact_finding_and_one_bounded_origin(): void
    {
        $href = EmergencyDrillReturn::href(5, '/health-safety/drills?tab=findings&site_id=7', 'findings', 8);
        self::assertSame($href, RecoverableTaskAuthorizationException::validatedReturnTo($href));
        self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo('//example.com/health-safety/drills/5'));
        self::assertSame('/health-safety/drills/5', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/drills/5?return_to='.rawurlencode('/health-safety/events/6?return_to=/tasks')));
    }

    public function test_untrusted_or_malformed_navigation_does_not_create_a_command_or_redirect(): void
    {
        foreach ([null, [], '', 'https://example.com/tasks', '//example.com/tasks', '%2F%2Fexample.com/tasks', '/%2f%2fexample.com/tasks', '/health-safety/drills/0', '/health-safety/drills/2/complete', '/health-safety/drills/2#complete', '/sites/7\\example.com', '/health-safety/drills?'.str_repeat('x', 1500)] as $url) {
            self::assertNull(EmergencyDrillReturn::sanitize($url));
        }
        self::assertSame('/health-safety/drills', EmergencyDrillReturn::sanitize('/health-safety/drills?tab[]=findings&period=yesterday&site_id=0&outcome=successful&action=complete'));
        self::assertSame('/health-safety/drills/5', EmergencyDrillReturn::sanitize('/health-safety/drills/5?section[]=run&finding=-1&return_to[]=https://example.com'));
    }
}
