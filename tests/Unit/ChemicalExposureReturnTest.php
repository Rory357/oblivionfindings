<?php

namespace Tests\Unit;

use App\Support\HealthSafety\ChemicalExposureReturn as ExposureReturn;
use PHPUnit\Framework\TestCase;

final class ChemicalExposureReturnTest extends TestCase
{
    public function test_private_register_retains_only_read_filters_and_zoned_real_calendar_instants(): void
    {
        $this->assertSame(ExposureReturn::BASE.'?site_id=8&worker_id=9&substance_id=10&from=2026-10-12T00%3A00%3A00Z&page=2',
            ExposureReturn::sanitize(ExposureReturn::BASE.'?site_id=8&worker_id=9&substance_id=10&from=2026-10-12T00:00:00Z&page=2&receipt_key=private&action=correct&return_to=%2Fmy-day'));
        $this->assertSame(ExposureReturn::BASE, ExposureReturn::sanitize(ExposureReturn::BASE.'?from=2026-02-30T00:00:00Z&to=2026-10-12T00:00:00%2B14:01&page=2147483648'));
    }
    public function test_record_back_preserves_section_and_all_four_read_pages_without_action_child_or_receipt_selectors(): void
    {
        $this->assertSame(ExposureReturn::BASE.'/18?section=evidence&history_page=2&reviews_page=3&followups_page=4&evidence_page=5',
            ExposureReturn::sanitize(ExposureReturn::BASE.'/18?section=evidence&history_page=2&reviews_page=3&followups_page=4&evidence_page=5&action=remove_evidence&evidence=999&followup=888&receipt_key=private&return_to=%2Fmy-day'));
        $this->assertSame(ExposureReturn::BASE.'/18', ExposureReturn::sanitize(ExposureReturn::BASE.'/18?section=unknown&evidence_page=0&reviews_page=-1&history_page=2147483648'));
    }
    public function test_public_chemical_back_retains_consumed_register_filters_and_exact_record_sections(): void
    {
        $this->assertSame('/health-safety/substances?tab=controlled&q=bleach&site_id=4&physical_form=liquid&status=active&controlled=controlled&sds_state=expiring&period=30&page=3',
            ExposureReturn::sanitize('/health-safety/substances?tab=controlled&q=bleach&site_id=4&physical_form=liquid&status=active&controlled=controlled&sds_state=expiring&period=30&page=3&action=edit&receipt_key=private'));
        foreach (['overview', 'controls', 'sds', 'storage', 'history', 'exposures'] as $section) {
            $this->assertSame('/health-safety/substances/24?section='.$section,
                ExposureReturn::sanitize('/health-safety/substances/24?section='.$section.'&action=exposure&return_to=%2Fmy-day'));
        }
        $this->assertSame('/health-safety/substances', ExposureReturn::sanitize('/health-safety/substances?period=6&tab=unknown&controlled=1&q='.str_repeat('x', 201)));
    }
    public function test_unknown_array_keys_and_malformed_or_external_navigation_are_rejected_as_a_whole(): void
    {
        foreach ([null, [], 'https://example.test/health-safety/substances', '//example.test/health-safety/substances',
            '/health-safety/substances#private', '/health-safety/substances?unknown%5B%5D=1', '/health-safety/substances?q=%ZZ',
            '/health-safety/substances?q=%00', '/health-safety/substances?q=%5Cserver', '/health-safety/substances?q=literal space',
            '/health-safety/substances?q='.str_repeat('a', 1501)] as $unsafe) { $this->assertNull(ExposureReturn::sanitize($unsafe)); }
    }
    public function test_duplicate_scalar_and_encoded_aliases_do_not_choose_ambiguous_values(): void
    {
        $this->assertSame(ExposureReturn::BASE.'?site_id=4', ExposureReturn::sanitize(ExposureReturn::BASE.'?page=1&%70age=2&site_id=4'));
        $this->assertSame('/health-safety/substances/5', ExposureReturn::sanitize('/health-safety/substances/5?section=sds&section=history'));
    }
    public function test_controlled_command_destination_contains_no_self_back_loop_and_capture_preserves_filtered_origin(): void
    {
        $this->assertSame(ExposureReturn::BASE.'/7?section=reviews&action=review',
            ExposureReturn::record(7, ExposureReturn::BASE.'/7?section=history', 'reviews', 'review'));
        $destination = ExposureReturn::capture(8, '/health-safety/substances?site_id=4&page=2&receipt_key=private');
        parse_str(parse_url($destination, PHP_URL_QUERY), $query);
        $this->assertSame('8', $query['substance_id']); $this->assertSame('/health-safety/substances?site_id=4&page=2', $query['return_to']);
        $this->assertStringNotContainsString('private', $destination);
    }
    public function test_reassignment_bookmark_is_navigation_only_and_read_return_strips_child_and_command(): void
    {
        $this->assertSame(ExposureReturn::BASE.'/7?section=followups&action=reassign_followup&return_to=%2Ftasks',
            ExposureReturn::record(7, '/tasks', 'followups', 'reassign_followup'));
        $this->assertSame(ExposureReturn::BASE.'/7?section=followups',
            ExposureReturn::sanitize(ExposureReturn::BASE.'/7?section=followups&action=reassign_followup&followup=8&receipt_key=private'));
    }
}
