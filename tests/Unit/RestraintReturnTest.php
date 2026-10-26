<?php

namespace Tests\Unit;

use App\Support\HealthSafety\RestraintReturn;
use PHPUnit\Framework\TestCase;

class RestraintReturnTest extends TestCase
{
    public function test_filtered_register_return_preserves_both_pages_and_discards_old_modal_or_command_keys(): void
    {
        $this->assertSame('/health-safety/restraints?lens=plans&tab=review_due&client_id=12&q=recorded%20plan&events_page=2&plans_page=4', RestraintReturn::fromRegister([
            'lens' => 'plans', 'tab' => 'review_due', 'client_id' => 12, 'q' => 'recorded plan', 'events_page' => 2,
            'plans_page' => 4, 'plan' => 55, 'approve' => 1, 'idempotency_key' => 'secret-command-identity',
        ]));
    }

    public function test_event_and_plan_section_keys_do_not_cross_into_other_source_commands(): void
    {
        $this->assertSame('/health-safety/restraints/events/7?section=followups&followup=2', RestraintReturn::event(7, null, 'followups', 2));
        $this->assertSame('/health-safety/restraints/plans/8?section=versions&version=3', RestraintReturn::plan(8, null, 'versions', 3));
        $this->assertSame('/health-safety/restraints/plans/8', RestraintReturn::plan(8, null, 'monitoring'));
        $this->assertSame('/health-safety/restraints/events/7', RestraintReturn::sanitize('/health-safety/restraints/events/7?section=approval&version=3&action=approve'));
    }

    public function test_one_source_leaf_survives_but_deeper_nesting_and_same_record_cycles_do_not(): void
    {
        $source = '/health-safety/restraints/events/7?section=connections&return_to='.rawurlencode('/tasks?assigned=me');
        $this->assertSame($source, RestraintReturn::sanitize($source));
        $this->assertNull(RestraintReturn::leaf($source));
        $this->assertSame('/health-safety/restraints/plans/8', RestraintReturn::plan(8, '/health-safety/restraints/events/7?return_to='.rawurlencode('/health-safety/restraints/plans/8')));
        $this->assertSame('/health-safety/restraints/events/7', RestraintReturn::sanitize('/health-safety/restraints/events/7?return_to='.rawurlencode('/health-safety/restraints/events/8?return_to=/tasks')));
    }

    public function test_external_command_control_and_out_of_range_record_origins_are_rejected(): void
    {
        foreach (['https://example.com/health-safety/restraints', '//example.com/tasks', '/health-safety/restraints\\events\\1',
            '/health-safety/restraints/events/9007199254740992', '/health-safety/restraints/events/1/attachments/2/download',
            '/health-safety/restraints/plans/1/activate', "/tasks\n", '/tasks#private'] as $value) {
            $this->assertNull(RestraintReturn::sanitize($value), $value);
        }
    }

    public function test_invalid_dates_and_encoded_control_keys_do_not_survive_register_return(): void
    {
        $this->assertSame('/health-safety/restraints?to=2026-10-11', RestraintReturn::sanitize('/health-safety/restraints?from=2026-02-31&to=2026-10-11&q=%0Aprivate&client_id=0&site_id=9007199254740992'));
    }

    public function test_canonical_person_respite_and_clinical_origins_keep_their_read_context_only(): void
    {
        $this->assertSame('/operations/clients/12?tab=observations', RestraintReturn::sanitize('/operations/clients/12?tab=observations&action=approve&idempotency_key=private'));
        $this->assertSame('/respite/stays/21', RestraintReturn::sanitize('/respite/stays/21?kind=plan&command_id=private'));
        $this->assertSame('/respite?tab=stays', RestraintReturn::sanitize('/respite?tab=stays&command_id=private'));
        $clinical = '/health-clinical/behaviour?client_id=12&site_id=3&date_from=2026-10-01&date_to=2026-10-12&view=restraint';
        $this->assertSame($clinical, RestraintReturn::sanitize($clinical.'&action=review&receipt_key=private'));
        $this->assertSame('/health-safety/restraints/events/7?return_to='.rawurlencode('/respite/stays/21'), RestraintReturn::event(7, '/respite/stays/21'));
        $this->assertSame('/health-safety/restraints/plans/8?return_to='.rawurlencode('/operations/clients/12?tab=observations'), RestraintReturn::plan(8, '/operations/clients/12?tab=observations'));
    }

    public function test_source_leaves_reject_commands_overflow_and_a_second_return(): void
    {
        foreach (['/respite/stays/21/extend', '/respite/stays/21/restraints/report', '/respite/stays/9007199254740992',
            '/operations/clients/9007199254740992?tab=observations', '/health-clinical/behaviour/approve'] as $value) {
            $this->assertNull(RestraintReturn::sanitize($value), $value);
        }
        $this->assertNull(RestraintReturn::leaf('/respite/stays/21?return_to=/tasks'));
        $this->assertSame('/health-clinical/behaviour?view=restraint', RestraintReturn::sanitize('/health-clinical/behaviour?client_id=0&date_from=2026-02-31&view=restraint'));
    }
}
