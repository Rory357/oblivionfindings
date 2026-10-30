<?php

namespace Tests\Unit;

use App\Support\HealthSafety\InjuryReturn;
use App\Support\HealthSafety\FirstAidReturn;
use App\Support\HealthSafety\RestraintReturn;
use PHPUnit\Framework\TestCase;

class InjuryReturnTest extends TestCase
{
    public function test_filtered_register_keeps_safe_search_dates_and_page_without_modal_command_or_receipt(): void
    {
        $this->assertSame('/health-safety/injuries?tab=under_treatment&q=shoulder%20injury&site_id=2&period=30d&from=2026-10-01&page=4',
            InjuryReturn::fromRegister(['tab' => 'under_treatment', 'q' => 'shoulder injury', 'site_id' => 2, 'period' => '30d',
                'from' => '2026-10-01', 'page' => 4, 'injury' => 8, 'action' => 'close', 'receipt_key' => 'private']));
    }
    public function test_record_section_and_explicit_command_are_bounded_and_origins_strip_commands(): void
    {
        $this->assertSame('/health-safety/injuries/8?section=recovery&action=edit_rtw&plan=2', InjuryReturn::record(8, null, 'rtw', ['plan' => 2], 'edit_rtw'));
        $this->assertSame('/health-safety/injuries/8?section=evidence', InjuryReturn::sanitize('/health-safety/injuries/8?section=evidence&attachment=9&action=remove_evidence&receipt_key=private'));
        $this->assertSame('/health-safety/injuries/8', InjuryReturn::record(8, null, 'approval', ['plan' => -1], 'approve_restrictions'));
    }
    public function test_hr_injury_profile_and_wellbeing_keep_actual_read_context(): void
    {
        $this->assertSame('/hr/people/12?tab=injuries', InjuryReturn::sanitize('/hr/people/12?tab=injuries&action=close&idempotency_key=private'));
        $this->assertSame('/hr/people/12', InjuryReturn::sanitize('/hr/people/12?tab=medical-clearance'));
        $this->assertSame('/hr/wellbeing', InjuryReturn::sanitize('/hr/wellbeing?action=approve'));
        $this->assertSame('/health-safety/injuries/8?return_to='.rawurlencode('/hr/people/12?tab=injuries'), InjuryReturn::record(8, '/hr/people/12?tab=injuries'));
    }
    public function test_one_current_source_origin_is_retained_but_deeper_nesting_and_cycles_are_removed(): void
    {
        $leaf = '/incidents/3?return_to='.rawurlencode('/tasks?assigned=me');
        $this->assertSame($leaf, InjuryReturn::sanitize($leaf));
        $this->assertNull(InjuryReturn::leaf($leaf));
        $this->assertSame('/health-safety/injuries/8', InjuryReturn::record(8, '/incidents/3?return_to='.rawurlencode('/health-safety/injuries/8')));
        $this->assertSame('/health-safety/injuries/8', InjuryReturn::sanitize('/health-safety/injuries/8?return_to='.rawurlencode('/incidents/3?return_to=/tasks')));
    }
    public function test_external_paths_downloads_commands_normalised_paths_and_overflow_ids_are_rejected(): void
    {
        foreach (['https://example.com/tasks', '//example.com/tasks', '/health-safety/injuries/1/attachments/2/download',
            '/health-safety/injuries/1/status', '/health-safety/injuries/9007199254740992', '/hr/people/9007199254740992',
            '/health-safety/injuries/01', '/health-safety/injuries/../injuries/1', '/health-safety/injuries/%31',
            '/health-safety/injuries\\1', "/tasks\n", '/tasks#private'] as $value) {
            $this->assertNull(InjuryReturn::sanitize($value), $value);
        }
        $this->assertSame(InjuryReturn::REGISTER, InjuryReturn::record(0));
    }
    public function test_ambiguous_duplicate_values_invalid_dates_and_encoded_controls_are_removed(): void
    {
        $this->assertSame('/health-safety/injuries?to=2026-10-12', InjuryReturn::sanitize('/health-safety/injuries?from=2026-02-31&to=2026-10-12&q=%0Aprivate&page=2&page=3'));
        $this->assertSame('/health-safety/injuries/8', InjuryReturn::sanitize('/health-safety/injuries/8?plan=2&plan=3&return_to=/tasks&return_to=/hr/wellbeing'));
    }
    public function test_utf8_byte_limit_and_decoded_filter_limit_are_enforced(): void
    {
        $this->assertNull(InjuryReturn::sanitize('/health-safety/injuries?q='.str_repeat('ā', 750)));
        $this->assertSame('/health-safety/injuries', InjuryReturn::sanitize('/health-safety/injuries?q='.rawurlencode(str_repeat('ā', 101))));
    }
    public function test_actual_first_aid_and_restraint_helpers_reciprocally_recognise_only_literal_injury_read_leaves(): void
    {
        foreach ([FirstAidReturn::class, RestraintReturn::class] as $helper) {
            $this->assertSame('/health-safety/injuries?tab=under_treatment&page=2',
                $helper::sanitize('/health-safety/injuries?tab=under_treatment&page=2&injury=8&action=close&receipt_key=private'));
            $this->assertSame('/health-safety/injuries/8?section=recovery&plan=2',
                $helper::sanitize('/health-safety/injuries/8?section=rtw&plan=2&attachment=7&action=edit&receipt_key=private'));
            $this->assertSame('/health-safety/injuries/8', $helper::sanitize('/health-safety/injuries/8?plan=2&plan=3'));
            foreach (['/health-safety/injuries/create', '/health-safety/injuries/8/status',
                '/health-safety/injuries/8/attachments/2/download', '/health-safety/injuries/9007199254740992'] as $unsafe) {
                $this->assertNull($helper::sanitize($unsafe));
            }
        }
        $injury = '/health-safety/injuries/8?section=connections';
        $this->assertSame('/health-safety/first-aid/5?return_to='.rawurlencode($injury), FirstAidReturn::href(5, $injury));
        $this->assertSame('/health-safety/restraints/events/7?return_to='.rawurlencode($injury), RestraintReturn::event(7, $injury));
        $this->assertSame('/health-safety/injuries/8?return_to='.rawurlencode('/health-safety/first-aid/5'), InjuryReturn::record(8, '/health-safety/first-aid/5'));
        $this->assertSame('/health-safety/injuries/8?return_to='.rawurlencode('/incidents/3'), InjuryReturn::record(8, '/incidents/3'));
    }

