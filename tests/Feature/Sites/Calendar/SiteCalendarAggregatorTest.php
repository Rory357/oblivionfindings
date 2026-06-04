<?php

use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteMealPlanEntry;
use App\Models\User;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function makeMeal(Site $site, string $date, string $slot, string $name): SiteMealPlanEntry
{
    return SiteMealPlanEntry::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'plan_date' => $date,
        'meal_slot' => $slot,
        'source_type' => 'ad_hoc',
        'ad_hoc_name' => $name,
        'servings' => 4,
    ]);
}

test('aggregator unions manual events with auto-derived obligations', function () {
    $site = Site::factory()->create(['type' => 'house', 'name' => 'Maple House']);
    $owner = User::factory()->create();

    SiteCalendarEvent::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'event_type' => 'general',
        'title' => 'House meeting',
        'start_at' => Carbon::parse('2026-05-20 16:00'),
        'end_at' => Carbon::parse('2026-05-20 17:00'),
        'created_by_user_id' => $owner->id,
        'owner_user_id' => $owner->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);

    makeMeal($site, '2026-05-20', 'lunch', 'Roast chicken');

    $items = collect(app(SiteCalendarAggregator::class)->itemsForRange(
        [$site->id],
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
    ));

    $manual = $items->firstWhere('source', 'event');
    expect($manual)->not->toBeNull();
    expect($manual->group)->toBe('manual');
    expect($manual->editable)->toBeTrue();
    expect($manual->title)->toBe('House meeting');

    $meal = $items->firstWhere('source', 'meal');
    expect($meal)->not->toBeNull();
    expect($meal->group)->toBe('auto');
    expect($meal->editable)->toBeFalse();
    expect($meal->title)->toContain('Roast chicken');
    expect($meal->link)->toContain("/sites/{$site->id}?tab=meal-planner");
    expect($meal->ref)->toBe('MEAL-'.SiteMealPlanEntry::first()->id);
});

test('meal obligations surface at their business-timezone slot time (8am, not 8pm)', function () {
    $tz = config('app.worker_timezone', 'Pacific/Auckland');
    $site = Site::factory()->create(['type' => 'house']);

    makeMeal($site, '2026-06-05', 'breakfast', 'Porridge & fruit');

    $items = collect(app(SiteCalendarAggregator::class)->itemsForRange(
        [$site->id],
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
    ));

    $meal = $items->firstWhere('source', 'meal');
    expect($meal)->not->toBeNull();
    // The 08:00 breakfast slot must read as 8am in NZ — the +12h bug showed 8pm.
    expect(Carbon::parse($meal->start)->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-06-05 08:00');
    expect(Carbon::parse($meal->end)->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-06-05 09:00');
    // Stored/emitted as a true UTC instant (08:00 NZST → 20:00 UTC the day before).
    expect(Carbon::parse($meal->start)->utc()->format('Y-m-d H:i'))->toBe('2026-06-04 20:00');
});

test('aggregator excludes rows from sites outside the requested set', function () {
    $wanted = Site::factory()->create(['type' => 'house']);
    $other = Site::factory()->create(['type' => 'house']);

    makeMeal($other, '2026-05-10', 'dinner', 'Pasta bake');

    $items = app(SiteCalendarAggregator::class)->itemsForRange(
        [$wanted->id],
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
    );

    expect($items)->toBeEmpty();
});

test('aggregator can filter to a single source layer', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $owner = User::factory()->create();

    SiteCalendarEvent::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'event_type' => 'general',
        'title' => 'House meeting',
        'start_at' => Carbon::parse('2026-05-20 16:00'),
        'created_by_user_id' => $owner->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);
    makeMeal($site, '2026-05-21', 'lunch', 'Soup');

    $mealsOnly = collect(app(SiteCalendarAggregator::class)->itemsForRange(
        [$site->id],
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
        ['sources' => ['meal']],
    ));

    expect($mealsOnly->pluck('source')->unique()->all())->toBe(['meal']);
});
