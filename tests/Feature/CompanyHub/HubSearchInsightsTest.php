<?php

use App\Domain\CompanyHub\Services\HubSearchInsights;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-13 11:00:00', 'Pacific/Auckland')->utc());
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->reader);
    $this->insights = app(HubSearchInsights::class);
});

test('search insights persist coarse counters without phrases or staff identifiers and deduplicate same-day searches', function () {
    $phrase = 'Specific private enquiry about Person-X';
    $this->insights->record($this->reader, $phrase, false);
    // A fresh request can have a new array cache, or a durable cache can be
    // evicted. Deduplication must not depend on either cache lifetime.
    Cache::flush();
    $this->insights->record($this->reader, '  specific PRIVATE enquiry about Person-X  ', false);
    $this->insights->record($this->reader, 'annual leave balance', true);
    $rows = DB::table('hub_search_daily')->get();
    expect($rows)->toHaveCount(2)->and((int) $rows->sum('searches'))->toBe(2)->and((int) $rows->sum('unanswered'))->toBe(1)
        ->and($rows->pluck('topic')->sort()->values()->all())->toBe(['leave', 'other'])
        ->and($rows->toJson())->not->toContain('Person-X', 'Specific private enquiry')
        ->and(Schema::getColumnListing('hub_search_daily'))->not->toContain('query', 'phrase', 'user_id', 'name', 'email', 'ip_address');
    expect($rows->first()->day)->toBe('2026-10-13');
    expect(DB::table('hub_search_keys')->count())->toBe(2)
        ->and(Schema::getColumnListing('hub_search_keys'))->toBe(['fingerprint', 'day'])
        ->and(DB::table('hub_search_keys')->get()->toJson())->not->toContain($phrase, 'Person-X');
    $this->travel(1)->days();
    $this->insights->record($this->reader, $phrase, false);
    expect(DB::table('hub_search_daily')->count())->toBe(3)->and((int) DB::table('hub_search_daily')->sum('searches'))->toBe(3);
    expect(DB::table('hub_search_keys')->count())->toBe(1)
        ->and(DB::table('hub_search_keys')->sole()->day)->toBe('2026-10-14');
});

test('only submitted whole-hub searches count and an accessible result is not reported as unanswered', function () {
    $this->actingAs($this->reader)->get('/company-hub?search=leave')->assertOk();
    $this->get('/company-hub?search=UnfindableSearchExample')->assertOk();
    Cache::flush();
    $this->get('/company-hub?search=UnfindableSearchExample')->assertOk();
    $this->get('/company-hub?view=news&search=ScopedNewsExample')->assertOk();
    $this->getJson('/company-hub/search?query=CommandSuggestionExample')->assertOk();
    expect((int) DB::table('hub_search_daily')->sum('searches'))->toBe(2)
        ->and((int) DB::table('hub_search_daily')->sum('unanswered'))->toBe(1);
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->reader->fresh())->get('/company-hub?search=AnotherMissingExample')->assertForbidden();
    expect((int) DB::table('hub_search_daily')->sum('searches'))->toBe(2);
});

test('only hub managers receive totals for their approved sites and selected NZ calendar period', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    DB::table('hub_search_daily')->insert([
        ['day' => '2026-10-13', 'site_id' => $this->site->id, 'topic' => 'policies', 'searches' => 4, 'unanswered' => 3],
        ['day' => '2026-10-07', 'site_id' => $this->site->id, 'topic' => 'leave', 'searches' => 2, 'unanswered' => 1],
        ['day' => '2026-10-05', 'site_id' => $this->site->id, 'topic' => 'it', 'searches' => 5, 'unanswered' => 5],
        ['day' => '2026-10-13', 'site_id' => $outside->id, 'topic' => 'other', 'searches' => 99, 'unanswered' => 99],
    ]);
    expect($this->insights->summary($this->reader, 7))->toBeNull();
    $permission = Permission::where('key', 'company_hub.edit_all')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $manager = $this->reader->fresh();
    $summary = $this->insights->summary($manager, 7);
    expect($summary)->toMatchArray(['since' => '2026-10-07', 'until' => '2026-10-13', 'days' => 7, 'total' => 6, 'unanswered' => 4])
        ->and(array_column($summary['topics'], 'key'))->toBe(['policies', 'leave']);
    expect($this->insights->summary($manager, 28)['total'])->toBe(11);
    $this->actingAs($manager)->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('searches.total', 6);
    $manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
    expect($this->insights->summary($manager->fresh(), 7))->toBeNull();
});

test('search counters expire after ninety calendar days and empty topics never invent activity', function () {
    DB::table('hub_search_daily')->insert(['day' => now('Pacific/Auckland')->subDays(90)->toDateString(), 'site_id' => $this->site->id, 'topic' => 'other', 'searches' => 9, 'unanswered' => 9]);
    $this->insights->record($this->reader, 'Unlisted question', false);
    expect(DB::table('hub_search_daily')->count())->toBe(1)->and(DB::table('hub_search_daily')->sole()->day)->toBe('2026-10-13');
    $permission = Permission::where('key', 'company_hub.edit_all')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    $this->travel(91)->days();
    $summary = $this->insights->summary($this->reader->fresh(), 90);
    expect($summary['total'])->toBe(0)->and($summary['unanswered'])->toBe(0)->and($summary['topics'])->toBe([]);
});

test('failed counter writes roll back the daily fingerprint so a later request can record the search', function () {
    $failNextCounter = true;
    DB::listen(function ($query) use (&$failNextCounter): void {
        if ($failNextCounter && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'hub_search_daily')) {
            $failNextCounter = false;
            throw new RuntimeException('Isolated search counter write failure');
        }
    });

    $this->insights->record($this->reader, 'Unlisted atomic example', false);
    expect(DB::table('hub_search_keys')->count())->toBe(0)
        ->and(DB::table('hub_search_daily')->count())->toBe(0);

    $this->insights->record($this->reader, 'Unlisted atomic example', false);
    expect(DB::table('hub_search_keys')->count())->toBe(1)
        ->and((int) DB::table('hub_search_daily')->sole()->searches)->toBe(1);
});