    public function test_actual_reciprocal_helpers_keep_one_tasks_origin_and_remove_recursive_or_same_record_cycles(): void
    {
        $injury = '/health-safety/injuries/8?return_to='.rawurlencode('/tasks?assigned=me');
        foreach ([FirstAidReturn::class, RestraintReturn::class] as $helper) {
            $this->assertSame($injury, $helper::sanitize($injury));
            $this->assertNull($helper::leaf($injury));
            $this->assertSame('/health-safety/injuries/8', $helper::sanitize('/health-safety/injuries/8?return_to='
                .rawurlencode('/health-safety/first-aid/5?return_to=/tasks')));
            $this->assertSame('/health-safety/injuries/8', $helper::sanitize('/health-safety/injuries/8?return_to='
                .rawurlencode('/health-safety/injuries/8?section=history')));
        }
        $this->assertSame('/health-safety/first-aid/5', FirstAidReturn::href(5,
            '/health-safety/injuries/8?return_to='.rawurlencode('/health-safety/first-aid/5')));
        $this->assertSame('/health-safety/restraints/events/7', RestraintReturn::event(7,
            '/health-safety/injuries/8?return_to='.rawurlencode('/health-safety/restraints/events/7')));
        $hs = '/health-safety/events/3?return_to='.rawurlencode('/health-safety/injuries/8?section=connections');
        $this->assertSame($hs, FirstAidReturn::sanitize($hs));
        $this->assertSame('/health-safety/injuries/8?return_to='.rawurlencode('/hr/people/12?tab=injuries'),
            InjuryReturn::record(8, '/hr/people/12?tab=injuries'));
    }
    public function test_current_operational_and_source_destinations_keep_only_read_navigation(): void
    {
        foreach (['/control-room/alerts/4', '/health-safety/events/5', '/health-safety/first-aid/6',
            '/health-safety/corrective-actions/7', '/operations/shifts/8'] as $path) {
            $this->assertSame($path, InjuryReturn::sanitize($path.'?action=close&receipt_key=private'));
        }
        $this->assertSame('/health-safety/restraints?lens=events', InjuryReturn::sanitize('/health-safety/restraints?lens=events&command=approve'));
    }
}
