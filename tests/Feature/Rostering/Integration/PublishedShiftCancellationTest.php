<?php

use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Models\Client;
use App\Models\RosterPeriod;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;

it('marks a published period changed when a published shift is cancelled', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $actor = User::factory()->create(['organization_id' => 1]);
    $worker = User::factory()->create(['organization_id' => 1]);
    $period = RosterPeriod::factory()->published()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'week_start' => '2026-05-04',
        'week_end' => '2026-05-11',
    ]);

    $shift = Shift::factory()->scheduled()->published($period)->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $worker->id,
        'roster_period_id' => $period->id,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
    ]);

    app(ShiftLifecycleService::class)->cancel($shift, $actor, 'Worker unavailable');

    expect($shift->fresh()->status)->toBe('cancelled');
    expect($shift->fresh()->publish_dirty_at)->not->toBeNull();
    expect($period->fresh()->status)->toBe(RosterPeriod::STATUS_CHANGED_AFTER_PUBLISH);
});
