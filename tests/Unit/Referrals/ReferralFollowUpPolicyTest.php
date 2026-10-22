<?php

namespace Tests\Unit\Referrals;

use App\Domain\Referrals\Services\ReferralFollowUpPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReferralFollowUpPolicyTest extends TestCase
{
    public function test_working_day_escalation_excludes_weekends_and_holidays_across_nz_daylight_saving(): void
    {
        foreach (['Pacific/Auckland', 'Pacific/Chatham'] as $timezone) {
            $configuration = $this->configuration($timezone);
            $due = CarbonImmutable::parse('2026-09-25 16:00', $timezone);
            $before = CarbonImmutable::parse('2026-09-29 15:59', $timezone);
            $at = CarbonImmutable::parse('2026-09-29 16:00', $timezone);
            $this->assertFalse(ReferralFollowUpPolicy::thresholdReached($configuration, $due, $before));
            $this->assertTrue(ReferralFollowUpPolicy::thresholdReached($configuration, $due, $at));
            $this->assertFalse(ReferralFollowUpPolicy::windowOpen($configuration, CarbonImmutable::parse('2026-09-28 12:00', $timezone)));
            $this->assertFalse(ReferralFollowUpPolicy::windowOpen($configuration, CarbonImmutable::parse('2026-09-27 12:00', $timezone)));
            $this->assertTrue(ReferralFollowUpPolicy::windowOpen($configuration, CarbonImmutable::parse('2026-09-29 09:00', $timezone)));
            $this->assertFalse(ReferralFollowUpPolicy::windowOpen($configuration, CarbonImmutable::parse('2026-09-29 17:00', $timezone)));
        }
    }

    public function test_legacy_versions_retain_the_shared_three_calendar_day_threshold(): void
    {
        $due = CarbonImmutable::parse('2026-10-15 20:00', 'UTC');
        $this->assertTrue(ReferralFollowUpPolicy::windowOpen([], $due));
        $this->assertFalse(ReferralFollowUpPolicy::thresholdReached([], $due, $due->addDays(3)->subSecond()));
        $this->assertTrue(ReferralFollowUpPolicy::thresholdReached([], $due, $due->addDays(3)));
    }

    public function test_unknown_calendar_cannot_authorize_working_time_delivery(): void
    {
        $configuration = $this->configuration('Pacific/Auckland');
        unset($configuration['business_calendar']['opening_time']);
        $at = CarbonImmutable::parse('2026-10-19 12:00', 'Pacific/Auckland');
        $this->assertFalse(ReferralFollowUpPolicy::windowOpen($configuration, $at));
        $this->assertFalse(ReferralFollowUpPolicy::thresholdReached($configuration, $at->subWeek(), $at));
        $configuration['follow_up']['office_hours_only'] = false;
        $this->assertFalse(ReferralFollowUpPolicy::thresholdReached($configuration, $at->subWeek(), $at));
    }

    private function configuration(string $timezone): array
    {
        return ['follow_up' => ['manager_after_days' => 1, 'day_basis' => 'business', 'office_hours_only' => true],
            'business_calendar' => ['timezone' => $timezone, 'weekdays' => [1, 2, 3, 4, 5], 'holidays' => ['2026-09-28'], 'opening_time' => '09:00', 'closing_time' => '17:00']];
    }
}
