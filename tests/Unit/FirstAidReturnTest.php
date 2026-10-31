<?php

namespace Tests\Unit;

use App\Exceptions\RecoverableTaskAuthorizationException;
use App\Support\HealthSafety\FirstAidReturn;
use PHPUnit\Framework\TestCase;

class FirstAidReturnTest extends TestCase
{
    public function test_register_keeps_supported_filters_and_removes_command_and_legacy_record_selection(): void
    {
        self::assertSame('/health-safety/first-aid?tab=followup&site_id=2&treated_person_type=client&treatment_outcome=ongoing_monitoring&first_aider_id=8&period=week&q=wound%20check&page=3', FirstAidReturn::fromRegister([
            'tab' => 'followup', 'site_id' => 2, 'treated_person_type' => 'client', 'treatment_outcome' => 'ongoing_monitoring', 'first_aider_id' => 8, 'period' => 'week', 'q' => 'wound check', 'page' => 3, 'record' => 99, 'action' => 'archive',
        ]));
        self::assertSame('/health-safety/first-aid', FirstAidReturn::sanitize('/health-safety/first-aid?tab[]=all&site_id=0&period=tomorrow&treatment_outcome=safe&first_aider_id=-2'));
    }

    public function test_exact_followup_context_and_one_supported_leaf_survive_destination_returns(): void
    {
        $origin = '/health-safety/first-aid?tab=followup&site_id=2&period=all';
        $url = FirstAidReturn::href(5, $origin, 'followups', 8);
        self::assertSame('/health-safety/first-aid/5?section=followups&followup=8&return_to='.rawurlencode($origin), $url);
        self::assertSame($url, FirstAidReturn::sanitize($url));
        self::assertNull(FirstAidReturn::leaf($url));
        self::assertSame('/health-safety/events/3?section=actions&return_to='.rawurlencode($origin), FirstAidReturn::sanitize('/health-safety/events/3?section=actions&return_to='.rawurlencode($origin)));
        self::assertSame('/health-safety/first-aid/5', FirstAidReturn::href(5, '/control-room/alerts/7?return_to=/health-safety/first-aid/5'));
        self::assertSame('/health-safety/first-aid/5', FirstAidReturn::sanitize('/health-safety/first-aid/5?return_to='.rawurlencode('/health-safety/events/3?return_to=/tasks')));
    }

    public function test_actual_profile_task_incident_and_source_origins_are_read_context_only(): void
    {
        foreach (['/sites/2?tab=first_aid', '/operations/clients/8?tab=first_aid', '/my-day', '/tasks?bucket=open&assigned=me', '/incidents/7?section=evidence', '/health-safety/lone-workers/sessions/3?section=history', '/control-room/alerts/4?section=tasks', '/health-safety/drills/7?section=findings'] as $origin) {
            self::assertSame($origin, FirstAidReturn::sanitize($origin));
        }
        self::assertSame('/sites/2?tab=first_aid', FirstAidReturn::sanitize('/sites/2?tab=first_aid&client_id=99&action=create'));
        self::assertSame('/health-safety/first-aid/5?section=injury', FirstAidReturn::sanitize('/health-safety/first-aid/5?section=injury&followup=0&action=edit'));
    }

    public function test_no_external_fragment_array_protocol_or_unsafe_numeric_redirect_is_accepted(): void
    {
        foreach ([null, [], '', 'https://example.com/tasks', '//example.com/tasks', '%2F%2Fexample.com/tasks', '/%2f%2fexample.com/tasks', '/health-safety/first-aid/0', '/health-safety/first-aid/9007199254740992', '/health-safety/first-aid/2/archive', '/health-safety/first-aid/2#injury', '/my-day'.chr(92).'private', "/my-day\n", '/tasks?'.str_repeat('x', 1500)] as $origin) {
            self::assertNull(FirstAidReturn::sanitize($origin));
        }
        self::assertSame('/health-safety/first-aid/5', FirstAidReturn::sanitize('/health-safety/first-aid/5?section[]=history&followup[]=4&return_to[]=https://example.com'));
    }

    public function test_shared_destination_reader_accepts_only_bounded_first_aid_context_without_changing_lone_worker_returns(): void
    {
        $href = FirstAidReturn::href(5, '/tasks?bucket=open', 'followups', 8);
        self::assertSame($href, RecoverableTaskAuthorizationException::validatedReturnTo($href));
        self::assertSame('/health-safety/first-aid?tab=followup', RecoverableTaskAuthorizationException::validatedReturnTo('/health-safety/first-aid?tab=followup&record=2'));
        self::assertNull(RecoverableTaskAuthorizationException::validatedReturnTo('https://example.com/health-safety/first-aid/5'));
        $existing = '/health-safety/lone-workers/sessions/3?section=history&return_to='.rawurlencode('/health-safety/lone-workers?tab=sessions&status=active');
        self::assertSame($existing, RecoverableTaskAuthorizationException::validatedReturnTo($existing));
    }

    public function test_exact_dashboard_capture_origins_strip_every_query_without_accepting_other_paths(): void
    {
        foreach (['/health-safety', '/dashboard'] as $origin) {
            $untrusted = $origin.'?action=archive&record=99&return_to=https%3A%2F%2Fexample.com&tab%5B%5D=private';
            self::assertSame($origin, FirstAidReturn::sanitize($untrusted));
            self::assertSame($origin, FirstAidReturn::leaf($untrusted));
            $href = FirstAidReturn::href(5, $untrusted);
            self::assertSame('/health-safety/first-aid/5?return_to='.rawurlencode($origin), $href);
            self::assertSame($href, RecoverableTaskAuthorizationException::validatedReturnTo($href));
        }
        foreach (['/dashboard/', '/dashboard/edit', '/health-safety/', '/health-safety-other', '/health-safety/reports'] as $origin) {
            self::assertNull(FirstAidReturn::sanitize($origin));
        }
    }

    public function test_self_cycles_and_recursive_leaf_cannot_recover_a_command_context(): void
    {
        self::assertSame('/health-safety/first-aid/5', FirstAidReturn::href(5, '/health-safety/first-aid/5?section=history'));
        self::assertNull(FirstAidReturn::leaf('/tasks?return_to=/health-safety/first-aid'));
        self::assertSame('/health-safety/first-aid/5?section=connections', FirstAidReturn::sanitize('/health-safety/first-aid/5?section=connections&return_to='.rawurlencode('/health-safety/first-aid/5?section=history')));
    }
}
