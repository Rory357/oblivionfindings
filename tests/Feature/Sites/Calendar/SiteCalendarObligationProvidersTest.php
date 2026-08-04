<?php

use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteEmergencyPlan;
use App\Models\SiteVendor;
use App\Services\Sites\Calendar\SiteCalendarAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/** Run the aggregator over a today-anchored window for a single site. The window
 *  reaches far enough ahead to include a never-rotated credential's first due
 *  date (created_at + 90-day cadence). */
function aggregate(Site $site, array $sources = []): Collection
{
    return collect(app(SiteCalendarAggregator::class)->itemsForRange(
        [$site->id],
        now()->subMonth(),
        now()->addMonths(4),
        $sources ? ['sources' => $sources] : [],
    ));
}

test('asset maintenance obligations surface vehicle and asset due dates', function () {
    $site = Site::factory()->create(['type' => 'house']);

    $asset = Asset::factory()->forSite($site)->vehicle()->create([
        'name' => 'Site Van',
        'wof_expires_at' => now()->addDays(10)->toDateString(),
        'registration_expires_at' => now()->subDays(5)->toDateString(),
    ]);

    $items = aggregate($site, ['asset']);

    $wof = $items->first(fn ($i) => str_contains($i->title, 'WOF expires'));
    expect($wof)->not->toBeNull();
    expect($wof->group)->toBe('auto');
    expect($wof->editable)->toBeFalse();
    expect($wof->status)->toBe('scheduled');
    expect($wof->link)->toBe("/assets/{$asset->id}");

    // A past registration date reads as overdue.
    $rego = $items->first(fn ($i) => str_contains($i->title, 'Registration expires'));
    expect($rego)->not->toBeNull();
    expect($rego->status)->toBe('overdue');
});

test('asset obligations skip retired kit', function () {
    $site = Site::factory()->create(['type' => 'house']);

    Asset::factory()->forSite($site)->retired()->create([
        'wof_expires_at' => now()->addDays(10)->toDateString(),
    ]);

    expect(aggregate($site, ['asset']))->toBeEmpty();
});

test('emergency plan review obligations surface and can derive the next review date', function () {
    $site = Site::factory()->create(['type' => 'house']);

    // Explicit next review.
    SiteEmergencyPlan::create([
        'site_id' => $site->id,
        'plan_type' => 'evacuation',
        'title' => 'Evacuation plan',
        'next_review_at' => now()->addDays(14)->toDateString(),
        'status' => 'active',
    ]);

    // No explicit next review — derived from last_reviewed_at + interval (~1 month out).
    SiteEmergencyPlan::create([
        'site_id' => $site->id,
        'plan_type' => 'fire',
        'title' => 'Fire plan',
        'last_reviewed_at' => now()->subMonths(11)->toDateString(),
        'review_interval_months' => 12,
        'next_review_at' => null,
        'status' => 'active',
    ]);

    $items = aggregate($site, ['emergency']);

    expect($items)->toHaveCount(2);
    $evac = $items->first(fn ($i) => str_contains($i->title, 'Evacuation plan'));
    expect($evac)->not->toBeNull();
    expect($evac->title)->toContain('review due');
    expect($evac->link)->toBe("/sites/{$site->id}?tab=compliance");
    expect($items->first(fn ($i) => str_contains($i->title, 'Fire plan')))->not->toBeNull();
});

test('credential reminders now fire for never-rotated credentials (created_at fallback)', function () {
    $site = Site::factory()->create(['type' => 'house']);

    // No last_rotated_at → due falls at created_at (≈ now) + 90 days.
    SiteCredential::create([
        'site_id' => $site->id,
        'label' => 'Wifi Password',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('secret'),
    ]);

    $items = aggregate($site, ['credential']);

    $cred = $items->firstWhere('source', 'credential');
    expect($cred)->not->toBeNull();
    expect($cred->title)->toContain('first rotation due');
});

test('vendor reminders cover contract renewal and the next scheduled visit', function () {
    $site = Site::factory()->create(['type' => 'house']);

    SiteVendor::create([
        'site_id' => $site->id,
        'service_type' => 'plumber',
        'company_name' => 'Pipes Ltd',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
        'contract_renewal_date' => now()->subDays(3)->toDateString(),
        'next_visit_date' => now()->addDays(7)->toDateString(),
    ]);

    $items = aggregate($site, ['vendor']);

    $contract = $items->first(fn ($i) => str_contains($i->title, 'contract renewal'));
    expect($contract)->not->toBeNull();
    expect($contract->status)->toBe('overdue');

    // A forward booking is never "overdue".
    $visit = $items->first(fn ($i) => str_contains($i->title, 'scheduled visit'));
    expect($visit)->not->toBeNull();
    expect($visit->status)->toBe('scheduled');
});
