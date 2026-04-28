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

    public function test_cancelled_shifts_do_not_count_toward_fatigue_limits(): void
    {
        config([
            'hr.fatigue.max_hours_per_day' => 12,
            'hr.fatigue.max_hours_per_week' => 50,
            'hr.fatigue.warning_threshold_weekly' => 40,
            'hr.fatigue.min_rest_between_shifts_hours' => 10,
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
            'starts_at' => Carbon::parse('2026-04-13 08:00:00'),
            'ends_at' => Carbon::parse('2026-04-13 18:00:00'),
            'status' => 'cancelled',
            'created_by' => $staff->id,
        ]);

        $candidate = Shift::factory()->make([
            'organization_id' => 1,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'service_context_id' => $serviceContext->id,
            'user_id' => $staff->id,
            'starts_at' => Carbon::parse('2026-04-13 10:00:00'),
            'ends_at' => Carbon::parse('2026-04-13 14:00:00'),
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
