<?php

namespace Tests\Feature\Contracts;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\Rules\FatigueRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FatigueExcludesCancelledShiftsTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_worker_local_daily_and_weekly_thresholds_pass(): void
    {
        config([
            'app.worker_timezone' => 'Pacific/Auckland',
            'hr.fatigue.max_hours_per_day' => 12,
            'hr.fatigue.max_hours_per_week' => 12,
            'hr.fatigue.warning_threshold_weekly' => 12,
            'hr.fatigue.min_rest_between_shifts_hours' => 0,
            'hr.fatigue.max_consecutive_days' => 7,
        ]);

        $staff = User::factory()->create(['organization_id' => 1]);
        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);

        Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-06-01 08:00:00', 'Pacific/Auckland')->utc(),
            'actual_starts_at' => Carbon::parse('2026-06-01 08:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-06-01 16:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'completed',
            'created_by' => $staff->id,
        ]);

        $candidate = Shift::factory()->make([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-06-01 16:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-06-01 20:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        $results = collect(app(FatigueRule::class)->evaluateAll($candidate, $staff))
            ->keyBy('rule');

        $this->assertTrue($results['fatigue_daily']['passed']);
        $this->assertTrue($results['fatigue_weekly']['passed']);

        $candidate->ends_at = Carbon::parse('2026-06-01 20:01:00', 'Pacific/Auckland')->utc();
        $overThreshold = collect(app(FatigueRule::class)->evaluateAll($candidate, $staff))
            ->keyBy('rule');

        $this->assertFalse($overThreshold['fatigue_daily']['passed']);
        $this->assertFalse($overThreshold['fatigue_weekly']['passed']);
    }

    public function test_cancelled_shifts_do_not_count_toward_fatigue_limits(): void
    {
        config([
            'app.worker_timezone' => 'Pacific/Auckland',
            'hr.fatigue.max_hours_per_day' => 12,
            'hr.fatigue.max_hours_per_week' => 12,
            'hr.fatigue.warning_threshold_weekly' => 12,
            'hr.fatigue.min_rest_between_shifts_hours' => 10,
            'hr.fatigue.max_consecutive_days' => 2,
        ]);

        $staff = User::factory()->create(['organization_id' => 1]);
        $site = Site::factory()->create();
        $serviceContext = ServiceContext::factory()->create();
        $client = Client::factory()->create([
            'organization_id' => 1,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
        ]);

        Shift::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            // If this cancelled row leaked into fatigue calculations it would:
            // - push Monday and its ISO week to 13 hours,
            // - leave only one hour of rest before the candidate, and
            // - make the candidate the second consecutive local work day.
            'starts_at' => Carbon::parse('2026-04-12 23:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-04-13 09:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'cancelled',
            'created_by' => $staff->id,
        ]);

        $candidate = Shift::factory()->make([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-13 10:00:00', 'Pacific/Auckland')->utc(),
            'ends_at' => Carbon::parse('2026-04-13 14:00:00', 'Pacific/Auckland')->utc(),
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        $results = collect(app(FatigueRule::class)->evaluateAll($candidate, $staff))
            ->keyBy('rule');

        $this->assertTrue($results['fatigue_daily']['passed']);
        $this->assertTrue($results['fatigue_weekly']['passed']);
        $this->assertTrue($results['fatigue_rest']['passed']);
        $this->assertTrue($results['fatigue_consecutive']['passed']);
    }
}
