<?php

namespace Tests\Unit;

use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\Medication\Controlled\ControlledSettingsFragment;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class ControlledPolicyTest extends TestCase
{
    public function test_witness_defaults_and_explicit_house_choices_follow_the_approved_policy(): void
    {
        $policy = $this->policy();
        $this->assertTrue($policy->doseWitnessRequiredAt(4));
        $policy->organisation[ControlledPolicy::WITNESS_REQUIRED] = 'off';
        $this->assertFalse($policy->doseWitnessRequiredAt(4), 'An unconfigured house follows the organisation.');
        $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'on';
        $this->assertTrue($policy->doseWitnessRequiredAt(4), 'A house may require a witness when the default is off.');
        $policy->organisation[ControlledPolicy::WITNESS_REQUIRED] = 'on';
        $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'off';
        $this->assertFalse($policy->doseWitnessRequiredAt(4), 'The approved Not required house choice is an independent override.');
        $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'org';
        $this->assertTrue($policy->doseWitnessRequiredAt(4));
        $policy->organisation[ControlledPolicy::WITNESS_REQUIRED] = 'unknown';
        $this->assertTrue($policy->doseWitnessRequiredAt(4), 'An invalid organisation policy keeps witnessing on.');
    }

    public function test_explicit_order_witness_requirement_always_wins(): void
    {
        $policy = $this->policy();
        $policy->organisation[ControlledPolicy::WITNESS_REQUIRED] = 'off';
        $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'off';
        $medication = new ClientMedication(['controlled_drug' => true, 'witness_required' => true]);
        $this->assertTrue($policy->witnessRequired($medication));
        $medication->witness_required = false;
        $this->assertFalse($policy->witnessRequired($medication));
        $medication->controlled_drug = false;
        $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'on';
        $this->assertFalse($policy->witnessRequired($medication), 'Ordinary orders do not inherit the controlled witness rule.');
        $medication->witness_required = true;
        $this->assertTrue($policy->witnessRequired($medication));
    }

    public function test_model_requires_witness_uses_the_one_policy(): void
    {
        $container = Container::getInstance();
        $previous = $container->bound(ControlledPolicy::class) ? $container->make(ControlledPolicy::class) : null;
        $policy = $this->policy();
        $policy->organisation[ControlledPolicy::WITNESS_REQUIRED] = 'off';
        $container->instance(ControlledPolicy::class, $policy);
        try {
            $medication = new ClientMedication(['controlled_drug' => true, 'witness_required' => false]);
            $this->assertFalse($medication->requiresWitness());
            $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'on';
            $this->assertTrue($medication->requiresWitness());
            $policy->house[ControlledPolicy::HOUSE_WITNESS] = 'off';
            $medication->witness_required = true;
            $this->assertTrue($medication->requiresWitness());
        } finally {
            $container->forgetInstance(ControlledPolicy::class);
            if ($previous !== null) {
                $container->instance(ControlledPolicy::class, $previous);
            }
        }
    }

    public function test_count_cadence_is_not_configured_until_a_valid_value_is_saved(): void
    {
        $policy = $this->policy();
        $medication = new ClientMedication(['controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $this->assertNull($policy->cadence(4));
        $status = $policy->countStatus($medication, $this->at('2026-10-03 14:30'));
        $this->assertSame('not_configured', $status['status']);
        $this->assertNull($status['due_at']);
        $policy->organisation[ControlledPolicy::COUNT_CADENCE] = 'every-eight-hours';
        $this->assertNull($policy->cadence(4));
        $policy->organisation[ControlledPolicy::COUNT_CADENCE] = 'shift';
        $this->assertSame('shift', $policy->cadence(4));
    }

    public function test_count_becomes_due_thirty_minutes_before_an_actual_change_and_overdue_after_an_hour(): void
    {
        $policy = $this->policy();
        $changes = [$this->at('2026-10-03 07:00'), $this->at('2026-10-03 15:00'), $this->at('2026-10-03 15:00'), $this->at('2026-10-03 23:00')];
        $last = $this->at('2026-10-03 07:05');
        $this->assertSame('complete', $policy->countStatusForChanges('shift', $this->at('2026-10-03 14:29:59'), $last, $changes)['status']);
        $due = $policy->countStatusForChanges('shift', $this->at('2026-10-03 14:30'), $last, $changes);
        $this->assertSame('due', $due['status']);
        $this->assertSame('2026-10-03T15:00:00+13:00', $due['change_at']);
        $this->assertSame('2026-10-03T14:30:00+13:00', $due['due_at']);
        $this->assertSame('due', $policy->countStatusForChanges('shift', $this->at('2026-10-03 15:59:59'), $last, $changes)['status']);
        $this->assertSame('overdue', $policy->countStatusForChanges('shift', $this->at('2026-10-03 16:00'), $last, $changes)['status']);
    }

    public function test_early_witnessed_count_covers_the_change_but_an_earlier_or_future_count_does_not(): void
    {
        $policy = $this->policy();
        $changes = [$this->at('2026-10-03 15:00'), $this->at('2026-10-03 23:00')];
        $now = $this->at('2026-10-03 16:15');
        $this->assertSame('complete', $policy->countStatusForChanges('shift', $now, $this->at('2026-10-03 14:30'), $changes)['status']);
        $this->assertSame('overdue', $policy->countStatusForChanges('shift', $now, $this->at('2026-10-03 14:29'), $changes)['status']);
        $this->assertSame('overdue', $policy->countStatusForChanges('shift', $now, $this->at('2026-10-03 17:00'), $changes)['status']);
    }

    public function test_daily_count_uses_the_first_real_morning_start_on_each_nz_date(): void
    {
        $policy = $this->policy();
        $changes = [$this->at('2026-10-03 08:15'), $this->at('2026-10-03 09:30'), $this->at('2026-10-03 16:00'), $this->at('2026-10-04 06:45')];
        $status = $policy->countStatusForChanges('day', $this->at('2026-10-03 16:00'), $this->at('2026-10-03 08:20'), $changes);
        $this->assertSame('complete', $status['status']);
        $this->assertSame('2026-10-03T08:15:00+13:00', $status['change_at']);
        $this->assertSame('2026-10-04T06:45:00+13:00', $status['next_change_at']);
        $tomorrow = $policy->countStatusForChanges('day', $this->at('2026-10-04 06:15'), $this->at('2026-10-03 08:20'), $changes);
        $this->assertSame('due', $tomorrow['status']);
    }

    public function test_order_cutoff_is_applied_after_daily_morning_selection(): void
    {
        $policy = $this->policy();
        $changes = [$this->at('2026-10-03 08:15'), $this->at('2026-10-03 09:30'), $this->at('2026-10-04 06:45')];
        $status = $policy->countStatusForChanges('day', $this->at('2026-10-03 10:00'), null, $changes, 60, 'Pacific/Auckland', $this->at('2026-10-03 09:00'));
        $this->assertSame('upcoming', $status['status']);
        $this->assertSame('2026-10-04T06:45:00+13:00', $status['change_at']);
        $empty = $policy->countStatusForChanges('day', $this->at('2026-10-03 10:00'), null, [$changes[0], $changes[1]], 60, 'Pacific/Auckland', $this->at('2026-10-03 09:00'));
        $this->assertSame('schedule_unavailable', $empty['status']);
        $this->assertNull($empty['due_at']);
    }

    public function test_runtime_count_status_handles_empty_and_fully_filtered_roster_without_a_due_time(): void
    {
        $policy = $this->policy();
        $policy->organisation[ControlledPolicy::COUNT_CADENCE] = 'shift';
        $medication = new ClientMedication(['controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $empty = $policy->countStatus($medication, $this->at('2026-10-03 10:00'));
        $this->assertSame('schedule_unavailable', $empty['status']);
        $this->assertNull($empty['due_at']);
        $policy->roster = [$this->at('2026-10-03 07:00')];
        $medication->setRawAttributes(['controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'created_at' => '2026-10-04']);
        $filtered = $policy->countStatus($medication, $this->at('2026-10-03 10:00'));
        $this->assertSame('schedule_unavailable', $filtered['status']);
        $this->assertNull($filtered['change_at']);
        $this->assertNull($filtered['overdue_at']);
    }

    public function test_count_requirement_covers_active_orders_and_positive_residual_stock(): void
    {
        $policy = $this->policy();
        $stock = new ClientMedicationStock(['on_hand' => '0.00']);
        $medication = new ClientMedication([
            'controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        $medication->setRelation('stock', $stock);
        $this->assertTrue($policy->countRequired($medication), 'An active controlled order still needs counts at zero stock.');

        $medication->setRawAttributes([...$medication->getAttributes(), 'state' => 'ceased', 'active' => false, 'deleted_at' => '2026-10-03 08:00:00']);
        $stock->on_hand = '1.00';
        $this->assertTrue($policy->countRequired($medication), 'Historical positive physical stock remains countable.');
        $stock->on_hand = '0.00';
        $this->assertFalse($policy->countRequired($medication));
        $status = $policy->countStatus($medication, $this->at('2026-10-03 10:00'));
        $this->assertSame('not_applicable', $status['status']);
        $this->assertNull($status['due_at']);
        $this->assertNull($status['overdue_at']);

        $stock->on_hand = '1.00';
        $medication->controlled_drug = false;
        $this->assertFalse($policy->countRequired($medication), 'Ordinary physical stock never acquires the controlled count policy.');
    }

    public function test_missing_roster_or_unapproved_weekly_anchor_does_not_fabricate_due_times(): void
    {
        $policy = $this->policy();
        $now = $this->at('2026-10-03 16:00');
        foreach (['shift', 'day', 'week'] as $cadence) {
            $result = $policy->countStatusForChanges($cadence, $now, null, []);
            $this->assertSame('schedule_unavailable', $result['status']);
            $this->assertNull($result['due_at']);
            $this->assertNull($result['overdue_at']);
        }
        $this->assertSame('schedule_unavailable', $policy->countStatusForChanges('week', $now, null, [$this->at('2026-10-03 07:00')])['status']);
        $this->assertSame('schedule_unavailable', $policy->countStatusForChanges('day', $now, null, [$this->at('2026-10-03 15:00')])['status']);
    }

    public function test_elapsed_count_windows_work_across_nz_daylight_saving(): void
    {
        $policy = $this->policy();
        $change = $this->at('2026-09-27 03:15');
        $due = $policy->countStatusForChanges('shift', $this->at('2026-09-27 01:45'), null, [$change]);
        $this->assertSame('due', $due['status']);
        $this->assertSame('2026-09-27T01:45:00+12:00', $due['due_at']);
        $this->assertSame('2026-09-27T04:15:00+13:00', $due['overdue_at']);
        $this->assertSame('overdue', $policy->countStatusForChanges('shift', $this->at('2026-09-27 04:15'), null, [$change])['status']);
        $fallChange = CarbonImmutable::parse('2026-04-05T02:30:00+13:00');
        $fallStatus = $policy->countStatusForChanges('shift', CarbonImmutable::parse('2026-04-05T02:30:00+12:00'), null, [$fallChange]);
        $this->assertSame('overdue', $fallStatus['status']);
        $this->assertSame('2026-04-05T02:30:00+12:00', $fallStatus['overdue_at']);
    }

    public function test_on_site_destruction_requires_an_explicit_policy_and_invalid_numbers_keep_one_hour(): void
    {
        $policy = $this->policy();
        $this->assertFalse($policy->onsiteAllowed(4));
        $policy->organisation[ControlledPolicy::ONSITE_DESTRUCTION] = 'on';
        $this->assertTrue($policy->onsiteAllowed(4));
        $policy->organisation[ControlledPolicy::ONSITE_DESTRUCTION] = true;
        $this->assertFalse($policy->onsiteAllowed(4));
        foreach ([null, '0', '-10', '60.5', '1441', 'off'] as $invalid) {
            $policy->organisation[ControlledPolicy::COUNT_OVERDUE_MINUTES] = $invalid;
            $this->assertSame(60, $policy->overdueMinutes());
        }
        $policy->organisation[ControlledPolicy::COUNT_OVERDUE_MINUTES] = 90;
        $this->assertSame(90, $policy->overdueMinutes());
    }

    public function test_fragment_definitions_require_loosening_confirmation_for_witness_count_and_destruction_changes(): void
    {
        $groups = [];
        foreach (ControlledSettingsFragment::groups() as $group) {
            $groups[$group->key] = $group;
        }
        $this->assertTrue($groups['controlled_witness']->definition('organisation')->loosens('on', 'off'));
        $this->assertTrue($groups['controlled_witness']->definition('house')->loosens('org', 'off'));
        $this->assertTrue($groups['controlled_counts']->definition('cadence')->loosens('shift', 'day'));
        $this->assertTrue($groups['controlled_counts']->definition('cadence')->loosens('day', 'week'));
        $this->assertTrue($groups['controlled_counts']->definition('overdue_minutes')->loosens('60', '90'));
        $this->assertTrue($groups['controlled_destruction']->definition('onsite')->loosens('off', 'on'));
        $this->assertSame('', $groups['controlled_counts']->definition('cadence')->default);
        $this->assertSame('', $groups['controlled_counts']->definition('cadence')->normalise(null));
        $this->assertFalse($groups['controlled_counts']->definition('cadence')->accepts(''));
        $this->assertTrue($groups['controlled_counts']->definition('cadence')->accepts('shift'));
        $this->assertCount(3, $groups['controlled_counts']->definition('cadence')->options);
        $this->assertSame('week', $groups['controlled_counts']->definition('cadence')->normalise('week'));
        $this->assertSame('Once a week, on the chosen day and time', $groups['controlled_counts']->definition('cadence')->format('week'));
        $this->assertStringContainsString('Finish any due weekly counts before changing weekly timing.', $groups['controlled_counts']->toClient()['effect']);
        $this->assertNotNull($groups['controlled_counts']->definition('cadence')->whenNotConfigured);
    }

    public function test_weekly_schedule_uses_only_the_reviewed_weekday_and_local_time(): void
    {
        $policy = $this->policy();
        $anchor = ['day' => 5, 'time' => '10:00'];
        $cutoff = $this->at('2026-10-01 12:00');
        $next = $policy->countStatusForWeeklyAnchor($this->at('2026-10-02 09:29:59'), null, $anchor, 90, 'Pacific/Auckland', $cutoff);
        $this->assertSame('upcoming', $next['status']);
        $this->assertSame('2026-10-02T10:00:00+13:00', $next['change_at']);
        $due = $policy->countStatusForWeeklyAnchor($this->at('2026-10-02 09:30'), null, $anchor, 90, 'Pacific/Auckland', $cutoff);
        $this->assertSame('due', $due['status']);
        $this->assertSame('2026-10-02T09:30:00+13:00', $due['due_at']);
        $this->assertSame('2026-10-02T11:30:00+13:00', $due['overdue_at']);
        $this->assertSame('2026-10-09T10:00:00+13:00', $due['next_change_at']);
        $this->assertSame('overdue', $policy->countStatusForWeeklyAnchor($this->at('2026-10-02 11:30'), null, $anchor, 90, 'Pacific/Auckland', $cutoff)['status']);
    }

    public function test_weekly_counts_need_current_real_witnessed_evidence_inside_the_due_window(): void
    {
        $policy = $this->policy();
        $anchor = ['day' => 5, 'time' => '10:00'];
        $now = $this->at('2026-10-02 11:00');
        $this->assertSame('complete', $policy->countStatusForWeeklyAnchor($now, $this->at('2026-10-02 09:30'), $anchor)['status']);
        foreach (['2026-10-02 09:29:59', '2026-10-02 12:00', '2026-09-25 10:00'] as $invalid) {
            $this->assertSame('overdue', $policy->countStatusForWeeklyAnchor($now, $this->at($invalid), $anchor)['status']);
        }
    }

    public function test_weekly_runtime_needs_an_anchor_and_starts_at_the_next_boundary_after_publication_or_order_creation(): void
    {
        $policy = $this->policy();
        $policy->organisation[ControlledPolicy::COUNT_CADENCE] = 'week';
        $medicine = new ClientMedication(['controlled_drug' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified']);
        $this->assertSame('schedule_unavailable', $policy->countStatus($medicine, $this->at('2026-10-03 12:00'))['status']);
        $policy->organisation[ControlledPolicy::COUNT_WEEKLY_ANCHOR] = '{"day":5,"time":"10:00"}';
        $policy->effectiveAt = $this->at('2026-10-03 11:00');
        $status = $policy->countStatus($medicine, $this->at('2026-10-03 12:00'));
        $this->assertSame('upcoming', $status['status']);
        $this->assertSame('2026-10-09T10:00:00+13:00', $status['change_at']);
        $policy->effectiveAt = $this->at('2026-10-01 11:00');
        $this->assertSame('overdue', $policy->countStatus($medicine, $this->at('2026-10-03 12:00'))['status']);
        $medicine->setDateFormat('Y-m-d H:i:s');
        $medicine->setRawAttributes([...$medicine->getAttributes(), 'created_at' => '2026-10-03 00:00:00']);
        $this->assertSame('upcoming', $policy->countStatus($medicine, $this->at('2026-10-03 12:00'))['status']);
    }

    public function test_weekly_local_calendar_preserves_clock_time_across_both_nz_dst_changes(): void
    {
        $policy = $this->policy();
        $anchor = ['day' => 7, 'time' => '03:30'];
        foreach ([
            ['2026-09-20 04:30', '2026-09-20T03:30:00+12:00', '2026-09-27T03:30:00+13:00', 167],
            ['2026-03-29 04:30', '2026-03-29T03:30:00+13:00', '2026-04-05T03:30:00+12:00', 169],
        ] as [$now, $current, $next, $hours]) {
            $status = $policy->countStatusForWeeklyAnchor($this->at($now), null, $anchor);
            $this->assertSame($current, $status['change_at']);
            $this->assertSame($next, $status['next_change_at']);
            $this->assertEquals($hours * 3600, CarbonImmutable::parse($next)->timestamp - CarbonImmutable::parse($current)->timestamp);
        }
        $spring = $policy->countStatusForWeeklyAnchor($this->at('2026-09-27 04:30'), null, $anchor);
        $this->assertSame('2026-09-27T03:00:00+13:00', $spring['due_at']);
        $this->assertSame('2026-09-27T04:30:00+13:00', $spring['overdue_at']);
        $this->assertSame('schedule_unavailable', $policy->countStatusForWeeklyAnchor($this->at('2026-09-27 04:30'), null, ['day' => 7, 'time' => '02:30'])['status']);
    }

    public function test_monday_midnight_count_keeps_the_following_week_visible_before_calendar_week_rollover(): void
    {
        $policy = $this->policy();
        $anchor = ['day' => 1, 'time' => '00:00'];
        $clock = $this->at('2026-10-04 23:45');
        $complete = $policy->countStatusForWeeklyAnchor($clock, $this->at('2026-10-04 23:40'), $anchor);
        $this->assertSame('complete', $complete['status']);
        $this->assertSame('2026-10-05T00:00:00+13:00', $complete['change_at']);
        $this->assertSame('2026-10-04T23:30:00+13:00', $complete['due_at']);
        $this->assertSame('2026-10-12T00:00:00+13:00', $complete['next_change_at']);
        $pending = $policy->countStatusForWeeklyAnchor($clock, null, $anchor);
        $this->assertSame('due', $pending['status']);
        $this->assertSame('2026-10-12T00:00:00+13:00', $pending['next_change_at']);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Pacific/Auckland');
    }

    private function policy(): ControlledPolicy
    {
        return new class extends ControlledPolicy
        {
            public array $organisation = [];

            public array $house = [];

            public array $roster = [];

            public ?CarbonImmutable $effectiveAt = null;

            protected function weeklyPolicyEffectiveAt(): ?CarbonImmutable
            {
                return $this->effectiveAt;
            }

            protected function organisationValue(string $key): mixed
            {
                return $this->organisation[$key] ?? null;
            }

            protected function houseValue(int $siteId, string $key): mixed
            {
                return $this->house[$key] ?? null;
            }

            protected function siteId(ClientMedication $medication): ?int
            {
                return 4;
            }

            protected function rosterChanges(int $siteId, CarbonImmutable $from, CarbonImmutable $until): array
            {
                return $this->roster;
            }

            protected function timezone(): string
            {
                return 'Pacific/Auckland';
            }
        };
    }
}
