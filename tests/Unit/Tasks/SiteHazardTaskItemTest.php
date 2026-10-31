<?php

namespace Tests\Unit\Tasks;

use App\Services\Tasks\SiteHazardTaskItem;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SiteHazardTaskItemTest extends TestCase
{
    private Container $previousContainer;

    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $container = new Container;
        $container->instance('config', new Repository(['app' => ['worker_timezone' => 'Pacific/Auckland']]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->previousTimezone);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_due_today_remains_on_time_through_the_final_fraction_of_the_nz_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 23:59:59.999999', 'Pacific/Auckland'));

        $this->assertFalse($this->item()->isOverdue());
        $this->assertFalse($this->item('in_progress')->toArray()['overdue']);
    }

    public function test_due_yesterday_becomes_overdue_at_nz_midnight_even_when_utc_is_the_previous_date(): void
    {
        $item = $this->item();
        Carbon::setTestNow(Carbon::parse('2026-10-08 23:59:59.999999', 'Pacific/Auckland'));
        $this->assertFalse($item->isOverdue());
        Carbon::setTestNow(Carbon::parse('2026-10-09 00:00:00', 'Pacific/Auckland'));

        $this->assertTrue($item->isOverdue());
        $this->assertTrue($this->item('in_progress')->isOverdue());
    }

    public function test_mitigated_controls_await_closure_without_becoming_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 00:00:00', 'Pacific/Auckland'));

        $this->assertFalse($this->item('mitigated')->isOverdue());
    }

    public function test_closed_hazards_are_not_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 00:00:00', 'Pacific/Auckland'));

        $this->assertFalse($this->item('closed')->isOverdue());
    }

    public function test_foreign_default_timezone_and_offset_do_not_replace_the_literal_source_due_date(): void
    {
        date_default_timezone_set('America/Los_Angeles');
        Carbon::setTestNow(Carbon::parse('2026-10-08 23:59:59.999999', 'Pacific/Auckland'));
        // Even a transported timestamp with another offset retains the canonical YYYY-MM-DD prefix.
        $this->assertFalse($this->item(dueAt: '2026-10-08T23:59:59+14:00')->isOverdue());
        Carbon::setTestNow(Carbon::parse('2026-10-08 11:00:00', 'UTC'));
        $this->assertTrue($this->item()->isOverdue());
    }

    public function test_a_hazard_without_a_control_due_date_is_not_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 00:00:00', 'Pacific/Auckland'));

        $this->assertFalse($this->item(dueAt: null)->isOverdue());
    }

    private function item(string $status = 'open', ?string $dueAt = '2026-10-08T23:59:59+13:00'): SiteHazardTaskItem
    {
        return new SiteHazardTaskItem(
            id: 'hazard-1', source: 'hazard', sourceLabel: 'Hazards', ref: 'HAZ-1',
            title: 'Wet floor', status: $status, bucket: $status === 'closed' ? 'done' : 'in_progress',
            severity: 'medium', dueAt: $dueAt,
        );
    }
}
